"""
runners.py
Glue between the job manager and the ML scripts.

The scripts live in ml/ and import each other by bare name (`import metrics`),
which only resolves if ml/ is on sys.path. The service is launched as
`uvicorn ml.api.main:app` from the repo root, so this module puts ml/ on the path
before importing them.

Heavy imports (train_trocr pulls in torch and transformers) happen inside the
runner functions, not at module import, so /health and /models stay fast.

Every runner has the same shape: `runner(job)`, reporting through `job.report()`
and `job.log()`, polling `job.cancelled()`, and returning a dict whose `metrics`
key the job manager lifts onto `job.metrics`.
"""

import os
import sys

ML_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if ML_ROOT not in sys.path:
    sys.path.insert(0, ML_ROOT)

import dataset_registry as ds  # noqa: E402  (must follow the sys.path edit)
import jobs  # noqa: E402


# --------------------------------------------------------------------- training

# Hyperparameters a caller may set. Anything else in the request body is ignored
# rather than forwarded blindly into run_training().
TRAINING_FIELDS = (
    "dataset", "output_name", "base_model", "epochs", "batch_size",
    "learning_rate", "max_label_length", "num_workers",
    "train_subset", "val_subset",
)

EVALUATION_FIELDS = ("model", "dataset", "split", "limit", "batch_size")


def training_defaults():
    """The script's own defaults, so the UI can pre-fill the form from one source."""
    import train_trocr

    return {
        "epochs": train_trocr.EPOCHS,
        "batch_size": train_trocr.BATCH_SIZE,
        "learning_rate": train_trocr.LEARNING_RATE,
        "max_label_length": train_trocr.MAX_LABEL_LENGTH,
        "num_workers": train_trocr.NUM_WORKERS,
        "train_subset": train_trocr.TRAIN_SUBSET,
        "val_subset": train_trocr.VAL_SUBSET,
        "base_model": train_trocr.MODEL_NAME,
        "output_name": train_trocr.DEFAULT_OUTPUT_NAME,
    }


def validate_training_config(config, available_models=None):
    """Reject a config that would waste GPU time before the job is queued.

    `available_models` is the service's model keys, used to catch a base model the
    service cannot see. Returns the cleaned config. Raises ValueError with a
    message meant for the Super Admin, which the API surfaces as a 400."""
    import train_trocr

    clean = {k: v for k, v in (config or {}).items() if k in TRAINING_FIELDS and v is not None}

    # Store the sanitised name, so the "is this dataset in use by a job?" check in
    # delete_dataset compares like with like.
    dataset = ds.sanitise_name(clean.get("dataset") or ds.DEFAULT_DATASET)
    clean["dataset"] = dataset

    base_model = clean.get("base_model")
    if base_model and available_models is not None:
        # Left alone when the caller names a Hugging Face repo: a local CLI run may
        # legitimately fine-tune from the hub. Anything else has to be a folder the
        # service can actually see, or the run dies on the first from_pretrained.
        known = set(available_models) | {train_trocr.MODEL_NAME}
        looks_like_hub_id = "/" in base_model
        on_disk = os.path.isdir(os.path.join(train_trocr.MODELS_DIR, base_model))

        if base_model not in known and not on_disk and not looks_like_hub_id:
            raise ValueError(
                f"The service cannot see a base model named '{base_model}'. "
                "Pick one from the model list."
            )

    # A manifest pointing at missing files fails deep into an epoch, hours in.
    report = ds.validate(dataset)
    if not report["ok"]:
        raise ValueError(
            f"Dataset '{dataset}' is not usable: " + " ".join(report["errors"])
        )

    output_name = ds.sanitise_name(clean.get("output_name") or train_trocr.DEFAULT_OUTPUT_NAME)
    clean["output_name"] = output_name

    target = os.path.join(train_trocr.MODELS_DIR, output_name)
    if os.path.isdir(target) and os.listdir(target):
        raise ValueError(
            f"A model named '{output_name}' already exists. Pick another output name."
        )

    for field, minimum in (("epochs", 1), ("batch_size", 1), ("max_label_length", 1),
                           ("num_workers", 0)):
        if field in clean:
            clean[field] = int(clean[field])
            if clean[field] < minimum:
                raise ValueError(f"{field.replace('_', ' ')} must be at least {minimum}.")

    if "learning_rate" in clean:
        clean["learning_rate"] = float(clean["learning_rate"])
        if not 0 < clean["learning_rate"] < 1:
            raise ValueError("Learning rate must be between 0 and 1.")

    for field in ("train_subset", "val_subset"):
        if clean.get(field) is not None:
            clean[field] = int(clean[field])
            if clean[field] < 1:
                clean[field] = None

    # Fails now with a clear message rather than after the first epoch.
    train_trocr.check_disk_space(target)

    return clean


def training_runner(config):
    """Build a `runner(job)` closure for a training run."""
    def run(job):
        import train_trocr

        def progress(**fields):
            job.report(**fields)

        result = train_trocr.run_training(
            progress=progress,
            should_cancel=job.cancelled,
            log=job.log,
            verbose=False,
            **config,
        )

        if result.get("cancelled"):
            # Surfaces whether a usable checkpoint survived the cancel.
            job.log(
                "Checkpoint kept." if result.get("checkpoint_kept")
                else "No checkpoint was written; the output folder was removed."
            )
            raise jobs.JobCancelled()

        job.log(f"Model '{result['output_name']}' is now selectable under ml/models/.")
        return result

    return run


# ------------------------------------------------------------------- evaluation

def validate_evaluation_config(config, available_models):
    """Clean an evaluation request. `available_models` is the service's model keys."""
    clean = {k: v for k, v in (config or {}).items() if k in EVALUATION_FIELDS and v is not None}

    model = clean.get("model")
    if not model:
        raise ValueError("Pick a model to evaluate.")
    if model not in available_models:
        raise ValueError(f"The service cannot see a model named '{model}'.")

    dataset = ds.sanitise_name(clean.get("dataset") or ds.DEFAULT_DATASET)
    clean["dataset"] = dataset

    split = (clean.get("split") or "test").lower()
    if split not in ds.SPLITS:
        raise ValueError(f"Unknown split '{split}'.")
    clean["split"] = split

    if clean.get("batch_size") is not None:
        clean["batch_size"] = int(clean["batch_size"])
        if clean["batch_size"] < 1:
            raise ValueError("Evaluation batch size must be at least 1.")

    # Existence only. Unlike training, a partly broken manifest just means fewer
    # samples, and an evaluation costs minutes rather than hours.
    ds.dataset_path(dataset)

    if clean.get("limit") is not None:
        clean["limit"] = int(clean["limit"])
        if clean["limit"] < 1:
            clean["limit"] = None

    return clean


def evaluation_runner(config, loader=None):
    """Build a `runner(job)` closure for an evaluation run.

    `loader` is the service's cached model loader, so evaluating the model Staff
    are already scanning with does not load a second 1.3 GB copy into VRAM."""
    def run(job):
        import test_finetuned

        result = test_finetuned.run_evaluation(
            loader=loader,
            progress=lambda **fields: job.report(**fields),
            should_cancel=job.cancelled,
            log=job.log,
            **config,
        )

        if result.get("cancelled"):
            raise jobs.JobCancelled()

        return result

    return run
