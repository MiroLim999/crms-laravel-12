"""
dataset_registry.py
Named training datasets under ml/datasets/<name>/.

Named `dataset_registry`, not `datasets`, on purpose: ml/ goes on sys.path so the
scripts can import each other, and a module called `datasets.py` there would
shadow Hugging Face's `datasets` package for everything downstream.

Expected layout:

    ml/datasets/<name>/
        manifest.csv        columns: filename,label,split,source
        train/  val/  test/

Rows whose label is empty or `UNREADABLE` are skipped by training and
evaluation (see is_usable).

The training and evaluation scripts use this module to find a dataset by name.
The OCR service imports it too, for sanitise_name() on model names, so it stays
import-cheap: no torch, no transformers.
"""

import os
import re

ML_ROOT = os.path.dirname(os.path.abspath(__file__))
DATASETS_DIR = os.path.join(ML_ROOT, "datasets")

# The pre-migration single-dataset folder. Still honoured as a fallback so an
# existing checkout keeps working after the move to named datasets.
LEGACY_DATASET_DIR = os.path.join(ML_ROOT, "dataset")

DEFAULT_DATASET = "default"

SPLITS = ("train", "val", "test")
IMAGE_EXTENSIONS = (".png", ".jpg", ".jpeg", ".bmp", ".tiff", ".tif")

MANIFEST_NAME = "manifest.csv"
UNREADABLE = "UNREADABLE"


class DatasetError(Exception):
    """A dataset is missing, or the name is not allowed."""


# ------------------------------------------------------------------ name safety

def sanitise_name(raw):
    """Fold a user-supplied name into a safe single path segment."""
    name = re.sub(r"[^\w\s._-]", "", (raw or "").strip()).strip(". ")
    name = re.sub(r"\s+", " ", name)
    if not name:
        raise DatasetError("That dataset name is not allowed.")
    return name


def dataset_path(name, must_exist=True):
    """Absolute path of a dataset, refusing anything outside DATASETS_DIR.

    Mirrors the model-folder guards in api/main.py: the resolved directory's
    parent must be DATASETS_DIR, so no name can reach a sibling folder."""
    safe = sanitise_name(name)
    path = os.path.join(DATASETS_DIR, safe)

    root = os.path.realpath(DATASETS_DIR)
    resolved = os.path.realpath(path)
    if os.path.dirname(resolved) != root:
        raise DatasetError("Refusing to touch anything outside the datasets folder.")

    if must_exist and not os.path.isdir(path):
        # One concession to the pre-migration layout, and only when that folder
        # holds a real dataset. An empty ml/dataset/ is not worth surfacing.
        if safe == DEFAULT_DATASET and has_legacy_dataset():
            return LEGACY_DATASET_DIR
        raise DatasetError(f"Dataset '{safe}' was not found.")

    return path


def has_legacy_dataset():
    return os.path.isfile(os.path.join(LEGACY_DATASET_DIR, MANIFEST_NAME))


def resolve_paths(name=None, split="train"):
    """Manifest and image-directory paths for a dataset split.

    Used by the training and evaluation scripts so a caller only has to name a
    dataset, not spell out three paths."""
    root = dataset_path(name or DEFAULT_DATASET)
    return {
        "root": root,
        "manifest": os.path.join(root, MANIFEST_NAME),
        "images": os.path.join(root, split),
    }


# ---------------------------------------------------------------------- labels

def is_usable(label):
    """Training skips empty and UNREADABLE labels."""
    return label != "" and label.upper() != UNREADABLE
