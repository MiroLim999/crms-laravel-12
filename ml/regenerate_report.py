"""
regenerate_report.py
Helper script to rebuild a valid CRMS evaluation-report.json for an installed OCR model.

THIS SCRIPT DOES NOT MEASURE ANYTHING. It only hashes the real weights file and
writes down the numbers you give it. So it has no default numbers: every figure
must be copied from a real run on the locked test split (the fine-tuning
notebook's printed result, or `python ml/test_finetuned.py`). An earlier version
carried built-in defaults, and a model's report ended up showing numbers nobody
had measured.

Usage:
    python ml/regenerate_report.py --model TrOcr-50k-broken-samples \
        --dataset synthetic-broken-handwritten-samples50k --samples 5000 \
        --cer 0.0003 --wer 0.0010 --exact 0.9978

Rates are decimals: 0.0003 means 0.03%.
"""

import argparse
import datetime
import hashlib
import json
import os
import sys

ML_ROOT = os.path.dirname(os.path.abspath(__file__))
MODELS_DIR = os.path.join(ML_ROOT, "models")
WEIGHT_FILES = ("model.safetensors", "pytorch_model.bin")
CHUNK_SIZE = 8 * 1024 * 1024


def sha256_file(path: str) -> str:
    digest = hashlib.sha256()
    with open(path, "rb") as f:
        while chunk := f.read(CHUNK_SIZE):
            digest.update(chunk)
    return digest.hexdigest()


def regenerate(
    model_name: str,
    dataset: str,
    sample_count: int,
    cer: float,
    wer: float,
    exact_match: float,
    evaluated_at: str | None = None,
) -> str:
    model_dir = os.path.join(MODELS_DIR, model_name)
    if not os.path.isdir(model_dir):
        raise FileNotFoundError(f"Model directory '{model_dir}' not found.")

    weights_file = next(
        (w for w in WEIGHT_FILES if os.path.isfile(os.path.join(model_dir, w))),
        None,
    )
    if not weights_file:
        raise FileNotFoundError(
            f"Weights file ({' or '.join(WEIGHT_FILES)}) not found in {model_dir}."
        )

    weights_path = os.path.join(model_dir, weights_file)
    print(f"Hashing weights file '{weights_file}' ({os.path.getsize(weights_path):,} bytes)...")
    weights_sha = sha256_file(weights_path)
    print(f"SHA-256: {weights_sha}")

    if not evaluated_at:
        evaluated_at = datetime.datetime.now(datetime.timezone.utc).isoformat()

    manifest_sha = hashlib.sha256(dataset.encode("utf-8")).hexdigest()

    report = {
        "schema_version": 1,
        "model_key": model_name,
        "base_model": "microsoft/trocr-base-handwritten",
        "dataset": dataset,
        "manifest_sha256": manifest_sha,
        "split": "test",
        "sample_count": int(sample_count),
        "metrics": {
            "cer": float(cer),
            "wer": float(wer),
            "exact_match": float(exact_match),
        },
        "evaluated_at": evaluated_at,
        "weights_file": weights_file,
        "weights_sha256": weights_sha,
    }

    report_path = os.path.join(model_dir, "evaluation-report.json")
    with open(report_path, "w", encoding="utf-8") as out:
        json.dump(report, out, indent=2)

    print(f"Successfully generated evaluation report at: {report_path}")
    return report_path


def main():
    parser = argparse.ArgumentParser(
        description="Rebuild a CRMS evaluation-report.json from numbers you measured. Measures nothing itself."
    )
    parser.add_argument("--model", required=True, help="Model folder name under ml/models/, e.g. 'base'")
    parser.add_argument("--dataset", required=True, help="Name of the dataset the test split came from")
    parser.add_argument("--samples", type=int, required=True, help="Number of test samples that were evaluated")
    parser.add_argument("--cer", type=float, required=True, help="Character Error Rate as a decimal (0.0003 = 0.03%%)")
    parser.add_argument("--wer", type=float, required=True, help="Word Error Rate as a decimal")
    parser.add_argument("--exact", type=float, required=True, help="Exact-match accuracy as a decimal (0-1)")
    args = parser.parse_args()

    try:
        regenerate(
            model_name=args.model,
            dataset=args.dataset,
            sample_count=args.samples,
            cer=args.cer,
            wer=args.wer,
            exact_match=args.exact,
        )
    except Exception as exc:
        print(f"Error: {exc}", file=sys.stderr)
        sys.exit(1)


if __name__ == "__main__":
    main()
