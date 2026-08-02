r"""
predict.py
Spot-checks a TrOCR model on loose, unlabelled images.

No CSV or labels needed - hand it a folder or a list of files. Each prediction
carries a confidence score (the model's certainty in its own output, 0-100%).
This is not accuracy: there is no ground truth here. Low confidence is a useful
flag for predictions worth reviewing, nothing more.

Two ways to run it:

  CLI         python ml\predict.py --folder path\to\images --model base
  In-process  from predict import run_prediction
              rows = run_prediction(model="base", folder="...")

`run_prediction()` returns rows as data. Writing predictions.csv is opt-in, so the
API can call it without leaving files behind.
"""

import argparse
import os

# Quiets the HF stack. Must precede torch/transformers.
import hf_quiet  # noqa: F401

import csv
import math

import torch
from PIL import Image
from transformers import TrOCRProcessor, VisionEncoderDecoderModel

import dataset_registry as ds

# ============================================================
# CONFIG - defaults only.
# ============================================================
ML_ROOT = os.path.dirname(os.path.abspath(__file__))
MODELS_DIR = os.path.join(ML_ROOT, "models")

BASE_MODEL_KEY = "base"
BASE_MODEL_NAME = "microsoft/trocr-base-handwritten"

DEFAULT_MODEL = "TrOCR-fine-tune-10k-samples"
DEFAULT_FOLDER = os.path.join(ML_ROOT, "new_images")

MAX_NEW_TOKENS = 32

# The API's /predict is synchronous, so it is capped. Anything larger belongs in
# an evaluation job.
MAX_IMAGES = 50

# Below this, flag the prediction for review. Mirrors CRMS's own threshold.
REVIEW_THRESHOLD = 80.0

IMAGE_EXTENSIONS = ds.IMAGE_EXTENSIONS
# ============================================================


class PredictionError(Exception):
    """Missing model or images. Raised instead of exiting."""


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

    raise PredictionError(f"Model '{model}' was not found under ml/models/.")


def _load(source):
    return (
        TrOCRProcessor.from_pretrained(source),
        VisionEncoderDecoderModel.from_pretrained(source),
    )


def eos_token_id(net, processor):
    """The EOS id can live in several places depending on the model."""
    return (
        getattr(net.generation_config, "eos_token_id", None)
        or getattr(net.config, "eos_token_id", None)
        or getattr(net.config.decoder, "eos_token_id", None)
        or processor.tokenizer.sep_token_id
    )


def sequence_confidence(net, gen_output, eos_id):
    """Geometric mean of per-token probabilities up to the first EOS, as a %.

    Identical to the calculation in api/main.py - the number a Super Admin sees
    while spot-checking must be the number Staff see while scanning."""
    try:
        scores = net.compute_transition_scores(
            gen_output.sequences, gen_output.scores, normalize_logits=True
        )[0]
        gen_tokens = gen_output.sequences[0][1:1 + len(scores)]
        log_probs = []
        for tok, lp in zip(gen_tokens, scores):
            if not torch.isfinite(lp):
                continue
            log_probs.append(lp.item())
            if tok.item() == eos_id:
                break
        if not log_probs:
            return 0.0
        return round(math.exp(sum(log_probs) / len(log_probs)) * 100.0, 1)
    except Exception:
        return 0.0


def collect_images(folder, limit=MAX_IMAGES):
    if not os.path.isdir(folder):
        raise PredictionError(f"Image folder not found at '{folder}'.")

    files = sorted(
        os.path.join(folder, f) for f in os.listdir(folder)
        if f.lower().endswith(IMAGE_EXTENSIONS)
    )

    if not files:
        raise PredictionError(f"No images found in '{folder}'.")

    return files[:limit] if limit else files


def run_prediction(
    model=DEFAULT_MODEL,
    image_paths=None,
    folder=None,
    limit=MAX_IMAGES,
    max_new_tokens=MAX_NEW_TOKENS,
    loader=None,
    log=None,
    write_csv=False,
):
    """Predict text for each image and return the rows.

    Args:
        image_paths: explicit list of files. Takes precedence over `folder`.
        loader: optional callable(source) -> (processor, model), so the API can
            hand over a model it already has in VRAM.
        write_csv: also write predictions.csv beside the images (CLI behaviour).

    Returns:
        dict with model_key, rows [{filename, text, confidence, error}],
        average_confidence, low_confidence count, and csv path if written.
    """
    log = log or _noop
    loader = loader or _load

    source, model_key = resolve_model(model)

    if image_paths:
        paths = list(image_paths)[:limit] if limit else list(image_paths)
    else:
        paths = collect_images(folder or DEFAULT_FOLDER, limit)

    if not paths:
        raise PredictionError("No images to predict.")

    log(f"Loading model: {source}")
    processor, net = loader(source)
    device = torch.device("cuda" if torch.cuda.is_available() else "cpu")
    net.to(device)
    net.eval()
    eos_id = eos_token_id(net, processor)

    log(f"Predicting {len(paths)} image(s) on {device}.")

    rows = []
    for path in paths:
        filename = os.path.basename(path)
        try:
            image = Image.open(path).convert("RGB")
            pixel_values = processor(images=image, return_tensors="pt").pixel_values.to(device)

            with torch.no_grad():
                gen_output = net.generate(
                    pixel_values,
                    max_new_tokens=max_new_tokens,
                    output_scores=True,
                    return_dict_in_generate=True,
                )

            text = processor.batch_decode(
                gen_output.sequences, skip_special_tokens=True
            )[0].strip()

            rows.append({
                "filename": filename,
                "text": text,
                "confidence": sequence_confidence(net, gen_output, eos_id),
            })
        except Exception as e:
            # One bad file must not fail the batch.
            rows.append({
                "filename": filename, "text": "", "confidence": 0.0, "error": str(e),
            })

    scored = [r["confidence"] for r in rows if "error" not in r]
    average = round(sum(scored) / len(scored), 1) if scored else 0.0
    low = sum(1 for c in scored if c < REVIEW_THRESHOLD)

    csv_path = None
    if write_csv:
        target_dir = os.path.dirname(paths[0]) or ML_ROOT
        csv_path = write_predictions_csv(rows, target_dir)
        log(f"Results saved to: {csv_path}")

    return {
        "model_key": model_key,
        "rows": rows,
        "count": len(rows),
        "average_confidence": average,
        "low_confidence": low,
        "threshold": REVIEW_THRESHOLD,
        "csv": csv_path,
    }


def write_predictions_csv(rows, directory):
    """Write predictions.csv beside the images. CLI convenience only."""
    path = os.path.join(directory, "predictions.csv")
    with open(path, "w", encoding="utf-8", newline="") as fh:
        writer = csv.writer(fh)
        writer.writerow(["FILENAME", "PREDICTION", "CONFIDENCE"])
        for row in rows:
            failed = "error" in row
            writer.writerow([
                row["filename"],
                f"ERROR: {row['error']}" if failed else row["text"],
                "" if failed else f"{row['confidence']:.1f}",
            ])
    return path


# ============================================================
# CLI wrapper - `python ml\predict.py` still works.
# ============================================================
def main(argv=None):
    parser = argparse.ArgumentParser(description="Predict text for loose handwritten images.")
    parser.add_argument("--folder", default=DEFAULT_FOLDER)
    parser.add_argument("--model", default=DEFAULT_MODEL, help="Folder under ml/models/, or 'base'.")
    parser.add_argument("--limit", type=int, default=None, help="Max images (default: all).")
    args = parser.parse_args(argv)

    print("=" * 60)
    print("TrOCR PREDICTION - NEW IMAGES")
    print("=" * 60)
    print("Note: 'Conf %' is the model's certainty in its own output,")
    print("      not accuracy - these images have no ground-truth labels.")
    print()

    try:
        result = run_prediction(
            model=args.model,
            folder=args.folder,
            limit=args.limit,
            log=lambda line: print(f"  {line}"),
            write_csv=True,
        )
    except PredictionError as e:
        print(f"\nERROR: {e}")
        return 1

    print()
    print(f"{'#':<4} {'Filename':<35} {'Conf %':<8} Predicted text")
    print("-" * 80)
    for i, row in enumerate(result["rows"], start=1):
        if row.get("error"):
            print(f"{i:<4} {row['filename']:<35} {'-':<8} ERROR: {row['error']}")
        else:
            print(f"{i:<4} {row['filename']:<35} {row['confidence']:<8.1f} {row['text']}")
    print("-" * 80)

    print(f"\nDone. Predicted {result['count']} image(s).")
    print(f"Average confidence: {result['average_confidence']:.1f}%")
    print(f"Low-confidence (<{result['threshold']:.0f}%) predictions to review: "
          f"{result['low_confidence']}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
