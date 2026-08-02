r"""
test_finetuned.py
Evaluates a TrOCR model against a labelled dataset split and reports
CER / WER / exact-match, plus a chart under ml/evaluation-metrics/.

Two ways to run it:

  CLI         python ml\test_finetuned.py --model TrOCR-fine-tune-10k-samples --split test
  In-process  from test_finetuned import run_evaluation
              run_evaluation(model="base", dataset="default", split="test")

The CONFIG block holds *defaults* only. `run_evaluation()` takes every value as an
argument, returns its metrics as data rather than only printing them, and polls a
cancel callback between images so the API can stop it cleanly.
"""

import argparse
import os

# Quiets the HF stack. Must precede torch/transformers.
import hf_quiet  # noqa: F401

import pandas as pd
import torch
from PIL import Image
from transformers import TrOCRProcessor, VisionEncoderDecoderModel

import dataset_registry as ds
from metrics import compute_metrics, print_metrics, save_metrics_png

# ============================================================
# CONFIG - defaults only.
# ============================================================
ML_ROOT = os.path.dirname(os.path.abspath(__file__))
MODELS_DIR = os.path.join(ML_ROOT, "models")

BASE_MODEL_KEY = "base"
BASE_MODEL_NAME = "microsoft/trocr-base-handwritten"

DEFAULT_MODEL = "TrOCR-fine-tune-10k-samples"
DEFAULT_DATASET = ds.DEFAULT_DATASET
DEFAULT_SPLIT = "test"

# Cap on how many samples to evaluate. None = the whole split.
NUM_SAMPLES = None

MAX_NEW_TOKENS = 32

# Images per generate() call. One-at-a-time inference leaves the GPU mostly idle
# between kernel launches; a 6000-image test split goes from tens of minutes to a
# few. Every image is resized to the same encoder input, so batching changes no
# individual prediction.
BATCH_SIZE = 8

# Per-sample rows returned to the caller. The full run can be thousands of images;
# the UI only needs a readable sample next to the aggregate figures.
MAX_RETURNED_ROWS = 100

IMAGE_EXTENSIONS = ds.IMAGE_EXTENSIONS
# ============================================================


class EvaluationError(Exception):
    """Missing model, dataset, or split. Raised instead of exiting."""


def _noop(*_args, **_kwargs):
    pass


def resolve_model(model):
    """Turn a model key from the UI into something from_pretrained accepts."""
    if not model or model == BASE_MODEL_KEY:
        return BASE_MODEL_NAME, BASE_MODEL_KEY

    if os.path.isdir(model):
        return model, os.path.basename(os.path.normpath(model))

    candidate = os.path.join(MODELS_DIR, model)
    if os.path.isdir(candidate):
        return candidate, model

    raise EvaluationError(
        f"Model '{model}' was not found under ml/models/. Add it, or pick another."
    )


def _load(source):
    processor = TrOCRProcessor.from_pretrained(source)
    model = VisionEncoderDecoderModel.from_pretrained(source)
    return processor, model


def _predict_batch(processor, net, device, paths, max_new_tokens):
    """Decoded text for a batch of image paths, in the same order."""
    images = [Image.open(path).convert("RGB") for path in paths]
    pixel_values = processor(images=images, return_tensors="pt").pixel_values.to(device)

    with torch.no_grad():
        generated = net.generate(pixel_values, max_new_tokens=max_new_tokens)

    return [text.strip() for text in processor.batch_decode(generated, skip_special_tokens=True)]


def run_evaluation(
    model=DEFAULT_MODEL,
    dataset=DEFAULT_DATASET,
    split=DEFAULT_SPLIT,
    limit=NUM_SAMPLES,
    manifest_csv=None,
    image_dir=None,
    max_new_tokens=MAX_NEW_TOKENS,
    batch_size=BATCH_SIZE,
    save_chart=True,
    loader=None,
    progress=None,
    should_cancel=None,
    log=None,
    on_row=None,
):
    """Evaluate `model` over one split and return metrics as data.

    Args:
        loader: optional callable(source) -> (processor, model). The API passes
            its own cache so a model already in VRAM is not loaded twice.
        progress: callable(**fields) receiving step/total_steps updates.
        should_cancel: callable() -> bool, polled per image.
        on_row: callable(dict) for each prediction, for streaming output.

    Returns:
        dict with metrics, rows (capped), chart, evaluated, model_key, cancelled.
    """
    progress = progress or _noop
    log = log or _noop
    should_cancel = should_cancel or (lambda: False)
    loader = loader or _load

    if split not in ds.SPLITS:
        raise EvaluationError(f"Unknown split '{split}'. Expected one of {', '.join(ds.SPLITS)}.")

    source, model_key = resolve_model(model)

    # ---------------------------------------------------------------- dataset paths
    if manifest_csv is None or image_dir is None:
        try:
            paths = ds.resolve_paths(dataset, split)
        except ds.DatasetError as e:
            raise EvaluationError(str(e)) from e
        manifest_csv = manifest_csv or paths["manifest"]
        image_dir = image_dir or paths["images"]

    if not os.path.isfile(manifest_csv):
        raise EvaluationError(f"Manifest not found at '{manifest_csv}'.")
    if not os.path.isdir(image_dir):
        raise EvaluationError(f"Split folder not found at '{image_dir}'.")

    # ---------------------------------------------------------------- labels/images
    df = pd.read_csv(manifest_csv, keep_default_na=False)
    if "filename" not in df.columns or "label" not in df.columns:
        raise EvaluationError("manifest.csv needs at least 'filename' and 'label' columns.")

    def label_map(frame):
        return dict(zip(frame["filename"], frame["label"].astype(str)))

    def usable_images(mapping):
        return [
            f for f in sorted(os.listdir(image_dir))
            if f.lower().endswith(IMAGE_EXTENSIONS) and f in mapping
            and ds.is_usable(mapping[f].strip())
        ]

    # Scope to the split under evaluation. Without this a file sitting in test/
    # whose manifest row says split=train is still scored, which quietly mixes
    # data the model was fitted on into the numbers and flatters CER/WER.
    scoped = df
    if "split" in df.columns:
        scoped = df[df["split"].astype(str).str.strip().str.lower() == split]

    labels = label_map(scoped)
    image_files = usable_images(labels)

    if not image_files and len(scoped) != len(df):
        # Folder layout and the split column disagree. Reporting an empty split
        # would be technically right and useless, so fall back and say so.
        log(f"No rows marked '{split}' matched the images in {image_dir}; "
            "falling back to every manifest row.")
        labels = label_map(df)
        image_files = usable_images(labels)

    if limit:
        image_files = image_files[:limit]

    if not image_files:
        raise EvaluationError(
            f"No labelled images to evaluate in '{split}'. "
            "Check the manifest splits and that labels are not all UNREADABLE."
        )

    # ------------------------------------------------------------------ the model
    progress(stage="loading-model", total_steps=len(image_files), step=0)
    log(f"Loading model: {source}")

    processor, net = loader(source)
    device = torch.device("cuda" if torch.cuda.is_available() else "cpu")
    net.to(device)
    net.eval()

    total = len(image_files)
    batch_size = max(int(batch_size or 1), 1)
    log(f"Evaluating {total} image(s) from '{split}' on {device}, batch {batch_size}.")
    progress(stage="evaluating", total_steps=total, step=0)

    references, predictions, rows = [], [], []
    cancelled = False
    processed = 0

    for start in range(0, total, batch_size):
        # Checked per batch rather than per image: a batch is the smallest unit
        # that can be abandoned without wasting a partial generate() call.
        if should_cancel():
            cancelled = True
            log(f"Cancelled after {processed} image(s).")
            break

        chunk = image_files[start:start + batch_size]
        paths = [os.path.join(image_dir, name) for name in chunk]

        try:
            texts = _predict_batch(processor, net, device, paths, max_new_tokens)
        except Exception as e:
            # A single unreadable file must not abort a long evaluation, and it
            # must not take the rest of its batch down with it. Retry the batch
            # one image at a time and skip only the ones that really fail.
            log(f"Batch of {len(chunk)} failed ({e}); retrying individually.")
            texts = []
            for path in paths:
                try:
                    texts.append(
                        _predict_batch(processor, net, device, [path], max_new_tokens)[0]
                    )
                except Exception as inner:
                    log(f"Skipped {os.path.basename(path)}: {inner}")
                    texts.append(None)

        for filename, predicted in zip(chunk, texts):
            processed += 1
            if predicted is None:
                continue

            ground_truth = str(labels[filename]).strip()
            references.append(ground_truth)
            predictions.append(predicted)

            row = {
                "filename": filename,
                "reference": ground_truth,
                "prediction": predicted,
                "match": predicted == ground_truth,
            }
            if len(rows) < MAX_RETURNED_ROWS:
                rows.append(row)
            if on_row:
                on_row(row)

        progress(stage="evaluating", step=processed, total_steps=total)

    if not references:
        raise EvaluationError("No samples were evaluated.")

    results = compute_metrics(references, predictions)

    chart = None
    if save_chart and not cancelled:
        progress(stage="charting")
        variant = "base" if model_key == BASE_MODEL_KEY else "finetuned"
        chart = save_metrics_png(results, subfolder=variant, model_label=model_key)
        chart = {"variant": variant, "name": os.path.basename(chart), "path": chart}

    log(f"CER {results['cer'] * 100:.2f}% | WER {results['wer'] * 100:.2f}% | "
        f"exact {results['accuracy'] * 100:.2f}% over {results['total']} sample(s).")

    return {
        "cancelled": cancelled,
        "model_key": model_key,
        "dataset": dataset,
        "split": split,
        "evaluated": results["total"],
        "chart": chart,
        "rows": rows,
        "metrics": {
            # Rates as 0-1 decimals, matching the ocr_models columns.
            "cer": round(results["cer"], 4),
            "wer": round(results["wer"], 4),
            "exact_match": round(results["accuracy"], 4),
            "exact": results["exact"],
            "total": results["total"],
        },
    }


# ============================================================
# CLI wrapper - `python ml\test_finetuned.py` still works.
# ============================================================
def main(argv=None):
    parser = argparse.ArgumentParser(description="Evaluate a TrOCR model on a dataset split.")
    parser.add_argument("--model", default=DEFAULT_MODEL, help="Folder under ml/models/, or 'base'.")
    parser.add_argument("--dataset", default=DEFAULT_DATASET)
    parser.add_argument("--split", default=DEFAULT_SPLIT, choices=list(ds.SPLITS))
    parser.add_argument("--limit", type=int, default=NUM_SAMPLES)
    parser.add_argument("--batch-size", type=int, default=BATCH_SIZE,
                        help="Images per generate() call. Lower it if VRAM is tight.")
    parser.add_argument("--no-chart", action="store_true")
    args = parser.parse_args(argv)

    print("=" * 60)
    print("TrOCR - EVALUATION")
    print("=" * 60)

    printed = {"header": False}

    def show(row):
        if not printed["header"]:
            print(f"\n{'Filename':<28} {'Ground truth':<22} {'Prediction':<22} Match")
            print("-" * 82)
            printed["header"] = True
        print(f"{row['filename']:<28} {row['reference']:<22} {row['prediction']:<22} "
              f"{'OK' if row['match'] else 'x'}")

    try:
        result = run_evaluation(
            model=args.model,
            dataset=args.dataset,
            split=args.split,
            limit=args.limit,
            batch_size=args.batch_size,
            save_chart=not args.no_chart,
            log=lambda line: print(f"  {line}"),
            on_row=show,
        )
    except (EvaluationError, ds.DatasetError) as e:
        print(f"\nERROR: {e}")
        return 1

    print("-" * 82)
    print()
    print_metrics(
        {
            "cer": result["metrics"]["cer"],
            "wer": result["metrics"]["wer"],
            "accuracy": result["metrics"]["exact_match"],
            "exact": result["metrics"]["exact"],
            "total": result["metrics"]["total"],
        },
        title=f"EVALUATION METRICS - {result['model_key']}",
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
