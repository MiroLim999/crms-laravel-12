r"""
train_trocr.py
Fine-tunes TrOCR (microsoft/trocr-base-handwritten) on a handwritten names dataset.

Dataset structure expected (see datasets.py):
  ml/datasets/<name>/
    manifest.csv                     (filename,label,split,source)
    train/syn_000001.png ...
    val/syn_000002.png ...

Two ways to run it:

  CLI      python ml\train_trocr.py --dataset default --epochs 5
  In-process  from train_trocr import run_training
              run_training(dataset="default", epochs=5, progress=cb)

The CONFIG block below holds *defaults* only. `run_training()` takes every value
as an argument so the FastAPI job runner can drive it, report progress, and cancel
it. Nothing in this module calls sys.exit() or input() - it has to stay importable.
"""

import argparse
import os
import warnings
import logging

# --- Suppress warnings ---
os.environ.setdefault("HF_HUB_DISABLE_SYMLINKS_WARNING", "1")
os.environ.setdefault("HF_HUB_DISABLE_IMPLICIT_TOKEN", "1")
os.environ.setdefault("TRANSFORMERS_NO_ADVISORY_WARNINGS", "1")
os.environ.setdefault("TRANSFORMERS_VERBOSITY", "error")
warnings.filterwarnings("ignore")
logging.disable(logging.WARNING)

import shutil
import time

import pandas as pd
import torch
from torch.utils.data import Dataset, DataLoader
from PIL import Image
from tqdm import tqdm
from transformers import TrOCRProcessor, VisionEncoderDecoderModel
from torch.optim import AdamW
from torch.cuda.amp import GradScaler, autocast

import dataset_registry as ds

# ============================================================
# CONFIG - defaults. Every one is overridable via run_training().
# ============================================================
MODEL_NAME = "microsoft/trocr-base-handwritten"

# Paths anchored to the ml/ directory rather than the working directory, so the
# script runs the same from anywhere.
ML_ROOT = os.path.dirname(os.path.abspath(__file__))
MODELS_DIR = os.path.join(ML_ROOT, "models")

DEFAULT_DATASET = ds.DEFAULT_DATASET

# Training hyperparameters
EPOCHS = 5
BATCH_SIZE = 8                # Adjust based on your GPU VRAM (RTX 4050 = 6GB)
LEARNING_RATE = 5e-5
MAX_LABEL_LENGTH = 32         # Max characters in a label
NUM_WORKERS = 2               # DataLoader workers

# Dataset subset (None = use ALL data)
TRAIN_SUBSET = None
VAL_SUBSET = None

# Output. Any folder dropped in ml/models/ is auto-discovered by the OCR service,
# so a finished run becomes selectable in the app without further steps.
DEFAULT_OUTPUT_NAME = "trocr-finetuned"

# A checkpoint is roughly 1.3 GB. Refuse to start without room for two, so a
# failed save cannot leave the disk full.
MIN_FREE_BYTES = 3 * 1024 ** 3
# ============================================================


class TrainingError(Exception):
    """Configuration or dataset problem. Raised instead of exiting."""


def _noop(*_args, **_kwargs):
    pass


class HandwrittenDataset(Dataset):
    """Handwritten text images with labels, for one split of one dataset."""

    def __init__(self, csv_path, img_dir, processor, max_label_length, split,
                 subset=None, log=_noop):
        self.processor = processor
        self.max_label_length = max_label_length
        self.img_dir = img_dir

        # keep_default_na=False prevents the literal label "None" from being
        # parsed as a missing value (NaN).
        df = pd.read_csv(csv_path, keep_default_na=False)

        for column in ("filename", "label", "split"):
            if column not in df.columns:
                raise TrainingError(f"manifest.csv is missing the '{column}' column.")

        df = df[df["split"].astype(str).str.strip().str.lower() == split]

        # Clean: remove rows with empty or "UNREADABLE" labels
        df = df[df["label"].astype(str).str.strip() != ""]
        df = df[df["label"].astype(str).str.strip().str.upper() != ds.UNREADABLE]
        df = df.reset_index(drop=True)

        if subset is not None:
            df = df.head(subset)

        self.data = df
        log(f"Loaded {len(self.data)} '{split}' sample(s) from {os.path.basename(csv_path)}")

    def __len__(self):
        return len(self.data)

    def __getitem__(self, idx):
        row = self.data.iloc[idx]
        filename = row["filename"]
        label = str(row["label"]).strip()

        img_path = os.path.join(self.img_dir, filename)
        image = Image.open(img_path).convert("RGB")

        pixel_values = self.processor(images=image, return_tensors="pt").pixel_values.squeeze(0)

        labels = self.processor.tokenizer(
            label,
            padding="max_length",
            max_length=self.max_label_length,
            truncation=True,
            return_tensors="pt",
        ).input_ids.squeeze(0)

        # Replace padding token id with -100 so it's ignored in the loss.
        labels[labels == self.processor.tokenizer.pad_token_id] = -100

        return {"pixel_values": pixel_values, "labels": labels}


def load_base(source):
    """Load a processor+model from a local folder or a Hugging Face name.

    Prefers the local cache (download_trocr.py) and only reaches for the hub when
    the weights are genuinely absent, so an offline machine still trains."""
    if os.path.isdir(source):
        return (
            TrOCRProcessor.from_pretrained(source),
            VisionEncoderDecoderModel.from_pretrained(source),
        )

    try:
        return (
            TrOCRProcessor.from_pretrained(source, local_files_only=True),
            VisionEncoderDecoderModel.from_pretrained(source, local_files_only=True),
        )
    except Exception:
        return (
            TrOCRProcessor.from_pretrained(source),
            VisionEncoderDecoderModel.from_pretrained(source),
        )


def resolve_base_model(base_model):
    """Turn a model key from the UI into something from_pretrained accepts."""
    if not base_model or base_model in ("base", MODEL_NAME):
        return MODEL_NAME

    candidate = os.path.join(MODELS_DIR, base_model)
    if os.path.isdir(candidate):
        return candidate

    return base_model


def _configure_for_finetuning(model, processor, max_label_length):
    """Align the decoder convention between training and inference.

    TrOCR is pretrained to start decoding from the EOS/SEP token (id 2), NOT the
    CLS token (id 0). Overriding decoder_start_token_id with cls_token_id trains
    the decoder under one convention while generate() uses another (the
    generation_config keeps id 2), which produces garbage at inference and blows
    up CER/WER. Keep the pretrained convention and sync both configs."""
    model.config.decoder_start_token_id = processor.tokenizer.sep_token_id
    model.config.pad_token_id = processor.tokenizer.pad_token_id
    model.config.vocab_size = model.config.decoder.vocab_size
    model.config.eos_token_id = processor.tokenizer.sep_token_id

    model.generation_config.decoder_start_token_id = model.config.decoder_start_token_id
    model.generation_config.eos_token_id = model.config.eos_token_id
    model.generation_config.pad_token_id = model.config.pad_token_id
    model.generation_config.max_length = max_label_length


def check_disk_space(path, minimum=MIN_FREE_BYTES):
    """A checkpoint is ~1.3 GB. Running out mid-save wastes the whole run."""
    target = path
    while target and not os.path.isdir(target):
        parent = os.path.dirname(target)
        if parent == target:
            break
        target = parent

    free = shutil.disk_usage(target or ML_ROOT).free
    if free < minimum:
        raise TrainingError(
            f"Not enough free disk space: {free / 1024 ** 3:.1f} GB available, "
            f"{minimum / 1024 ** 3:.1f} GB needed for checkpoints."
        )
    return free


def run_training(
    dataset=DEFAULT_DATASET,
    output_name=DEFAULT_OUTPUT_NAME,
    base_model=MODEL_NAME,
    epochs=EPOCHS,
    batch_size=BATCH_SIZE,
    learning_rate=LEARNING_RATE,
    max_label_length=MAX_LABEL_LENGTH,
    num_workers=NUM_WORKERS,
    train_subset=TRAIN_SUBSET,
    val_subset=VAL_SUBSET,
    manifest_csv=None,
    train_img_dir=None,
    val_img_dir=None,
    save_dir=None,
    progress=None,
    should_cancel=None,
    log=None,
    verbose=False,
):
    """Fine-tune TrOCR and return the run's metrics.

    Args:
        progress: callable(**fields) receiving stage/epoch/step/loss updates.
        should_cancel: callable() -> bool, polled between batches.
        log: callable(str) for human-readable lines.
        verbose: draw tqdm bars (CLI only).

    Returns:
        dict with best_val_loss, epochs_completed, save_dir, elapsed, history,
        and cancelled.

    Raises:
        TrainingError for a bad config or dataset.
    """
    progress = progress or _noop
    log = log or _noop
    should_cancel = should_cancel or (lambda: False)

    # ---------------------------------------------------------------- resolve paths
    if manifest_csv is None or train_img_dir is None or val_img_dir is None:
        paths = ds.resolve_paths(dataset, "train")
        manifest_csv = manifest_csv or paths["manifest"]
        train_img_dir = train_img_dir or paths["images"]
        val_img_dir = val_img_dir or os.path.join(paths["root"], "val")

    output_name = ds.sanitise_name(output_name or DEFAULT_OUTPUT_NAME)
    save_dir = save_dir or os.path.join(MODELS_DIR, output_name)

    for path, desc in ((manifest_csv, "Manifest CSV"),
                       (train_img_dir, "Train image folder"),
                       (val_img_dir, "Val image folder")):
        if not os.path.exists(path):
            raise TrainingError(f"{desc} not found at '{path}'.")

    if os.path.isdir(save_dir) and os.listdir(save_dir):
        raise TrainingError(
            f"'{output_name}' already exists under ml/models/. "
            "Choose another output name, or delete that model first."
        )

    check_disk_space(save_dir)

    # ------------------------------------------------------------------ device
    device = torch.device("cuda" if torch.cuda.is_available() else "cpu")
    log(f"Device: {device}")
    if device.type == "cuda":
        log(f"GPU: {torch.cuda.get_device_name(0)} "
            f"({torch.cuda.get_device_properties(0).total_memory / 1e9:.1f} GB VRAM)")

    progress(stage="loading-model", total_epochs=epochs)

    source = resolve_base_model(base_model)
    log(f"Loading base model: {source}")
    processor, model = load_base(source)
    _configure_for_finetuning(model, processor, max_label_length)
    model.to(device)
    log(f"Model ready. Parameters: {sum(p.numel() for p in model.parameters()):,}")

    if should_cancel():
        return _cancelled_result(save_dir, epochs, checkpoint_written=False)

    # ----------------------------------------------------------------- datasets
    progress(stage="preparing-data")

    train_dataset = HandwrittenDataset(
        manifest_csv, train_img_dir, processor, max_label_length,
        split="train", subset=train_subset, log=log,
    )
    val_dataset = HandwrittenDataset(
        manifest_csv, val_img_dir, processor, max_label_length,
        split="val", subset=val_subset, log=log,
    )

    if len(train_dataset) == 0:
        raise TrainingError("No usable training samples. Validate the dataset first.")

    train_loader = DataLoader(
        train_dataset, batch_size=batch_size, shuffle=True,
        num_workers=num_workers, pin_memory=True,
    )
    val_loader = DataLoader(
        val_dataset, batch_size=batch_size, shuffle=False,
        num_workers=num_workers, pin_memory=True,
    )

    optimizer = AdamW(model.parameters(), lr=learning_rate)
    use_amp = device.type == "cuda"
    scaler = GradScaler(enabled=use_amp)

    log(f"Epochs {epochs} | batch {batch_size} | lr {learning_rate} | "
        f"train {len(train_dataset)} | val {len(val_dataset)} | amp {use_amp}")

    progress(
        stage="training",
        total_epochs=epochs,
        total_steps=len(train_loader),
        step=0,
        epoch=0,
    )

    best_val_loss = float("inf")
    history = []
    checkpoint_written = False
    epochs_completed = 0
    started = time.monotonic()

    for epoch in range(1, epochs + 1):
        model.train()
        epoch_loss = 0.0
        num_batches = 0
        epoch_start = time.monotonic()

        bar = tqdm(
            train_loader,
            desc=f"Epoch {epoch}/{epochs} [Train]",
            unit="batch",
            bar_format="{l_bar}{bar:30}{r_bar}",
            disable=not verbose,
        )

        for batch in bar:
            if should_cancel():
                bar.close()
                log(f"Cancelled during epoch {epoch}.")
                return _cancelled_result(
                    save_dir, epochs, checkpoint_written,
                    epochs_completed=epochs_completed,
                    # inf is not valid JSON, and this is serialised straight into
                    # the job snapshot.
                    best_val_loss=(
                        round(best_val_loss, 4) if best_val_loss != float("inf") else None
                    ),
                    history=history,
                    elapsed=time.monotonic() - started,
                )

            pixel_values = batch["pixel_values"].to(device)
            labels = batch["labels"].to(device)

            optimizer.zero_grad()

            if use_amp:
                with autocast():
                    outputs = model(pixel_values=pixel_values, labels=labels)
                    loss = outputs.loss
                scaler.scale(loss).backward()
                scaler.step(optimizer)
                scaler.update()
            else:
                outputs = model(pixel_values=pixel_values, labels=labels)
                loss = outputs.loss
                loss.backward()
                optimizer.step()

            epoch_loss += loss.item()
            num_batches += 1
            avg_loss = epoch_loss / num_batches

            bar.set_postfix(loss=f"{avg_loss:.4f}")
            progress(
                stage="training",
                epoch=epoch,
                total_epochs=epochs,
                step=num_batches,
                total_steps=len(train_loader),
                loss=round(avg_loss, 4),
            )

        bar.close()
        avg_train_loss = epoch_loss / max(num_batches, 1)

        # ------------------------------------------------------------ validation
        progress(stage="validating", epoch=epoch)
        val_loss = _validate(model, val_loader, device, verbose, epoch, epochs)

        epochs_completed = epoch
        epoch_time = time.monotonic() - epoch_start
        elapsed = time.monotonic() - started
        eta = (elapsed / epoch) * (epochs - epoch)

        history.append({
            "epoch": epoch,
            "train_loss": round(avg_train_loss, 4),
            "val_loss": round(val_loss, 4),
            "seconds": round(epoch_time, 1),
        })

        log(f"Epoch {epoch}/{epochs} - train {avg_train_loss:.4f} | "
            f"val {val_loss:.4f} | {epoch_time:.0f}s | ETA {eta / 60:.1f} min")

        progress(
            stage="training",
            epoch=epoch,
            loss=round(avg_train_loss, 4),
            val_loss=round(val_loss, 4),
            eta_seconds=round(eta),
        )

        # Save only on improvement, and only between epochs, so a cancel never
        # interrupts a write.
        if val_loss < best_val_loss:
            best_val_loss = val_loss
            progress(stage="saving", epoch=epoch)
            model.save_pretrained(save_dir)
            processor.save_pretrained(save_dir)
            checkpoint_written = True
            log(f"Best so far - saved to ml/models/{os.path.basename(save_dir)}/")

    total = time.monotonic() - started

    if not checkpoint_written:
        raise TrainingError(
            "Training finished without saving a checkpoint. Validation loss never improved."
        )

    log(f"Training complete in {total / 60:.1f} min. Best val loss {best_val_loss:.4f}.")

    return {
        "cancelled": False,
        "output_name": os.path.basename(save_dir),
        "save_dir": save_dir,
        "epochs_completed": epochs_completed,
        "elapsed": round(total, 1),
        "history": history,
        "metrics": {
            "best_val_loss": round(best_val_loss, 4),
            "epochs_completed": epochs_completed,
            "train_samples": len(train_dataset),
            "val_samples": len(val_dataset),
        },
    }


def _validate(model, val_loader, device, verbose, epoch, epochs):
    """Average validation loss for one epoch."""
    model.eval()
    total_loss = 0.0
    batches = 0

    bar = tqdm(
        val_loader,
        desc=f"Epoch {epoch}/{epochs} [Val]  ",
        unit="batch",
        bar_format="{l_bar}{bar:30}{r_bar}",
        disable=not verbose,
    )

    with torch.no_grad():
        for batch in bar:
            outputs = model(
                pixel_values=batch["pixel_values"].to(device),
                labels=batch["labels"].to(device),
            )
            total_loss += outputs.loss.item()
            batches += 1
            bar.set_postfix(loss=f"{total_loss / batches:.4f}")

    bar.close()
    return total_loss / max(batches, 1)


def _cancelled_result(save_dir, epochs, checkpoint_written, **extra):
    """Leave no half-written checkpoint behind.

    A checkpoint is only ever written between epochs, so one that exists is
    complete and worth keeping. An output folder with nothing in it is not."""
    if not checkpoint_written:
        shutil.rmtree(save_dir, ignore_errors=True)

    return {
        "cancelled": True,
        "output_name": os.path.basename(save_dir),
        "save_dir": save_dir if checkpoint_written else None,
        "checkpoint_kept": checkpoint_written,
        "total_epochs": epochs,
        **extra,
    }


# ============================================================
# CLI wrapper - `python ml\train_trocr.py` still works.
# ============================================================
def main(argv=None):
    parser = argparse.ArgumentParser(description="Fine-tune TrOCR on a named dataset.")
    parser.add_argument("--dataset", default=DEFAULT_DATASET)
    parser.add_argument("--output-name", default=DEFAULT_OUTPUT_NAME)
    parser.add_argument("--base-model", default=MODEL_NAME)
    parser.add_argument("--epochs", type=int, default=EPOCHS)
    parser.add_argument("--batch-size", type=int, default=BATCH_SIZE)
    parser.add_argument("--learning-rate", type=float, default=LEARNING_RATE)
    parser.add_argument("--max-label-length", type=int, default=MAX_LABEL_LENGTH)
    parser.add_argument("--num-workers", type=int, default=NUM_WORKERS)
    parser.add_argument("--train-subset", type=int, default=TRAIN_SUBSET)
    parser.add_argument("--val-subset", type=int, default=VAL_SUBSET)
    args = parser.parse_args(argv)

    print("=" * 60)
    print("TrOCR FINE-TUNING")
    print("=" * 60)

    try:
        result = run_training(
            dataset=args.dataset,
            output_name=args.output_name,
            base_model=args.base_model,
            epochs=args.epochs,
            batch_size=args.batch_size,
            learning_rate=args.learning_rate,
            max_label_length=args.max_label_length,
            num_workers=args.num_workers,
            train_subset=args.train_subset,
            val_subset=args.val_subset,
            log=lambda line: print(f"  {line}"),
            verbose=True,
        )
    except (TrainingError, ds.DatasetError) as e:
        print(f"\nERROR: {e}")
        return 1

    print("=" * 60)
    print("TRAINING COMPLETE")
    print("=" * 60)
    print(f"Best validation loss: {result['metrics']['best_val_loss']}")
    print(f"Model saved to: {os.path.abspath(result['save_dir'])}")
    print("\nTo evaluate it, run:")
    print(f"  python ml\\test_finetuned.py --model {result['output_name']}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
