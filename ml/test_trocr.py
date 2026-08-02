r"""
test_trocr.py
Evaluates the **base**, un-fine-tuned TrOCR model so its CER / WER / exact-match
can be compared against a fine-tuned one.

This is a thin wrapper over `test_finetuned.run_evaluation(model="base", ...)`.

It used to carry its own copy of the load / generate / decode / score loop, which
meant two code paths could disagree about what a number meant - and this copy had
already drifted: it read the pre-migration `ml/dataset/` folder directly, ignored
the manifest's `split` column, and generated with `max_new_tokens=64` where
everything else uses 32. A base-model score produced under different settings is
not comparable with the fine-tuned score it exists to be compared against.

  CLI         python ml\test_trocr.py --dataset default --split test
  In-process  from test_finetuned import run_evaluation
              run_evaluation(model="base", dataset="default", split="test")
"""

import argparse

# Quiets the HF stack. Must precede torch/transformers.
import hf_quiet  # noqa: F401

import dataset_registry as ds
from metrics import print_metrics
from test_finetuned import (
    BASE_MODEL_KEY,
    BATCH_SIZE,
    DEFAULT_DATASET,
    DEFAULT_SPLIT,
    EvaluationError,
    run_evaluation,
)

# Re-exported for anything that imported these from here.
IMAGE_EXTENSIONS = ds.IMAGE_EXTENSIONS


def main(argv=None):
    parser = argparse.ArgumentParser(
        description="Evaluate the base (not fine-tuned) TrOCR model on a dataset split."
    )
    parser.add_argument("--dataset", default=DEFAULT_DATASET)
    parser.add_argument("--split", default=DEFAULT_SPLIT, choices=list(ds.SPLITS))
    parser.add_argument("--limit", type=int, default=None)
    parser.add_argument("--batch-size", type=int, default=BATCH_SIZE)
    parser.add_argument("--no-chart", action="store_true")
    args = parser.parse_args(argv)

    print("=" * 60)
    print("TrOCR BASE MODEL - EVALUATION")
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
            model=BASE_MODEL_KEY,
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
        title="BASE MODEL - EVALUATION METRICS",
    )
    print("\nCompare against a fine-tuned model with:")
    print(f"  python ml\\test_finetuned.py --model <name> --split {args.split}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
