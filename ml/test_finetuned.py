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
import warnings
import logging

# --- Suppress warnings ---
os.environ.setdefault("HF_HUB_DISABLE_SYMLINKS_WARNING", "1")
os.environ.setdefault("HF_HUB_DISABLE_IMPLICIT_TOKEN", "1")
os.environ.setdefault("TRANSFORMERS_NO_ADVISORY_WARNINGS", "1")
os.environ.setdefault("TRANSFORMERS_VERBOSITY", "error")
warnings.filterwarnings("ignore")
logging.disable(logging.WARNING)

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


def run_evaluation(
    model=DEFAULT_MODEL,
    dataset=DEFAULT_DATASET,
    split=DEFAULT_SPLIT,
    limit=NUM_SAMPLES,
    manifest_csv=None,
    image_dir=None,
    max_new_tokens=MAX_NEW_TOKENS,
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

    labels = dict(zip(df["filename"], df["label"].astype(str)))

    image_files = [
        f for f in sorted(os.listdir(image_dir))
        if f.lower().endswith(IMAGE_EXTENSIONS) and f in labels
        and ds.is_usable(labels[f].strip())
    ]

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

    log(f"Evaluating {len(image_files)} image(s) from '{split}' on {device}.")
    progress(stage="evaluating", total_steps=len(image_files), step=0)

    references, predictions, rows = [], [], []
    cancelled = False

    for index, filename in enumerate(image_files, start=1):
        if should_cancel():
            cancelled = True
            log(f"Cancelled after {index - 1} image(s).")
            break

        ground_truth = str(labels[filename]).strip()
        img_path = os.path.join(image_dir, filename)

        try:
            image = Image.open(img_path).convert("RGB")
            pixel_values = processor(images=image, return_tensors="pt").pixel_values.to(device)

            with torch.no_grad():
                generated = net.generate(pixel_values, max_new_tokens=max_new_tokens)

            predicted = processor.batch_decode(generated, skip_special_tokens=True)[0].strip()
        except Exception as e:
            # One unreadable file must not abort a multi-hour evaluation.
            log(f"Skipped {filename}: {e}")
            continue

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

        # Reporting every image would be noise on a 6000-image run.
        if index % 10 == 0 or index == len(image_files):
            progress(stage="evaluating", step=index, total_steps=len(image_files))

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
