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

Rows whose label is empty or `UNREADABLE` are skipped by training, so they are
counted separately in the validation report rather than silently dropped.

This module is deliberately import-cheap: no torch, no transformers. The API
imports it on every /datasets call and must stay responsive while the GPU is busy.
"""

import csv
import os
import re
import shutil
import zipfile

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

# Validation lists are capped so a broken 10k-row manifest returns a report
# instead of a several-megabyte JSON body.
MAX_REPORTED = 25


class DatasetError(Exception):
    """A dataset is missing, malformed, or the name is not allowed."""


# ------------------------------------------------------------------ name safety

def sanitise_name(raw):
    """Fold a user-supplied name into a safe single path segment."""
    name = re.sub(r"[^A-Za-z0-9._-]+", "-", (raw or "").strip()).strip("-._")
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


# --------------------------------------------------------------------- reading

def _read_manifest(root):
    """Manifest rows as dicts. Missing file yields an empty list."""
    path = os.path.join(root, MANIFEST_NAME)
    if not os.path.isfile(path):
        return []

    # newline="" per the csv module's contract; utf-8-sig strips a BOM written
    # by Excel, which would otherwise corrupt the first column name.
    with open(path, "r", encoding="utf-8-sig", newline="") as fh:
        return [row for row in csv.DictReader(fh)]


def _images_in(root, split):
    directory = os.path.join(root, split)
    if not os.path.isdir(directory):
        return []
    return sorted(
        f for f in os.listdir(directory)
        if f.lower().endswith(IMAGE_EXTENSIONS)
        and os.path.isfile(os.path.join(directory, f))
    )


def _directory_size(root):
    total = 0
    for base, _dirs, files in os.walk(root):
        for f in files:
            try:
                total += os.path.getsize(os.path.join(base, f))
            except OSError:
                pass
    return total


def _label_of(row):
    return str(row.get("label") or "").strip()


def is_usable(label):
    """Training skips empty and UNREADABLE labels."""
    return label != "" and label.upper() != UNREADABLE


def describe(name):
    """Summary of one dataset: per-split counts and total size on disk."""
    root = dataset_path(name)
    rows = _read_manifest(root)
    # The name callers must use, which is not always the folder's own basename:
    # the legacy ml/dataset/ folder is addressed as 'default'.
    addressable = sanitise_name(name)

    counts = {split: len(_images_in(root, split)) for split in SPLITS}
    usable = {split: 0 for split in SPLITS}
    for row in rows:
        split = str(row.get("split") or "").strip().lower()
        if split in usable and is_usable(_label_of(row)):
            usable[split] += 1

    return {
        "name": addressable,
        "folder": os.path.basename(root),
        "images": counts,
        "usable": usable,
        "total_images": sum(counts.values()),
        "manifest_rows": len(rows),
        "has_manifest": os.path.isfile(os.path.join(root, MANIFEST_NAME)),
        "size_bytes": _directory_size(root),
    }


def list_datasets():
    """Every dataset under ml/datasets/, plus the legacy folder if it holds one."""
    names = []
    if os.path.isdir(DATASETS_DIR):
        names = sorted(
            n for n in os.listdir(DATASETS_DIR)
            if os.path.isdir(os.path.join(DATASETS_DIR, n))
            and not n.endswith(".incoming")
        )

    # dataset_path() resolves 'default' to ml/dataset/ when nothing has been
    # migrated yet, so list it under the name callers actually have to use.
    if DEFAULT_DATASET not in names and has_legacy_dataset():
        names.append(DEFAULT_DATASET)

    out = []
    for name in names:
        try:
            out.append(describe(name))
        except DatasetError:
            continue
    return out


# ------------------------------------------------------------------ validation

def validate(name):
    """Pre-training sanity report.

    A manifest pointing at missing files fails deep into an epoch after hours of
    GPU time, so this runs before a dataset is offered for training."""
    root = dataset_path(name)
    rows = _read_manifest(root)

    report = {
        "name": sanitise_name(name),
        "folder": os.path.basename(root),
        "ok": True,
        "errors": [],
        "warnings": [],
        "manifest_rows": len(rows),
        "split_distribution": {split: 0 for split in SPLITS},
        "usable": {split: 0 for split in SPLITS},
        "empty_labels": 0,
        "unreadable_labels": 0,
        "missing_images": [],
        "missing_image_count": 0,
        "orphan_images": [],
        "orphan_image_count": 0,
        "unknown_splits": [],
    }

    if not os.path.isfile(os.path.join(root, MANIFEST_NAME)):
        report["ok"] = False
        report["errors"].append(f"{MANIFEST_NAME} is missing.")
        return report

    if rows:
        missing_columns = {"filename", "label", "split"} - set(rows[0].keys())
        if missing_columns:
            report["ok"] = False
            report["errors"].append(
                "Manifest is missing column(s): " + ", ".join(sorted(missing_columns))
            )
            return report
    else:
        report["ok"] = False
        report["errors"].append("Manifest has no rows.")
        return report

    # Rows -> files
    referenced = {split: set() for split in SPLITS}
    unknown = set()

    for row in rows:
        filename = str(row.get("filename") or "").strip()
        split = str(row.get("split") or "").strip().lower()
        label = _label_of(row)

        if split not in SPLITS:
            unknown.add(split or "(blank)")
            continue

        report["split_distribution"][split] += 1

        if label == "":
            report["empty_labels"] += 1
        elif label.upper() == UNREADABLE:
            report["unreadable_labels"] += 1
        else:
            report["usable"][split] += 1

        if not filename:
            report["missing_image_count"] += 1
            continue

        referenced[split].add(filename)

        if not os.path.isfile(os.path.join(root, split, filename)):
            report["missing_image_count"] += 1
            if len(report["missing_images"]) < MAX_REPORTED:
                report["missing_images"].append(f"{split}/{filename}")

    report["unknown_splits"] = sorted(unknown)

    # Files -> rows
    for split in SPLITS:
        for filename in _images_in(root, split):
            if filename not in referenced[split]:
                report["orphan_image_count"] += 1
                if len(report["orphan_images"]) < MAX_REPORTED:
                    report["orphan_images"].append(f"{split}/{filename}")

    # A missing image is fatal; everything else is worth knowing but survivable.
    if report["missing_image_count"]:
        report["ok"] = False
        report["errors"].append(
            f"{report['missing_image_count']} manifest row(s) point at an image that is not on disk."
        )

    if report["usable"]["train"] == 0:
        report["ok"] = False
        report["errors"].append("No usable training rows (every label is empty or UNREADABLE).")

    if report["usable"]["val"] == 0:
        report["warnings"].append(
            "No usable validation rows. Training will run but cannot pick a best epoch."
        )

    if report["orphan_image_count"]:
        report["warnings"].append(
            f"{report['orphan_image_count']} image(s) have no manifest row and will be ignored."
        )

    if report["empty_labels"] or report["unreadable_labels"]:
        report["warnings"].append(
            f"{report['empty_labels']} empty and {report['unreadable_labels']} "
            f"{UNREADABLE} label(s) will be skipped."
        )

    if report["unknown_splits"]:
        report["warnings"].append(
            "Rows with an unrecognised split were ignored: "
            + ", ".join(report["unknown_splits"])
        )

    return report


# --------------------------------------------------------------- create/delete

def _safe_extract(archive, destination):
    """Extract a zip, refusing any member that escapes the destination.

    The original two-pass version called infolist() to check every path, then
    extractall() to write — loading every ZipInfo into memory first. For a
    100 GB archive with hundreds of thousands of entries that list can itself
    consume gigabytes of RAM. This version checks and extracts each member in
    a single pass so only one ZipInfo is live at a time.
    """
    root = os.path.realpath(destination)

    for member in archive.infolist():
        name = member.filename.replace("\\", "/")

        # Path safety: refuse anything that could escape the destination.
        if name.startswith("/") or ".." in name.split("/"):
            raise DatasetError(f"Refusing to extract unsafe path: {member.filename}")

        target = os.path.realpath(os.path.join(destination, name))
        if not target.startswith(root + os.sep) and target != root:
            raise DatasetError(f"Refusing to extract outside the dataset: {member.filename}")

        # Directories are created implicitly by extractall; skip them explicitly
        # here so we never try to open a directory as a file.
        if member.is_dir():
            os.makedirs(target, exist_ok=True)
            continue

        os.makedirs(os.path.dirname(target), exist_ok=True)

        # Stream the compressed data directly to disk — never read the whole
        # entry into memory.
        with archive.open(member) as src, open(target, "wb") as dst:
            shutil.copyfileobj(src, dst, 256 * 1024)  # 256 KB copy buffer


def _find_manifest_root(base):
    """Locate the folder holding manifest.csv, tolerating one wrapping directory.

    Zipping a folder in Explorer nests everything one level down, which is the
    single most common shape of an uploaded dataset."""
    if os.path.isfile(os.path.join(base, MANIFEST_NAME)):
        return base

    for entry in sorted(os.listdir(base)):
        candidate = os.path.join(base, entry)
        if os.path.isdir(candidate) and os.path.isfile(os.path.join(candidate, MANIFEST_NAME)):
            return candidate

    raise DatasetError(f"The archive does not contain a {MANIFEST_NAME}.")


def _remove_install_artifact(path, ignore_errors=False):
    """Remove one owned installation path without following a symlink."""
    try:
        if os.path.islink(path) or (os.path.lexists(path) and not os.path.isdir(path)):
            os.remove(path)
        elif os.path.isdir(path):
            shutil.rmtree(path)
    except OSError:
        if not ignore_errors:
            raise


def _installation_paths(name):
    """Return anchored target/staging paths and reject any existing target."""
    safe = sanitise_name(name)
    target = dataset_path(safe, must_exist=False)
    staging = target + ".incoming"

    datasets_root = os.path.abspath(DATASETS_DIR)
    if (
        os.path.dirname(os.path.abspath(target)) != datasets_root
        or os.path.dirname(os.path.abspath(staging)) != datasets_root
    ):
        raise DatasetError("Refusing to install outside the datasets folder.")

    if os.path.lexists(target):
        raise DatasetError(f"A dataset named '{safe}' already exists.")

    return safe, target, staging


def _commit_install(safe, source, target):
    """Move a staged tree into place and roll it back if inspection fails."""
    if os.path.lexists(target):
        raise DatasetError(f"A dataset named '{safe}' already exists.")

    installed = False
    try:
        shutil.move(source, target)
        installed = True
        return {"name": safe, "summary": describe(safe), "validation": validate(safe)}
    except Exception:
        if installed:
            _remove_install_artifact(target, ignore_errors=True)
        raise


def create_from_zip(name, zip_path):
    """Unpack an uploaded zip into ml/datasets/<name>/ and validate it.

    Disk requirement at peak: the zip itself sits in Laravel's staging area
    (already on disk by the time this is called), FastAPI writes a second copy
    to a temp file, and then the extracted tree is written alongside. For a
    100 GB zip that can reach 300 GB of temporary disk use. The caller's
    check_disk_space (in the training validator) guards training, but upload
    itself has no such guard — we just let the OS error if disk runs out,
    which surfaces as a clear 500 rather than a silent truncation.
    """
    safe, target, staging = _installation_paths(name)

    if not zipfile.is_zipfile(zip_path):
        raise DatasetError("The upload is not a readable zip archive.")

    os.makedirs(DATASETS_DIR, exist_ok=True)
    _remove_install_artifact(staging)
    os.makedirs(staging)

    try:
        with zipfile.ZipFile(zip_path) as archive:
            _safe_extract(archive, staging)

        source = _find_manifest_root(staging)
        return _commit_install(safe, source, target)
    finally:
        _remove_install_artifact(staging, ignore_errors=True)


def _assert_regular_directory_tree(root):
    """Refuse links and special files before copying an untrusted tree."""
    resolved_root = os.path.realpath(root)

    for base, directories, files in os.walk(root, followlinks=False):
        for entry in directories + files:
            path = os.path.join(base, entry)
            if os.path.islink(path):
                raise DatasetError(f"The dataset directory contains a symbolic link: {entry}")

            resolved = os.path.realpath(path)
            try:
                inside_root = os.path.commonpath((resolved_root, resolved)) == resolved_root
            except ValueError:
                inside_root = False
            if not inside_root:
                raise DatasetError("Refusing to copy a file outside the dataset directory.")

        for filename in files:
            path = os.path.join(base, filename)
            if not os.path.isfile(path):
                raise DatasetError(f"The dataset directory contains a non-regular file: {filename}")


def create_from_directory(name, source):
    """Install a staged directory upload under ml/datasets/<name>/ safely.

    The source may contain the dataset directly or inside one wrapping folder,
    matching create_from_zip's Explorer-friendly manifest-root handling. The
    source remains caller-owned: this function copies it into the target's
    incoming path before committing the completed dataset.
    """
    safe, target, staging = _installation_paths(name)

    source = os.fspath(source)
    if not os.path.isdir(source) or os.path.islink(source):
        raise DatasetError("The uploaded dataset directory is not readable.")

    manifest_root = _find_manifest_root(source)
    if os.path.islink(manifest_root):
        raise DatasetError("The uploaded dataset directory cannot be a symbolic link.")

    source_root = os.path.realpath(source)
    resolved_manifest_root = os.path.realpath(manifest_root)
    try:
        inside_source = os.path.commonpath((source_root, resolved_manifest_root)) == source_root
    except ValueError:
        inside_source = False
    if not inside_source:
        raise DatasetError("Refusing to install a dataset outside the uploaded directory.")

    _assert_regular_directory_tree(manifest_root)

    os.makedirs(DATASETS_DIR, exist_ok=True)
    _remove_install_artifact(staging)

    try:
        shutil.copytree(manifest_root, staging)
        return _commit_install(safe, staging, target)
    finally:
        _remove_install_artifact(staging, ignore_errors=True)


def delete_dataset(name):
    """Destructive: removes the dataset folder and every image in it."""
    path = dataset_path(name)

    if os.path.realpath(path) == os.path.realpath(LEGACY_DATASET_DIR):
        raise DatasetError(
            "The legacy ml/dataset folder is not managed here. Move it under "
            "ml/datasets/<name>/ first."
        )

    shutil.rmtree(path)
    return os.path.basename(path)
