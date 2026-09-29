r"""
line_markers.py
Outline every handwritten line on an aligned register page, and crop along the
outline.

Fixed field rectangles fail on real register books: handwriting drifts across
the printed ruled lines, and tall capitals and letter tails reach into the rows
above and below. A rectangle therefore cuts letters off and picks up a
neighbour's strokes. This module replaces the rectangle with a polygon traced
from the ink that belongs to each line.

How a page is processed (process_page):

  1. Binarise the page and find the printed rules. The template's ruled_ys and
     column edges are snapped to the rules actually printed on this page, so a
     page that sits a little higher, lower, or skewed still gets exact rows.
  2. Ask the line detector (kraken_lines, the only place Kraken is called) for
     baselines. Kraken often merges neighbouring cells into one line, so lines
     are split at the column rules, in the whitespace gap nearest the rule.
  3. Assign each line to a row. Rows are matched per column in reading order
     against where this page's writing usually sits in a row, so a line that
     drifts across a rule still lands in its own row.
  4. Give every ink stroke to exactly one line. A stroke that enters a line's
     x-height band belongs to that line, even when its capital or tail reaches
     into the next row; strokes of other lines are cut out of the outline.
  5. Cells with ink but no detected line (single letters, entry numbers) get a
     line built from that ink.
  6. Crop each line along its polygon (crop_line) and write overlay.png.

Lines are flagged for review rather than guessed:
  no_row       the line sits between rows and cannot be placed with confidence
  shared_cell  two or more stacked lines ended up in one column/row cell

Legacy rectangle fields are carried through as four-point polygons and cropped
by the same crop_line, so older templates keep working unchanged.

Runs in its own environment (ml/.venv-kraken, see setup_kraken.ps1) because
Kraken needs a newer torch than the TrOCR service. Everything is local: the
default Kraken model ships inside the kraken wheel.

CLI (Laravel calls these):
  python ml/line_markers.py detect --page page.png --geometry geometry.json --out DIR
  python ml/line_markers.py process --page page.png --geometry geometry.json --out DIR
  python ml/line_markers.py crop --page page.png --polygon polygon.json --out crop.png [--lines DIR/lines.json]
  python ml/line_markers.py grid --page sample.png
"""

import argparse
import json
import math
import os
import sys
import time

import numpy as np
from PIL import Image, ImageDraw

FLAG_NO_ROW = "no_row"
FLAG_SHARED_CELL = "shared_cell"

# Things about the grid as a whole that Staff should look at (result["notes"]).
NOTE_ROWS_UNEVEN = "rows_uneven"
NOTE_LINES_BELOW = "lines_below_grid"
NOTE_LINES_ABOVE = "lines_above_grid"
NOTE_GRID_MOVED = "grid_moved"
NOTE_ROWS_DO_NOT_FIT = "rows_do_not_fit"

SOURCE_DETECTED = "kraken"
SOURCE_INK = "ink"
SOURCE_TEMPLATE = "template"
# A written line found inside a rectangle field by Detect.
SOURCE_FIELD = "field"

# Assigning a second line to an already occupied row costs as much as moving a
# line about 0.6 of a row height away from where writing usually sits.
SHARE_PENALTY = 0.35

# A line further than this (in row heights) from its row's usual writing
# position is flagged no_row instead of being placed. Halfway between two rows
# is 0.5; on the sample registers 99% of lines sit within 0.1, so 0.35 leaves
# room for real drift while still catching lines that straddle two rows.
NO_ROW_DISTANCE = 0.35

# How far the row fit may move the table's first row line from where Staff put
# it, in row heights. Evenly ruled rows repeat, so a grid slid one row up or
# down matches the printed rules exactly as well as the right one; rule
# matching cannot tell them apart. Staff's placement is the only evidence, so
# it is trusted to within half a row. (Detect, which fits the template to the
# page itself, uses the smaller FITTED_ROW_REACH on top of that fit.)
ROW_REACH = 0.5
FITTED_ROW_REACH = 0.3

# How much the row spacing may differ from the template's, either way. A page
# scanned at another scale, or a template drawn on a slightly different sample,
# is a few percent off; 10% covers that without inventing a row.
ROW_STRETCH = 0.10

# A ledger template over a page that is not that ledger (22 template rows over a
# list of diseases): the writing does not sit in the rows. On every real ledger
# page it does - the baselines keep the same height within their rows (circular
# concentration 0.81 to 0.99) and follow one another a row apart (1.0 to 1.1
# rows). Free text under the template scores 0.07 to 0.26, 2.2 to 2.7 rows apart.
# Below these, the columns are read as free text, line by line, like a field.
ROWS_MIN_LINES = 6
ROWS_SCATTER = 0.5
ROWS_SPARSE = 1.5

# A band above the grid at most this many rows tall could be a row of entries.
# Headers on the real pages measure 1.25 to 1.45 rows, so those stay quiet.
ROW_SIZED_BAND = 1.12

# A row band whose height, against the template's, differs from the typical
# band by more than this share was distorted by the fit (a header taken for a
# row, a rule missing from the page). Reported to Staff, not corrected.
UNEVEN_BAND = 0.20

# Masked crops are filled with the paper's own tone around the line, with a
# margin. Measured with the project's fine-tuned TrOCR on two real register
# pages (440 cells): white fill with a 4 px margin read at 3.1% CER, local paper
# tone with an 8 px margin at 2.0%. TrOCR reads a line on paper, not a line
# pasted onto a white card.
CROP_PADDING = 8
CROP_FILL = "paper"


# ============================================================ line detector

def detector_device(requested=None):
    """The device Kraken runs on: "cuda" when a GPU can be used, else "cpu".

    LINE_MARKERS_DEVICE chooses: "auto" (the default) uses the GPU when this
    environment's PyTorch was built with CUDA and a card is present, "cuda"
    asks for it, "cpu" never uses it. The setting lives in Laravel's .env.
    """
    requested = (requested or os.environ.get("LINE_MARKERS_DEVICE") or "auto").strip().lower()
    if requested == "cpu":
        return "cpu"
    try:
        import torch

        if torch.cuda.is_available():
            return "cuda"
    except ImportError:  # pragma: no cover - depends on the environment
        pass
    return "cpu"


def kraken_lines(image, device=None):
    """Baselines and boundary polygons for every text line Kraken finds.

    This is the single place Kraken is called. Swapping the detector means
    replacing this function with another that returns the same shape:

        [{"baseline": [[x, y], ...], "boundary": [[x, y], ...]}, ...]

    in page pixels. Imported lazily so the rest of this module (and the
    rectangle path used by older templates) works without Kraken installed.

    Runs on the GPU when there is one (see detector_device). If the GPU
    fails - out of memory while TrOCR holds it, a driver hiccup - the page is
    segmented again on the CPU rather than failing the scan.
    """
    import warnings

    warnings.filterwarnings("ignore")
    try:
        from kraken import blla
    except ImportError as error:  # pragma: no cover - depends on the environment
        raise RuntimeError(
            "Kraken is not installed in this Python environment. "
            "Run ml\\setup_kraken.ps1, or point LINE_MARKERS_PYTHON at ml\\.venv-kraken."
        ) from error

    device = detector_device(device)
    try:
        segmentation = blla.segment(image.convert("RGB"), device=device)
    except RuntimeError as error:
        if device == "cpu":
            raise
        print(f"[line_markers] GPU line detection failed ({error}); using the CPU.", file=sys.stderr)
        device = "cpu (GPU failed)"
        segmentation = blla.segment(image.convert("RGB"), device="cpu")
    # Recorded in lines.json, so a page shows where its lines were found.
    kraken_lines.used_device = device
    lines = []
    for line in segmentation.lines:
        baseline = [[float(x), float(y)] for x, y in (line.baseline or [])]
        boundary = [[float(x), float(y)] for x, y in (line.boundary or [])]
        if len(baseline) >= 2 and len(boundary) >= 3:
            lines.append({"baseline": baseline, "boundary": boundary})
    return lines


# ============================================================ cropping

def crop_line(image, polygon, out_path=None, fill=CROP_FILL, padding=CROP_PADDING):
    """Crop `image` to `polygon`, blanking everything outside it.

    The one crop function for every path: detected lines, legacy rectangles
    (four-point polygons), manual corrections, and therefore the training
    export, which copies these exact files. Returns the crop and its bounding
    box [x, y, w, h] in page pixels.

    `fill` is an RGB tuple, or "paper" for the local paper tone (the 75th
    percentile of the region around the line, which is mostly paper).
    """
    if isinstance(image, str):
        image = Image.open(image)
    image = image.convert("RGB")

    points = [(float(x), float(y)) for x, y in polygon]
    if len(points) < 3:
        raise ValueError("A line outline needs at least three points.")

    width, height = image.size
    x0 = max(0, int(math.floor(min(p[0] for p in points))))
    y0 = max(0, int(math.floor(min(p[1] for p in points))))
    x1 = min(width, int(math.ceil(max(p[0] for p in points))) + 1)
    y1 = min(height, int(math.ceil(max(p[1] for p in points))) + 1)
    if x1 - x0 < 2 or y1 - y0 < 2:
        raise ValueError("That outline is too small, or lies outside the page.")

    region = image.crop((x0, y0, x1, y1))
    if fill == "paper":
        pixels = np.asarray(region).reshape(-1, 3)
        fill = tuple(int(v) for v in np.percentile(pixels, 75, axis=0))
    mask = Image.new("L", region.size, 0)
    ImageDraw.Draw(mask).polygon([(x - x0, y - y0) for x, y in points], fill=255, outline=255)

    out = Image.new("RGB", (region.width + 2 * padding, region.height + 2 * padding), fill)
    out.paste(region, (padding, padding), mask)

    if out_path:
        os.makedirs(os.path.dirname(os.path.abspath(out_path)), exist_ok=True)
        out.save(out_path, "PNG")

    return out, [x0, y0, x1 - x0, y1 - y0]


# ============================================================ geometry input

def _rect_polygon(x, y, w, h):
    return [[x, y], [x + w, y], [x + w, y + h], [x, y + h]]


def _turned_rect_polygon(x, y, w, h, degrees):
    """A rectangle turned clockwise (as seen on screen) about its centre.

    Corners in the order top left, top right, bottom right, bottom left of the
    rectangle itself, so the first edge runs along its top.
    """
    if not degrees:
        return _rect_polygon(x, y, w, h)
    cx, cy = x + w / 2, y + h / 2
    cos, sin = math.cos(math.radians(degrees)), math.sin(math.radians(degrees))
    return [
        [cx + dx * cos - dy * sin, cy + dx * sin + dy * cos]
        for dx, dy in ((-w / 2, -h / 2), (w / 2, -h / 2), (w / 2, h / 2), (-w / 2, h / 2))
    ]


def _polygon_turn(polygon):
    """(centre, width, height, degrees) of a turned four-corner rectangle."""
    (x0, y0), (x1, y1), _, (x3, y3) = [(float(x), float(y)) for x, y in polygon[:4]]
    centre = (sum(float(p[0]) for p in polygon[:4]) / 4, sum(float(p[1]) for p in polygon[:4]) / 4)
    return centre, math.hypot(x1 - x0, y1 - y0), math.hypot(x3 - x0, y3 - y0), math.degrees(math.atan2(y1 - y0, x1 - x0))


def crop_turned_box(image, polygon, out_path=None, fill=CROP_FILL, padding=CROP_PADDING):
    """Crop a turned rectangle so that it reads level.

    A tilted field marker holds tilted writing. Masking its outline, as
    crop_line does, would hand TrOCR the writing still on a slant; this turns
    the rectangle upright instead. Returns the crop and the bounding box
    [x, y, w, h] of the turned rectangle in page pixels, like crop_line.
    """
    if isinstance(image, str):
        image = Image.open(image)
    image = image.convert("RGB")
    (cx, cy), w, h, degrees = _polygon_turn(polygon)
    if w < 2 or h < 2:
        raise ValueError("That outline is too small, or lies outside the page.")

    xs = [float(p[0]) for p in polygon]
    ys = [float(p[1]) for p in polygon]
    width, height = image.size
    x0, y0 = max(0, int(math.floor(min(xs)))), max(0, int(math.floor(min(ys))))
    x1, y1 = min(width, int(math.ceil(max(xs))) + 1), min(height, int(math.ceil(max(ys))) + 1)
    if x1 - x0 < 2 or y1 - y0 < 2:
        raise ValueError("That outline is too small, or lies outside the page.")
    if fill == "paper":
        pixels = np.asarray(image.crop((x0, y0, x1, y1))).reshape(-1, 3)
        fill = tuple(int(v) for v in np.percentile(pixels, 75, axis=0))

    out_w, out_h = int(round(w)) + 2 * padding, int(round(h)) + 2 * padding
    cos, sin = math.cos(math.radians(degrees)), math.sin(math.radians(degrees))
    # Output pixel (u, v) comes from the page point centre + turn(u - W/2, v - H/2).
    coefficients = (
        cos, -sin, cx - cos * out_w / 2 + sin * out_h / 2,
        sin, cos, cy - sin * out_w / 2 - cos * out_h / 2,
    )
    out = image.transform((out_w, out_h), Image.AFFINE, coefficients, resample=Image.BICUBIC, fillcolor=fill)

    if out_path:
        os.makedirs(os.path.dirname(os.path.abspath(out_path)), exist_ok=True)
        out.save(out_path, "PNG")

    return out, [x0, y0, x1 - x0, y1 - y0]


def _page_geometry(geometry, width, height):
    """Convert the template geometry (page fractions) into page pixels."""
    columns = []
    for index, column in enumerate(geometry.get("columns") or []):
        x, y, w, h = [float(v) for v in column["box"]]
        columns.append({
            "index": index,
            "name": str(column.get("name") or f"Column {index + 1}"),
            "left": x * width,
            "right": (x + w) * width,
            "top": y * height,
            "bottom": (y + h) * height,
        })

    ruled = sorted(float(v) * height for v in (geometry.get("ruled_ys") or []))

    fields = []
    for index, field in enumerate(geometry.get("fields") or []):
        angle = float(field.get("angle") or 0.0)
        if field.get("polygon"):
            polygon = [[float(px) * width, float(py) * height] for px, py in field["polygon"]]
        else:
            x, y, w, h = [float(v) for v in field["box"]]
            polygon = _turned_rect_polygon(x * width, y * height, w * width, h * height, angle)
        fields.append({
            "index": index,
            "name": str(field.get("name") or f"Field {index + 1}"),
            "polygon": polygon,
            # A turned rectangle is cropped level (crop_turned_box).
            "turned": abs(angle) >= 0.05 and len(polygon) == 4,
            "person_group": field.get("person_group"),
            "person_field_order": field.get("person_field_order"),
        })

    return columns, ruled, fields


# ============================================================ ink and rules

def _ink_mask(gray):
    """Dark-on-light ink, robust to stained and mottled paper."""
    from scipy import ndimage
    from skimage.filters import threshold_sauvola

    window = max(15, (min(gray.shape) // 40) | 1)
    local = threshold_sauvola(gray, window_size=window, k=0.25)
    # Sauvola alone turns flat paper grain into speckle; also require real
    # contrast against the paper tone.
    paper = float(np.percentile(gray, 90))
    ink = (gray < local) & (gray < paper - 35)

    labels, count = ndimage.label(ink)
    if count:
        sizes = np.bincount(labels.ravel())
        small = sizes < 6
        small[0] = False
        ink[small[labels]] = False
    return ink


def _fit(u, v):
    """Least-squares v = a + b*u with one outlier-trimming pass."""
    b, a = np.polyfit(u, v, 1)
    residual = v - (a + b * u)
    keep = np.abs(residual) <= max(1.5, 2.5 * float(np.std(residual)))
    if 10 <= keep.sum() < len(u):
        b, a = np.polyfit(u[keep], v[keep], 1)
    return float(a), float(b)


def _rule_evidence(gray, axis, length, bridge=None):
    """Pixels that lie on long, thin, straight dark lines along one axis.

    Printed rules in old registers are often faint and one pixel wide, far
    lighter than the ink, so they are found from thin-line contrast rather
    than from the ink mask: a closing across the rule's direction erases a
    thin line, and the difference is the line. A pixel counts when most of a
    `length`-long window along the axis is also line.

    Where a rule crosses a rule of the other direction the thin-line contrast
    vanishes for a pixel or two, which would cut a rule inside a narrow
    column into pieces too short to count as print. `bridge` (the other
    direction's rule pixels) fills exactly those crossings.
    """
    from scipy import ndimage

    g = gray.astype(np.int16)
    background = ndimage.grey_closing(g, size=(9, 1) if axis == "h" else (1, 9))
    dark = (background - g) > 15
    if bridge is not None:
        dark |= bridge
    run = ndimage.uniform_filter1d(dark.astype(np.float32), size=length, axis=1 if axis == "h" else 0)
    return dark & (run >= 0.55)


def _rule_pixels(gray, length=60):
    """Horizontal and vertical rule evidence, each bridged across the other."""
    from scipy import ndimage

    grow = np.ones((3, 3), bool)
    vertical = _rule_evidence(gray, "v", length)
    horizontal = _rule_evidence(gray, "h", length, ndimage.binary_dilation(vertical, structure=grow))
    vertical = _rule_evidence(gray, "v", length, ndimage.binary_dilation(horizontal, structure=grow))
    return horizontal, vertical


def _find_rules(evidence, axis, min_span, max_thickness=None, writing=None, near=0.0):
    """Straight printed rules along one axis.

    Returns (rules, pixels). Rules are {"a", "b", "lo", "hi"} where the rule
    is v = a + b*u, u being x for horizontal rules and y for vertical ones,
    and [lo, hi] the extent along u. `pixels` marks every rule fragment long
    enough to be print rather than handwriting, including fragments too short
    or too bent to join a fitted rule; those still have to be erased.

    A printed rule is a hairline. On a small or low-resolution image, though,
    a row of lowercase letters is no taller than the thin-line test allows,
    and a written word passes for a rule. `max_thickness` rejects fragments
    thicker than that, unless they run across half the page, which only print
    does (page borders are thicker than the rules inside the table).

    Thickness alone does not settle it: the low joins of a cursive word
    ("itimate" in "Legitimate", 82 px long and 4 px thick on a real page) look
    like a stretch of printed rule, and erasing them cut the word to "Leg t".
    Such a stretch stands alone, inside a line of writing, away from every
    rule. So when `writing` (a mask of the detected lines of writing) is
    given, a fragment that joins no rule and lies mostly inside the writing
    is kept as ink, unless it runs within `near` pixels of a rule: a piece of
    a bent or broken rule stays with its rule and is erased.
    """
    from scipy import ndimage

    labels, _ = ndimage.label(evidence, structure=np.ones((3, 3), bool))
    pixels = np.zeros(evidence.shape, bool)
    extent = evidence.shape[1] if axis == "h" else evidence.shape[0]
    fragments = []
    for index, window in enumerate(ndimage.find_objects(labels), start=1):
        if window is None:
            continue
        mask = labels[window] == index
        ys, xs = np.nonzero(mask)
        ys = ys + window[0].start
        xs = xs + window[1].start
        u, v = (xs, ys) if axis == "h" else (ys, xs)
        span = u.max() - u.min() + 1
        if span < 80:
            continue
        if max_thickness is not None and span < 0.5 * extent:
            across = np.bincount(u - u.min())
            if float(np.median(across[across > 0])) > max_thickness:
                continue
        a, b = _fit(u.astype(float), v.astype(float))
        fragments.append({"a": a, "b": b, "lo": int(u.min()), "hi": int(u.max()), "u": u, "v": v,
                          "window": window, "mask": mask})

    # A rule broken by a crossing stroke, faded ink or a fold comes back as
    # several collinear fragments. Merge them and refit on all their pixels.
    fragments.sort(key=lambda f: f["a"] + f["b"] * (f["lo"] + f["hi"]) / 2)
    merged = []
    for fragment in fragments:
        if merged:
            last = merged[-1]
            probe = (max(last["lo"], fragment["lo"]) + min(last["hi"], fragment["hi"])) / 2
            if abs((last["a"] + last["b"] * probe) - (fragment["a"] + fragment["b"] * probe)) <= 4:
                last["u"] = np.concatenate([last["u"], fragment["u"]])
                last["v"] = np.concatenate([last["v"], fragment["v"]])
                last["a"], last["b"] = _fit(last["u"].astype(float), last["v"].astype(float))
                last["lo"] = min(last["lo"], fragment["lo"])
                last["hi"] = max(last["hi"], fragment["hi"])
                last["members"].append(fragment)
                continue
        merged.append(dict(fragment, members=[fragment]))

    rules = []
    loose = []
    for rule in merged:
        covered = len(np.unique(rule["u"]))
        if covered >= min_span:
            rules.append({"a": rule["a"], "b": rule["b"], "lo": rule["lo"], "hi": rule["hi"]})
            for member in rule["members"]:
                pixels[member["window"]] |= member["mask"]
        else:
            loose.extend(rule["members"])

    for fragment in loose:
        if writing is not None:
            inside = float(np.mean(writing[fragment["window"]][fragment["mask"]]))
            u_mid = (fragment["lo"] + fragment["hi"]) / 2
            v_mid = fragment["a"] + fragment["b"] * u_mid
            by_a_rule = any(abs(rule["a"] + rule["b"] * u_mid - v_mid) <= near
                            and rule["lo"] - near <= u_mid <= rule["hi"] + near for rule in rules)
            if inside >= 0.5 and not by_a_rule:
                continue  # the body of a written word, not print
        pixels[fragment["window"]] |= fragment["mask"]
    return rules, pixels


def _rule_band(h_pixels, v_pixels):
    """Where the printed rules are, widened to cover their faint edges.

    Built from the rule pixels themselves rather than from fitted straight
    lines: old pages curl, so a rule bends a pixel or two across the table and
    a straight fit would miss it at one end and cut into handwriting at the
    other.
    """
    from scipy import ndimage

    h_band = ndimage.binary_dilation(h_pixels, structure=np.ones((5, 7), bool))
    v_band = ndimage.binary_dilation(v_pixels, structure=np.ones((7, 5), bool))
    return h_band, v_band


def _remove_rules(ink, h_band, v_band):
    """Ink without the printed rules, keeping the strokes that meet a rule.

    Components are what decide which line a stroke belongs to. With the rules
    left in, every letter touching a rule would join one page-wide component.
    Erasing the whole band, though, would also erase the tip of a capital that
    ends on a rule and cut a tail that crosses one. So strokes are grown back
    into the band across the rule - up and down through horizontal rules,
    sideways through vertical ones - and never along it.
    """
    from scipy import ndimage

    band = h_band | v_band
    clean = ink & ~band
    across_h = np.ones((5, 1), bool)
    across_v = np.ones((1, 5), bool)
    grown = clean
    for _ in range(2):
        reach = (ndimage.binary_dilation(grown, structure=across_h) & h_band) \
            | (ndimage.binary_dilation(grown, structure=across_v) & v_band)
        grown = clean | (ink & reach)
    return grown


# ============================================================ grid refinement

class Rule:
    """A printed rule: v = a + b*u (y from x for rows, x from y for columns)."""

    __slots__ = ("a", "b", "matched")

    def __init__(self, a, b, matched):
        self.a = float(a)
        self.b = float(b)
        self.matched = matched

    def at(self, u):
        return self.a + self.b * u

    def to_json(self):
        return {"a": round(self.a, 3), "b": round(self.b, 6), "matched": self.matched}


def _global_fit(expected, detected, probe, reach, tolerance, stretch=0.04, pivot="mean"):
    """The shift and stretch that line the expected rules up with the printed ones.

    Found before any rule is snapped on its own, so a template aligned a
    little off, or dragged a little too tall or short, still matches every
    rule to its own printed line rather than to its neighbour.

    `stretch` is how far the spacing may change either way. `pivot` is the
    line the stretch is about: "mean" (the middle), or "first" for rows. Rows
    pivot on the first line because that is where Staff put the table's top:
    stretching about the middle couples the scale to a shift, so a template
    5% off in row height can only be matched by sliding a whole row, and a
    limit on how far the top may move (`reach`) would then rule out the right
    fit.
    """
    wanted = np.asarray(expected, float)
    if not detected or len(wanted) == 0:
        return wanted
    printed = np.array([rule["a"] + rule["b"] * probe for rule in detected])
    anchor = float(wanted[0]) if pivot == "first" else float(wanted.mean())
    best, best_score = wanted, -1.0
    for scale in np.arange(1.0 - stretch, 1.0 + stretch + 1e-6, 0.0025):
        scaled = anchor + (wanted - anchor) * scale
        for shift in np.arange(-reach, reach + 0.5, 1.0):
            moved = scaled + shift
            distances = np.abs(printed[None, :] - moved[:, None]).min(axis=1)
            score = float(np.clip(1.0 - distances / tolerance, 0.0, 1.0).sum())
            # Among equally good fits prefer the smallest correction.
            score -= abs(shift) / (20.0 * max(reach, 1.0)) + abs(scale - 1.0)
            if score > best_score:
                best, best_score = moved, score
    return best


def _snap(expected, detected, probe, tolerance, fallback_slope, reach=0.0, stretch=0.04, pivot="mean"):
    """Match expected rule positions to detected rules, one to one and in order.

    Unmatched positions keep their expected place shifted by the offset of
    their matched neighbours, so a faded rule does not throw off its row.
    """
    if reach > 0:
        expected = list(_global_fit(expected, detected, probe, reach, tolerance, stretch, pivot))
    candidates = []
    for i, value in enumerate(expected):
        for j, rule in enumerate(detected):
            distance = abs(rule["a"] + rule["b"] * probe - value)
            if distance <= tolerance:
                candidates.append((distance, i, j))
    candidates.sort()

    matched = {}
    used = set()
    for distance, i, j in candidates:
        if i in matched or j in used:
            continue
        matched[i] = j
        used.add(j)

    # Keep order: drop any match that would put rules out of sequence.
    order = sorted(matched)
    for k in range(1, len(order)):
        prev, cur = order[k - 1], order[k]
        if prev in matched and cur in matched:
            prev_value = detected[matched[prev]]["a"] + detected[matched[prev]]["b"] * probe
            cur_value = detected[matched[cur]]["a"] + detected[matched[cur]]["b"] * probe
            if cur_value <= prev_value:
                del matched[cur]

    slopes = [detected[j]["b"] for j in matched.values()]
    slope = float(np.median(slopes)) if slopes else fallback_slope
    known = sorted(matched)
    offsets = [detected[matched[i]]["a"] + detected[matched[i]]["b"] * probe - expected[i] for i in known]

    rules = []
    for i, value in enumerate(expected):
        if i in matched:
            rule = detected[matched[i]]
            rules.append(Rule(rule["a"], rule["b"], True))
            continue
        offset = float(np.interp(i, known, offsets)) if known else 0.0
        at_probe = value + offset
        rules.append(Rule(at_probe - slope * probe, slope, False))
    return rules


def _snap_columns(columns, v_rules, probe, row_height):
    """Snap column edges to the printed vertical rules.

    Neighbouring columns usually share one rule, so edges closer together
    than a few pixels are snapped as a single boundary.
    """
    edges = []  # (position, [(column, side), ...])
    for column in sorted(columns, key=lambda c: c["left"]):
        for side in ("left", "right"):
            position = column[side]
            if edges and abs(edges[-1][0] - position) <= max(4.0, 0.15 * row_height):
                edges[-1][1].append((column, side))
                edges[-1] = ((edges[-1][0] + position) / 2, edges[-1][1])
            else:
                edges.append((position, [(column, side)]))

    widths = [c["right"] - c["left"] for c in columns]
    tolerance = min(0.3 * min(widths), 0.6 * row_height) if widths else 0.6 * row_height
    snapped = _snap([e[0] for e in edges], v_rules, probe, tolerance, 0.0, reach=0.5 * row_height)
    for rule, (_, owners) in zip(snapped, edges):
        for column, side in owners:
            column[f"{side}_rule"] = rule


# ============================================================ line helpers

class Line:
    """One outlined line. Coordinates are page pixels."""

    def __init__(self, baseline, parts, source, column=None):
        self.baseline = np.asarray(baseline, float)
        self.baseline = self.baseline[np.argsort(self.baseline[:, 0])]
        self.parts = parts  # boundary polygons, list of (N, 2) arrays
        self.source = source
        self.column = column
        self.row = None
        self.flags = []
        self.polygon = None
        self.field = None

    @property
    def x0(self):
        return float(self.baseline[0, 0])

    @property
    def x1(self):
        return float(self.baseline[-1, 0])

    @property
    def cx(self):
        return (self.x0 + self.x1) / 2

    @property
    def width(self):
        return self.x1 - self.x0

    def y_at(self, x):
        return float(np.interp(x, self.baseline[:, 0], self.baseline[:, 1]))

    @property
    def y(self):
        """Median baseline height: the line's position for row assignment."""
        return float(np.median(self.baseline[:, 1]))


def _clip_polygon(points, lo, hi):
    """Clip a polygon to lo <= x <= hi. Returns the largest piece or None."""
    from shapely.geometry import Polygon, box

    shape = Polygon(points)
    if not shape.is_valid:
        shape = shape.buffer(0)
    if shape.is_empty:
        return None
    piece = shape.intersection(box(lo, -1e6, hi, 1e6))
    if piece.is_empty:
        return None
    if piece.geom_type != "Polygon":
        polygons = [g for g in getattr(piece, "geoms", []) if g.geom_type == "Polygon"]
        if not polygons:
            return None
        piece = max(polygons, key=lambda g: g.area)
    return np.asarray(piece.exterior.coords, float)


def _clip_baseline(baseline, lo, hi):
    xs, ys = baseline[:, 0], baseline[:, 1]
    lo = max(lo, xs[0])
    hi = min(hi, xs[-1])
    if hi - lo < 2:
        return None
    inner = baseline[(xs > lo) & (xs < hi)]
    start = [lo, float(np.interp(lo, xs, ys))]
    end = [hi, float(np.interp(hi, xs, ys))]
    return np.vstack([[start], inner, [end]]) if len(inner) else np.array([start, end])


def _column_edges(columns, y):
    return [(c["left_rule"].at(y), c["right_rule"].at(y)) for c in columns]


def _split_at_columns(line, columns, ink, row_height):
    """Cut a detected line where it crosses a column rule.

    The cut goes through the emptiest ink column near the rule, so text that
    overflows its column by a letter or two is not sliced through.
    """
    y = line.y
    edges = _column_edges(columns, y)
    cuts = []
    for k in range(len(edges) - 1):
        boundary = (edges[k][1] + edges[k + 1][0]) / 2
        if line.x0 + 6 < boundary < line.x1 - 6:
            cuts.append(boundary)
    if not cuts:
        return [line]

    top = int(max(0, y - 0.8 * row_height))
    bottom = int(min(ink.shape[0], y + 0.2 * row_height))
    window = max(6, int(0.35 * row_height))
    refined = []
    for boundary in cuts:
        lo = int(max(line.x0 + 4, boundary - window))
        hi = int(min(line.x1 - 4, boundary + window))
        if hi <= lo:
            refined.append(boundary)
            continue
        profile = ink[top:bottom, lo:hi + 1].sum(axis=0).astype(float)
        # Prefer the rule itself when several columns are equally empty.
        xs = np.arange(lo, hi + 1)
        profile += np.abs(xs - boundary) / (4.0 * window)
        refined.append(float(xs[int(np.argmin(profile))]))

    bounds = [-1e6] + refined + [1e6]
    pieces = []
    for lo, hi in zip(bounds[:-1], bounds[1:]):
        baseline = _clip_baseline(line.baseline, lo, hi)
        if baseline is None or baseline[-1, 0] - baseline[0, 0] < 6:
            continue
        parts = [p for p in (_clip_polygon(part, lo, hi) for part in line.parts) if p is not None]
        if not parts:
            continue
        pieces.append(Line(baseline, parts, line.source))
    return pieces


def _column_for(line, columns):
    """Column whose span holds most of the line, or None."""
    edges = _column_edges(columns, line.y)
    best, best_overlap = None, 0.0
    for index, (left, right) in enumerate(edges):
        overlap = min(line.x1, right) - max(line.x0, left)
        if overlap > best_overlap:
            best, best_overlap = index, overlap
    if best is None or best_overlap < 0.4 * max(1.0, line.width):
        return None
    return best


def _x_overlap(a, b):
    return min(a.x1, b.x1) - max(a.x0, b.x0)


def _merge(a, b):
    baseline = np.vstack([a.baseline, b.baseline])
    merged = Line(baseline, a.parts + b.parts, a.source, a.column)
    merged.row = a.row
    return merged


def _merge_side_by_side(lines, row_height):
    """Join pieces of one written line that the detector returned separately."""
    lines = sorted(lines, key=lambda line: line.x0)
    changed = True
    while changed:
        changed = False
        for i in range(len(lines)):
            for j in range(i + 1, len(lines)):
                a, b = lines[i], lines[j]
                same_height = abs(a.y - b.y) <= 0.3 * row_height
                overlap = _x_overlap(a, b)
                side_by_side = overlap <= 0.25 * min(a.width, b.width)
                duplicate = overlap >= 0.6 * min(a.width, b.width) and abs(a.y - b.y) <= 0.15 * row_height
                if same_height and (side_by_side or duplicate):
                    lines[i] = _merge(a, b)
                    del lines[j]
                    changed = True
                    break
            if changed:
                break
    return lines


# ============================================================ rows

def _row_frame(rules, x):
    """Tops and heights of every row band at x."""
    ys = np.array([rule.at(x) for rule in rules])
    return ys[:-1], np.diff(ys)


def _writing_offset(lines, rules):
    """Where in a row this page's writing usually sits, as a fraction of it.

    Measured from the data rather than assumed, because some registers are
    written on the rule and others mid-row. A circular mean keeps baselines
    just above and just below a rule from averaging to mid-row.
    """
    angles = []
    for line in lines:
        tops, heights = _row_frame(rules, line.cx)
        k = int(np.searchsorted(tops + heights, line.y))
        if 0 <= k < len(tops) and heights[k] > 0:
            angles.append(2 * math.pi * (line.y - tops[k]) / heights[k])
    if not angles:
        return 0.75
    mean = math.atan2(np.mean(np.sin(angles)), np.mean(np.cos(angles))) / (2 * math.pi)
    mean %= 1.0
    # Writing sits on or above its row's bottom rule, so a mean just past a
    # rule means the bottom of the row above, not the top of the one below.
    return mean + 1.0 if mean < 0.25 else mean


def _assign_rows(lines, rules, offset):
    """Place each line of one column in a row, keeping reading order.

    Dynamic programming over lines sorted top to bottom: rows never go back
    up, each line prefers the row whose usual writing height it is closest
    to, and a second line in an occupied row pays SHARE_PENALTY. This is what
    keeps a line that drifts across a rule in its own row.
    """
    if not lines:
        return
    lines.sort(key=lambda line: line.y)
    n_rows = len(rules) - 1
    cost = np.zeros((len(lines), n_rows))
    for i, line in enumerate(lines):
        tops, heights = _row_frame(rules, line.cx)
        expected = tops + offset * heights
        cost[i] = ((line.y - expected) / np.maximum(heights, 1.0)) ** 2

    best = np.full((len(lines), n_rows), np.inf)
    back = np.zeros((len(lines), n_rows), dtype=int)
    best[0] = cost[0]
    for i in range(1, len(lines)):
        running_min = np.inf
        running_arg = -1
        for r in range(n_rows):
            # Option 1: previous line in an earlier row.
            option_new = running_min
            # Option 2: previous line in this same row (shared cell).
            option_share = best[i - 1, r] + SHARE_PENALTY
            if option_new <= option_share:
                best[i, r] = cost[i, r] + option_new
                back[i, r] = running_arg
            else:
                best[i, r] = cost[i, r] + option_share
                back[i, r] = r
            if best[i - 1, r] < running_min:
                running_min = best[i - 1, r]
                running_arg = r

    r = int(np.argmin(best[-1]))
    for i in range(len(lines) - 1, -1, -1):
        lines[i].row = r
        lines[i].row_distance = math.sqrt(cost[i, r])
        if lines[i].row_distance > NO_ROW_DISTANCE:
            lines[i].flags.append(FLAG_NO_ROW)
        r = int(back[i, r]) if i > 0 else r


def _nearest_row(y, x, rules, offset):
    """Row for a single outline (manual corrections), or None if between rows."""
    tops, heights = _row_frame(rules, x)
    expected = tops + offset * heights
    distances = np.abs(y - expected) / np.maximum(heights, 1.0)
    r = int(np.argmin(distances))
    return (None if distances[r] > NO_ROW_DISTANCE else r), float(distances[r])


def _rows_do_not_fit(written, rules, row_height):
    """True when written lines (page x, baseline y) do not sit in the grid's rows.

    In a ledger every line is written at about the same height within its row,
    and one row below the last. Both fail on a page that is not that ledger:
    the baselines fall at any height within a row (low concentration of their
    positions within it), and are several rows apart. Both must fail, and there
    must be enough lines to tell.
    """
    phases = []
    for x, y in written:
        tops, heights = _row_frame(rules, x)
        k = int(np.searchsorted(tops + heights, y))
        if 0 <= k < len(tops) and heights[k] > 0:
            phases.append(2 * math.pi * (y - tops[k]) / heights[k])
    if len(phases) < ROWS_MIN_LINES or row_height <= 0:
        return False
    concentration = float(np.hypot(np.mean(np.sin(phases)), np.mean(np.cos(phases))))

    centres = []
    for y in sorted(y for _, y in written):
        if centres and y - centres[-1][-1] <= 0.3 * row_height:
            centres[-1].append(y)
        else:
            centres.append([y])
    if len(centres) < 3:
        return False
    gap = float(np.median(np.diff([np.mean(group) for group in centres])))
    return concentration < ROWS_SCATTER and gap >= ROWS_SPARSE * row_height


def _grid_notes(rules, expected, h_rules, above, below, columns, row_height, centre_x, table_left, table_right, height,
                written=()):
    """What Staff should check about the grid as a whole: [{"code": ..., ...}].

    A wrong grid cannot be told from a right one by looking at any single
    line: every line still lands in some row. These are the tell-tale signs,
    reported so nothing is silent, and never acted on:

      rows_uneven       some row bands that hold writing came out much taller
                        or shorter than the template's (a header taken for a
                        row, a rule the page lacks). `rows` are the 1-based row
                        numbers. Empty rows are left out: a template with more
                        rows than the page squeezes its last rows into the
                        margin, and there is nothing in them to read wrongly.
                        `written` are the heights of the lines found in the grid.
      lines_below_grid  written lines in several columns just below the last
                        row: the page has more rows than the template, and
                        they are not read. `rows` is how many rows they span.
      rows_do_not_fit   the writing on this page does not sit in the template's rows
                        (see _rows_do_not_fit), so `count` column(s) were read as
                        free text. Reported by process_page itself.
      grid_moved        (Detect only) the fit moved the first row line whole rows
                        from where Staff put it; `rows` is how many, positive
                        for down. A header as tall as a row is part of the
                        page's evenly ruled run, so this can be the header.
      lines_above_grid  written lines in several columns in a row-sized band
                        directly above the first row. A taller band is a
                        header (more than ROW_SIZED_BAND rows) stays quiet; a
                        band as tall as a row could be either, so Staff are asked.
    """
    notes = []
    at = [rule.at(centre_x) for rule in rules]
    snapped = np.diff(at)
    wanted = np.diff(np.asarray(expected, float))
    if len(snapped) >= 3 and len(snapped) == len(wanted):
        # Rows the template squeezed onto the page's edge, or past it, are not
        # rows the page has; leave them out of the comparison.
        real = (wanted > 0.3 * float(np.median(wanted))) & (np.asarray(at[:-1]) < height)
        ratio = snapped[real] / wanted[real]
        typical = float(np.median(ratio)) if len(ratio) >= 3 else 0.0
        if typical > 0:
            numbers = np.nonzero(real)[0] + 1
            holds = {k + 1 for k in range(len(snapped))
                     if any(at[k] <= y <= at[k + 1] + 0.1 * row_height for y in written)}
            off = [int(n) for n, value in zip(numbers, ratio)
                   if abs(value / typical - 1.0) > UNEVEN_BAND and int(n) in holds]
            if off:
                notes.append({"code": NOTE_ROWS_UNEVEN, "count": len(off), "rows": off[:6]})

    def in_table(line):
        return table_left <= line.cx <= table_right

    def spread(lines):
        return {_column_at(line.cx, line.y, columns) for line in lines} - {None}

    lower = [line for line in below if in_table(line) and line.y <= rules[-1].at(line.cx) + 4 * row_height]
    if len(spread(lower)) >= 2:
        ys = sorted(line.y for line in lower)
        rows = 1 + sum(1 for a, b in zip(ys, ys[1:]) if b - a > 0.5 * row_height)
        notes.append({"code": NOTE_LINES_BELOW, "count": len(lower), "rows": rows})

    top = at[0]
    printed = [rule["a"] + rule["b"] * centre_x for rule in h_rules]
    edges = [y for y in printed if y < top - 0.3 * row_height]
    if edges and top - max(edges) <= ROW_SIZED_BAND * row_height:
        edge = max(edges)
        band = [line for line in above if in_table(line) and edge < line.y]
        if len(spread(band)) >= 2:
            notes.append({"code": NOTE_LINES_ABOVE, "count": len(band)})
    return notes


# ============================================================ strokes and outlines

def _x_height(lines, ink, row_height):
    """Height of the lowercase body above the baseline, measured from the ink."""
    offsets = np.arange(-int(0.9 * row_height), int(0.3 * row_height) + 1)
    totals = np.zeros(len(offsets))
    count = 0
    height, width = ink.shape
    for line in lines:
        xs = np.arange(int(line.x0), int(line.x1), 2)
        if len(xs) == 0:
            continue
        ys = np.interp(xs, line.baseline[:, 0], line.baseline[:, 1]).astype(int)
        for k, d in enumerate(offsets):
            yy = ys + d
            ok = (yy >= 0) & (yy < height) & (xs >= 0) & (xs < width)
            totals[k] += ink[yy[ok], xs[ok]].sum()
        count += len(xs)
    if count == 0:
        return 0.35 * row_height
    density = totals / count
    above = offsets <= 0
    peak = density[above].max()
    if peak <= 0:
        return 0.35 * row_height
    body = offsets[above & (density >= 0.4 * peak)]
    x_height = float(-body.min()) if len(body) else 0.35 * row_height
    return float(np.clip(x_height, 0.15 * row_height, 0.6 * row_height))


def _core_band(line, x_height):
    """The line's x-height band along its baseline, as a polygon."""
    xs = line.baseline[:, 0]
    ys = line.baseline[:, 1]
    top = [(x, y - x_height) for x, y in zip(xs, ys)]
    bottom = [(x, y + 0.15 * x_height) for x, y in zip(xs[::-1], ys[::-1])]
    return top + bottom


def _raster(shape, polygons_by_id):
    """Label image: pixel value = id of the polygon covering it (0 = none)."""
    canvas = Image.new("I", (shape[1], shape[0]), 0)
    draw = ImageDraw.Draw(canvas)
    for line_id, polygons in polygons_by_id:
        for polygon in polygons:
            if len(polygon) >= 3:
                draw.polygon([tuple(p) for p in polygon], fill=int(line_id))
    return np.asarray(canvas, dtype=np.int32)


def _row_key(line):
    """Which row a line is in, telling a field's line numbers from table rows."""
    return (line.field["index"] if line.field is not None else None, line.row)


def _assign_strokes(lines, clean, x_height, row_height):
    """Give every ink component to one line.

    Returns (owner, labels): owner is a per-pixel line id (1-based, 0 = none).
    A component that enters exactly one line's x-height band belongs wholly
    to that line, however far its capital or tail reaches. A component that
    enters two stacked lines' bands (a tail touching the capital below) is
    split between them pixel by pixel.
    """
    from scipy import ndimage

    labels, count = ndimage.label(clean, structure=np.ones((3, 3), bool))
    cores = _raster(clean.shape, [(i + 1, [_core_band(line, x_height)]) for i, line in enumerate(lines)])
    outlines = _raster(clean.shape, [(i + 1, line.parts) for i, line in enumerate(lines)])

    owner = np.zeros(clean.shape, dtype=np.int32)
    loose = []
    for index, window in enumerate(ndimage.find_objects(labels), start=1):
        if window is None:
            continue
        pixels = labels[window] == index
        core_ids = cores[window][pixels]
        core_counts = np.bincount(core_ids, minlength=len(lines) + 1)
        core_counts[0] = 0
        members = [k for k in np.nonzero(core_counts)[0] if core_counts[k] >= 3]

        if len(members) >= 2:
            rows = {_row_key(lines[k - 1]) for k in members}
            if len(rows) >= 2:
                owner[window][pixels] = _split_component(pixels, cores[window], members)[pixels]
                continue
            members = [max(members, key=lambda k: core_counts[k])]

        if len(members) == 1:
            owner[window][pixels] = members[0]
            continue

        outline_ids = outlines[window][pixels]
        outline_counts = np.bincount(outline_ids, minlength=len(lines) + 1)
        outline_counts[0] = 0
        if outline_counts.sum() > 0:
            owner[window][pixels] = int(np.argmax(outline_counts))
            continue

        loose.append((index, window))

    # Dots, accents and full stops sit outside every band: attach them to the
    # nearest line in the same column when they are close enough to it.
    leftover = []
    for index, window in loose:
        ys, xs = np.nonzero(labels[window] == index)
        cy = float(ys.mean() + window[0].start)
        cx = float(xs.mean() + window[1].start)
        best, best_distance = None, None
        for k, line in enumerate(lines):
            if not (line.x0 - 0.5 * x_height <= cx <= line.x1 + 0.5 * x_height):
                continue
            distance = abs(cy - (line.y_at(cx) - 0.5 * x_height))
            if best_distance is None or distance < best_distance:
                best, best_distance = k, distance
        if best is None or best_distance > 1.1 * x_height:
            best = _line_under_mark(lines, cx, cy, float(ys.max() + window[0].start), x_height, row_height)
        if best is not None:
            owner[window][labels[window] == index] = best + 1
        else:
            leftover.append((index, window))

    return owner, labels, leftover


def _split_component(pixels, cores, members):
    """Split one ink component between the stacked lines whose bands it enters.

    Each line's share grows outward from the component's pixels inside that
    line's x-height band, one pixel ring at a time along the ink, so every
    pixel goes to the line it is nearer to along the pen's path. Splitting by
    straight-line height instead cuts every touching stroke at the same level
    between the lines, wherever the strokes actually meet. On a page of
    tightly stacked handwriting this left less of one line's ink inside the
    next line's outline; on the ledger samples both splits agree.
    """
    from scipy import ndimage

    grown = np.where(pixels & np.isin(cores, members), cores, 0)
    structure = np.ones((3, 3), bool)
    while True:
        todo = pixels & (grown == 0)
        if not todo.any():
            break
        spread = ndimage.grey_dilation(grown, footprint=structure)
        new = todo & (spread > 0)
        if not new.any():
            break
        grown[new] = spread[new]
    return grown


def _line_under_mark(lines, cx, cy, bottom, x_height, spacing):
    """In a field, the written line a loose mark sits over, or None.

    A dot, an accent or the detached bar of a capital T is written above its
    own letters, often higher than the dot of an i in a ledger cell. It
    belongs to the first line below it, not to whichever line is nearest,
    which can be the line above. Table cells keep the nearest-line rule:
    there, ink too far from any line may be a cell the detector missed.
    """
    best, best_gap = None, None
    for k, line in enumerate(lines):
        if line.field is None or not _inside_any(cx, cy, [line.field["polygon"]]):
            continue
        if not (line.x0 - 0.5 * x_height <= cx <= line.x1 + 0.5 * x_height):
            continue
        gap = line.y_at(cx) - bottom
        if -0.5 * x_height <= gap <= spacing and (best_gap is None or gap < best_gap):
            best, best_gap = k, gap
    return best


def _outline(owner, line_id, window, core, x_height, shape, rules=None):
    """Polygon around a line's own strokes, cut away from everyone else's.

    `rules` marks printed rule pixels. They are kept out of the outline except
    where the line's own strokes cross them: a stub of a column rule left at
    the edge of a masked crop reads as a "1" or an "l" to TrOCR.
    """
    from scipy import ndimage
    from skimage.measure import approximate_polygon, find_contours

    core = np.asarray(core, float)
    margin = int(max(3, round(0.35 * x_height)))
    if window is not None:
        own_x0, own_x1 = window[1].start, window[1].stop - 1
        own_y0, own_y1 = window[0].start, window[0].stop - 1
    else:
        own_x0 = own_y0 = 1e9
        own_x1 = own_y1 = -1
    x0 = int(max(0, min(own_x0, core[:, 0].min()) - margin - 2))
    y0 = int(max(0, min(own_y0, core[:, 1].min()) - margin - 2))
    x1 = int(min(shape[1], max(own_x1, core[:, 0].max()) + margin + 3))
    y1 = int(min(shape[0], max(own_y1, core[:, 1].max()) + margin + 3))

    local_owner = owner[y0:y1, x0:x1]
    own = local_owner == line_id
    foreign = (local_owner != 0) & ~own

    radius = int(max(2, round(0.25 * x_height)))
    disk = _disk(radius)
    mask = ndimage.binary_dilation(own, structure=disk)

    band = Image.new("L", (x1 - x0, y1 - y0), 0)
    ImageDraw.Draw(band).polygon([(x - x0, y - y0) for x, y in core], fill=1)
    mask |= np.asarray(band, bool)

    mask &= ~ndimage.binary_dilation(foreign, structure=_disk(1))
    if rules is not None:
        mask &= ~(rules[y0:y1, x0:x1] & ~ndimage.binary_dilation(own, structure=_disk(1)))

    # One outline per line: keep the pieces that hold the line's strokes and
    # bridge any piece a foreign stroke cut off.
    labels, count = ndimage.label(mask)
    if count > 1:
        # Every piece holding the line's own ink stays - the end of a tail
        # below an erased rule, the dot of an i. Only slivers of empty band
        # that a foreign stroke cut off may go.
        inked = ndimage.sum(own, labels, index=range(1, count + 1))
        sizes = ndimage.sum(np.ones_like(own), labels, index=range(1, count + 1))
        keep = [k + 1 for k in range(count) if inked[k] > 0 or sizes[k] >= 0.03 * max(sizes)]
        centres = {k: ndimage.center_of_mass(labels == k) for k in keep}
        pieces = sorted(keep, key=lambda k: centres[k][1])
        joined = np.isin(labels, pieces)
        for a, b in zip(pieces[:-1], pieces[1:]):
            (ay, ax), (by, bx) = centres[a], centres[b]
            steps = int(max(abs(bx - ax), abs(by - ay))) + 1
            for t in np.linspace(0.0, 1.0, steps + 1):
                yy = int(round(ay + (by - ay) * t))
                xx = int(round(ax + (bx - ax) * t))
                joined[max(0, yy - 1):yy + 2, max(0, xx - 1):xx + 2] = True
        mask = joined
    mask = ndimage.binary_fill_holes(mask)

    padded = np.pad(mask, 1)
    contours = find_contours(padded.astype(float), 0.5)
    if not contours:
        return None
    contour = max(contours, key=len)
    contour = approximate_polygon(contour, tolerance=1.2)
    return [[int(round(c + x0 - 1)), int(round(r + y0 - 1))] for r, c in contour[:-1]]


def _disk(radius):
    yy, xx = np.mgrid[-radius:radius + 1, -radius:radius + 1]
    return (xx * xx + yy * yy) <= radius * radius


def _ink_line(ys, xs):
    """A line built from leftover ink (pixel coordinates) in a cell the detector missed."""
    # Baseline: the typical lowest ink per column, which ignores the odd tail.
    bottoms = {}
    for x, y in zip(xs, ys):
        if y > bottoms.get(x, -1):
            bottoms[x] = y
    base_y = float(np.percentile(list(bottoms.values()), 60))
    x0, x1 = float(xs.min()), float(xs.max())
    if x1 - x0 < 4:
        x0 -= 2
        x1 += 2
    baseline = [[x0, base_y], [x1, base_y]]
    part = np.array(_rect_polygon(x0, float(ys.min()), x1 - x0, float(ys.max() - ys.min())))
    return Line(baseline, [part], SOURCE_INK)


# ============================================================ page processing

def process_page(page_path, geometry, out_dir, detector=kraken_lines, debug=None, row_reach=ROW_REACH,
                 detect_fields=False):
    """Outline, flag and crop every line on one aligned page.

    `geometry` is the template aligned to this page, in page fractions:
        {"columns": [{"name": ..., "box": [x, y, w, h]}, ...],
         "ruled_ys": [y, ...],                      # every printed row line
         "fields": [{"name": ..., "box": [x, y, w, h]}, ...]}  # legacy rectangles

    Writes crops/, overlay.png and lines.json into `out_dir` and returns the
    same data as lines.json. Pass a dict as `debug` to receive the stroke
    ownership map (tests use it to check that no outline cuts its own
    strokes or holds anyone else's).
    """
    started = time.time()
    timings = {}
    os.makedirs(os.path.join(out_dir, "crops"), exist_ok=True)

    image = Image.open(page_path).convert("RGB")
    width, height = image.size
    columns, ruled, fields = _page_geometry(geometry, width, height)

    lines = []
    ignored = []
    # Handwriting the detector found just outside the grid, top and bottom.
    above, below = [], []
    notes = []
    grid = None
    detector_used = None
    has_table = bool(columns) and len(ruled) >= 2
    # In Detect, every rectangle field is split into the written lines inside
    # it. Otherwise a rectangle stays one four-point polygon, as it always was.
    split_fields = detect_fields and bool(fields)
    detected_fields = set()

    if has_table or split_fields:
        from scipy import ndimage

        gray = np.asarray(image.convert("L"), dtype=np.uint8)
        ink = _ink_mask(gray)
        timings["ink"] = time.time() - started

        # Detection. It does not depend on the rules, and the size of the
        # writing it finds tells how thick a printed rule can be here.
        raw = detector(image)
        timings["detector"] = time.time() - started
        detector_used = getattr(detector, "used_device", None)
        heights = [float(np.ptp(np.asarray(entry["boundary"], float)[:, 1])) for entry in raw]
        max_thickness = float(np.median(heights)) / 7 if heights else None
        # Where the detector found writing: a stretch of a written word can pass
        # for a piece of printed rule, and must not be erased (see _find_rules).
        writing_canvas = Image.new("1", (width, height), 0)
        writing_draw = ImageDraw.Draw(writing_canvas)
        for entry in raw:
            writing_draw.polygon([tuple(point) for point in entry["boundary"]], fill=1)
        writing = np.asarray(writing_canvas, dtype=bool)
        near = 0.25 * float(np.median(heights)) if heights else 0.0

        h_evidence, v_evidence = _rule_pixels(gray)
        if has_table:
            row_height = float(np.median(np.diff(ruled)))
            table_left = min(c["left"] for c in columns)
            table_right = max(c["right"] for c in columns)
            table_top = min(ruled[0], min(c["top"] for c in columns))
            table_bottom = max(ruled[-1], max(c["bottom"] for c in columns))
            centre_x = (table_left + table_right) / 2
            centre_y = (table_top + table_bottom) / 2

            h_rules, h_pixels = _find_rules(h_evidence, "h", 0.2 * (table_right - table_left), max_thickness, writing, near)
            v_rules, v_pixels = _find_rules(v_evidence, "v", 0.2 * (table_bottom - table_top), max_thickness, writing, near)
            # The first row line may move up to `row_reach` rows from where the
            # markers put it, and the row spacing may stretch by ROW_STRETCH
            # about that line. Never a whole row: see ROW_REACH.
            rules = _snap(ruled, h_rules, centre_x, 0.45 * row_height, 0.0,
                          reach=row_reach * row_height, stretch=ROW_STRETCH, pivot="first")
            _snap_columns(columns, v_rules, centre_y, row_height)
            # From here on the page's own rules define the table, not the template
            # boxes, which are only as exact as the alignment step left them.
            table_left = min(c["left_rule"].at(centre_y) for c in columns)
            table_right = max(c["right_rule"].at(centre_y) for c in columns)
            table_top = min(rules[0].at(table_left), rules[0].at(table_right))
            table_bottom = max(rules[-1].at(table_left), rules[-1].at(table_right))

            # A ledger column over writing that does not sit in its rows: read the
            # columns as free text instead - the written lines inside each, top to
            # bottom, exactly as a rectangle field is - and tell Staff. Forcing every
            # line into one of the template's rows splits words apart and flags most
            # of the page.
            written = [(float(np.mean([p[0] for p in entry["baseline"]])),
                        float(np.median([p[1] for p in entry["baseline"]]))) for entry in raw]
            written = [(x, y) for x, y in written
                       if table_left <= x <= table_right and rules[0].at(x) <= y <= rules[-1].at(x)]
            if _rows_do_not_fit(written, rules, row_height):
                for column in columns:
                    fields.append({
                        "index": len(fields),
                        "name": column["name"],
                        "polygon": _rect_polygon(column["left"], column["top"],
                                                 column["right"] - column["left"], column["bottom"] - column["top"]),
                        "turned": False,
                        "person_group": None,
                        "person_field_order": None,
                    })
                notes.append({"code": NOTE_ROWS_DO_NOT_FIT, "count": len(columns)})
                columns, ruled, has_table, split_fields = [], [], False, True

        if not has_table:
            # No table: any long printed line (a ruled notebook page, a form's
            # underlines) is still erased before strokes are read.
            h_rules, h_pixels = _find_rules(h_evidence, "h", 0.2 * width, max_thickness, writing, near)
            v_rules, v_pixels = _find_rules(v_evidence, "v", 0.2 * height, max_thickness, writing, near)
            rules = []
            row_height = 0.0

        h_band, v_band = _rule_band(h_pixels, v_pixels)
        clean = _remove_rules(ink, h_band, v_band)
        # Printed rule pixels proper, faint ones included, less the stroke ink
        # that crosses them.
        rule_pixels = ndimage.binary_dilation(h_pixels | v_pixels, structure=np.ones((3, 3), bool)) & ~clean
        near_rule = ndimage.binary_dilation(h_band | v_band, structure=np.ones((5, 5), bool))
        timings["rules"] = time.time() - started

        field_polygons = [f["polygon"] for f in fields]
        pieces = []
        field_pieces = {}
        for entry in raw:
            line = Line(entry["baseline"], [np.asarray(entry["boundary"], float)], SOURCE_DETECTED)
            inside = _field_at(line.cx, line.y, field_polygons)
            if inside is not None:
                if split_fields:
                    field_pieces.setdefault(inside, []).append(line)
                continue
            if not has_table:
                ignored.append(line)
                continue
            # Writing sits on or above its own row's bottom rule, so a baseline
            # above the table's top rule is the printed header row, and one far
            # below the last rule is outside the table.
            top = rules[0].at(line.cx) - 0.1 * row_height
            bottom = rules[-1].at(line.cx) + 0.5 * row_height
            if not (top <= line.y <= bottom):
                ignored.append(line)
                (above if line.y < top else below).append(line)
                continue
            for piece in _split_at_columns(line, columns, clean, row_height):
                piece.column = _column_for(piece, columns)
                if piece.column is None:
                    ignored.append(piece)
                else:
                    pieces.append(piece)

        if has_table:
            notes = _grid_notes(rules, ruled, h_rules, above, below, columns, row_height,
                                centre_x, table_left, table_right, height, [piece.y for piece in pieces])

        offset = _writing_offset(pieces, rules) if has_table else 0.0
        by_column = {}
        for piece in pieces:
            by_column.setdefault(piece.column, []).append(piece)

        for column_index, members in by_column.items():
            members = _merge_rows(members, row_height)
            _assign_rows(members, rules, offset)
            cells = {}
            for line in members:
                cells.setdefault(line.row, []).append(line)
            for row, occupants in cells.items():
                occupants = _merge_side_by_side(occupants, row_height)
                for line in occupants:
                    line.column = column_index
                    line.row = row
                lines.extend(occupants)
        table_count = len(lines)

        # Rectangle fields: the written lines inside each one, top to bottom.
        if field_pieces:
            field_lines, spacing = _field_text_lines(field_pieces, fields)
            for line in field_lines:
                detected_fields.add(line.field["index"])
            lines.extend(field_lines)
            if not has_table:
                row_height = spacing

        x_height = _x_height(lines, clean, row_height)
        owner, labels, leftover = _assign_strokes(lines, clean, x_height, row_height)
        timings["strokes"] = time.time() - started

        # Cells the detector missed entirely (table only).
        occupied = {(line.column, line.row) for line in lines[:table_count]}
        # A row where the detector found no writing at all is an empty row; a
        # lone mark there is a stain or a stray stroke, not an entry.
        written_rows = {line.row for line in lines[:table_count] if line.source == SOURCE_DETECTED}
        buckets = {}

        def bucket(ys, xs):
            cy, cx = float(ys.mean()), float(xs.mean())
            if not has_table or not (table_top <= cy <= table_bottom):
                return
            column = _column_at(cx, cy, columns)
            if column is None:
                return
            tops, heights = _row_frame(rules, cx)
            row = int(np.searchsorted(tops + heights, cy))
            if 0 <= row < len(heights) and (column, row) not in occupied:
                buckets.setdefault((column, row), []).append((ys, xs))

        for index, window in leftover:
            mask = labels[window] == index
            # Thick corners and smudged stretches of a printed rule survive rule
            # removal as small blobs hugging the rule. They are print, not a line.
            if np.count_nonzero(near_rule[window] & mask) >= 0.5 * np.count_nonzero(mask):
                continue
            ys, xs = np.nonzero(mask)
            bucket(ys + window[0].start, xs + window[1].start)

        # A letter in an empty cell that touches the line above or below it
        # (a stroke meeting the printed rule from both sides) arrives joined to
        # that line. Ink reaching more than half a row past the line's own
        # rule is a whole letter, not a tail: hand it back to its own cell.
        owned = ndimage.find_objects(owner, max_label=len(lines))
        for i, line in enumerate(lines[:table_count]):
            window = owned[i]
            if window is None or FLAG_NO_ROW in line.flags:
                continue
            ys, xs = np.nonzero(owner[window] == i + 1)
            ys = ys + window[0].start
            xs = xs + window[1].start
            r = line.row
            top_rule = rules[r].a + rules[r].b * xs
            bottom_rule = rules[r + 1].a + rules[r + 1].b * xs
            for beyond, neighbour in ((ys > bottom_rule + 3, r + 1), (ys < top_rule - 3, r - 1)):
                if (not beyond.any() or not 0 <= neighbour < len(rules) - 1
                        or (line.column, neighbour) in occupied or neighbour not in written_rows):
                    continue
                piece = np.zeros(owner.shape, bool)
                piece[ys[beyond], xs[beyond]] = True
                piece_labels, _ = ndimage.label(piece, structure=np.ones((3, 3), bool))
                for k, sl in enumerate(ndimage.find_objects(piece_labels), start=1):
                    if sl is None or sl[0].stop - sl[0].start < 0.5 * row_height:
                        continue
                    py, px = np.nonzero(piece_labels[sl] == k)
                    py = py + sl[0].start
                    px = px + sl[1].start
                    owner[py, px] = 0
                    bucket(py, px)

        added = []
        for (column, row), members in buckets.items():
            ys = np.concatenate([m[0] for m in members])
            xs = np.concatenate([m[1] for m in members])
            tallest = float(ys.max() - ys.min() + 1)
            # A "1" or an "l" is tall and thin: few pixels, but plainly writing.
            if (len(ys) < 40 and tallest < 1.5 * x_height) or tallest < 0.6 * x_height or row not in written_rows:
                continue
            line = _ink_line(ys, xs)
            line.column, line.row = column, row
            added.append(line)
            owner[ys, xs] = len(lines) + len(added)
        lines.extend(added)

        owned = ndimage.find_objects(owner, max_label=len(lines))
        kept = []
        for i, line in enumerate(lines):
            window = owned[i]
            area = 0 if window is None else int(np.count_nonzero(owner[window] == i + 1))
            if line.source in (SOURCE_DETECTED, SOURCE_FIELD) and area < 15:
                # The detector saw a line where there is no ink: a stain, a
                # fold, or a printed rule it mistook for writing.
                continue
            if window is not None:
                # Detector baselines often run past the last letter; the
                # outline should end where the writing does.
                margin = 0.5 * x_height
                trimmed = _clip_baseline(line.baseline, window[1].start - margin, window[1].stop - 1 + margin)
                if trimmed is not None:
                    line.baseline = trimmed
            core = _core_band(line, x_height)
            line.polygon = _outline(owner, i + 1, window, core, x_height, owner.shape, rules=rule_pixels)
            if line.polygon is None or len(line.polygon) < 3:
                line.polygon = _rect_polygon(line.x0, line.y - x_height, line.width, 1.3 * x_height)
            line.owner_id = i + 1
            kept.append(line)
        lines = kept
        if debug is not None:
            debug["owner"] = owner

        # A line stacked above or below a well-placed line in the same cell
        # (a two-line entry) is far from where writing usually sits, but it is
        # still nearer this row than any other. It shares the cell; it is not
        # lost between rows.
        table_lines = [line for line in lines if line.field is None]
        settled = {(line.column, line.row) for line in table_lines if FLAG_NO_ROW not in line.flags}
        for line in table_lines:
            if (FLAG_NO_ROW in line.flags and getattr(line, "row_distance", 1.0) <= 0.5
                    and (line.column, line.row) in settled):
                line.flags.remove(FLAG_NO_ROW)

        occupancy = {}
        for line in table_lines:
            if FLAG_NO_ROW not in line.flags:
                occupancy.setdefault((line.column, line.row), []).append(line)
        for occupants in occupancy.values():
            if len(occupants) > 1:
                for line in occupants:
                    line.flags.append(FLAG_SHARED_CELL)
        timings["outlines"] = time.time() - started

    if has_table:
        grid = {
            "rules": [rule.to_json() for rule in rules],
            "columns": [
                {
                    "index": c["index"],
                    "name": c["name"],
                    "left": c["left_rule"].to_json(),
                    "right": c["right_rule"].to_json(),
                }
                for c in columns
            ],
            "offset": round(offset, 4),
            "x_height": round(x_height, 2),
            "row_height": round(row_height, 2),
            "table": [round(table_left, 1), round(table_top, 1), round(table_right, 1), round(table_bottom, 1)],
        }

    for field in fields:
        if field["index"] in detected_fields:
            continue
        # A rectangle is one four-point polygon when it was not split into
        # lines (older templates, Scan without Detect, or no writing found).
        line = Line([[0, 0], [1, 0]], [], SOURCE_TEMPLATE)
        line.polygon = [[round(x, 1), round(y, 1)] for x, y in field["polygon"]]
        line.field = field
        lines.append(line)

    # Order: fields first (each one's lines top to bottom), then the table row
    # by row, then anything flagged without a row.
    def order(line):
        if line.field is not None:
            return (0, line.field["index"], line.row if line.source == SOURCE_FIELD else 0, 0)
        if FLAG_NO_ROW in line.flags:
            return (2, 0, line.y, line.x0)
        return (1, line.row, line.column, line.x0)

    lines.sort(key=order)

    records = []
    columns_by_index = {c["index"]: c for c in columns}
    for number, line in enumerate(lines, start=1):
        crop_name = _crop_name(number, line, columns_by_index)
        crop = crop_turned_box if line.source == SOURCE_TEMPLATE and line.field.get("turned") else crop_line
        _, bbox = crop(image, line.polygon, os.path.join(out_dir, "crops", crop_name))
        record = {
            "id": number,
            "source": line.source,
            "column_index": None,
            "column": None,
            "row": None,
            "polygon": [[round(float(x), 1), round(float(y), 1)] for x, y in line.polygon],
            "baseline": None,
            "bbox": bbox,
            "crop": f"crops/{crop_name}",
            "flags": sorted(set(line.flags)),
        }
        if line.source == SOURCE_TEMPLATE:
            record["column"] = line.field["name"]
            record["field_index"] = line.field["index"]
            record["person_group"] = line.field["person_group"]
            record["person_field_order"] = line.field["person_field_order"]
        elif line.source == SOURCE_FIELD:
            # A written line inside a field: row is its line number in the field.
            record["column"] = line.field["name"]
            record["field_index"] = line.field["index"]
            record["person_group"] = line.field["person_group"]
            record["person_field_order"] = line.field["person_field_order"]
            record["row"] = line.row + 1
            record["baseline"] = [[round(float(x), 1), round(float(y), 1)] for x, y in line.baseline]
            if debug is not None:
                debug.setdefault("owner_ids", {})[number] = line.owner_id
        else:
            record["column_index"] = line.column
            record["column"] = columns_by_index[line.column]["name"]
            record["row"] = None if FLAG_NO_ROW in line.flags else line.row + 1
            record["row_distance"] = round(float(getattr(line, "row_distance", 0.0)), 3)
            record["baseline"] = [[round(float(x), 1), round(float(y), 1)] for x, y in line.baseline]
            if debug is not None:
                debug.setdefault("owner_ids", {})[number] = line.owner_id
        records.append(record)

    result = {
        "version": 1,
        "size": [width, height],
        "lines": records,
        "grid": grid,
        "ignored": len(ignored),
        # What Staff should check about the grid: see _grid_notes.
        "notes": notes,
        "timings": {k: round(v, 2) for k, v in timings.items()},
        # "cuda", "cpu", or "cpu (GPU failed)"; None when no detector ran.
        "detector_device": detector_used,
    }
    result["timings"]["total"] = round(time.time() - started, 2)

    draw_overlay(image, result, ignored, os.path.join(out_dir, "overlay.png"))
    with open(os.path.join(out_dir, "lines.json"), "w", encoding="utf-8") as handle:
        json.dump(result, handle)
    return result


def _merge_rows(members, row_height):
    """Pre-merge pieces of one written line before rows are assigned."""
    members = sorted(members, key=lambda line: line.y)
    groups = []
    for line in members:
        if groups and abs(groups[-1][-1].y - line.y) <= 0.3 * row_height:
            groups[-1].append(line)
        else:
            groups.append([line])
    merged = []
    for group in groups:
        merged.extend(_merge_side_by_side(group, row_height))
    return merged


def _column_at(x, y, columns):
    for index, column in enumerate(columns):
        if column["left_rule"].at(y) <= x <= column["right_rule"].at(y):
            return index
    return None


def _inside_any(x, y, polygons):
    return _field_at(x, y, polygons) is not None


def _field_at(x, y, polygons):
    """Index of the first field whose box holds (x, y), or None."""
    for index, polygon in enumerate(polygons):
        xs = [p[0] for p in polygon]
        ys = [p[1] for p in polygon]
        if min(xs) <= x <= max(xs) and min(ys) <= y <= max(ys):
            return index
    return None


def _field_text_lines(field_pieces, fields):
    """Group the detector's pieces inside each field into written lines.

    Kraken returns words, and sometimes parts of words, as separate lines. In
    a field there is no ruling to go by, so pieces whose baselines sit at the
    same height are one written line; lines are then numbered top to bottom.
    Returns the lines and the typical spacing between them.
    """
    heights = [
        float(np.ptp(part[:, 1]))
        for members in field_pieces.values() for piece in members for part in piece.parts
    ]
    line_height = float(np.median(heights)) if heights else 30.0

    result = []
    spacings = []
    for index, members in sorted(field_pieces.items()):
        field = fields[index]
        xs = [p[0] for p in field["polygon"]]
        lo, hi = min(xs), max(xs)
        clipped = []
        for piece in members:
            baseline = _clip_baseline(piece.baseline, lo, hi)
            if baseline is None:
                continue
            parts = [p for p in (_clip_polygon(part, lo, hi) for part in piece.parts) if p is not None]
            if parts:
                clipped.append(Line(baseline, parts, SOURCE_FIELD))

        groups = []
        for piece in sorted(clipped, key=lambda p: p.y):
            if groups and abs(piece.y - float(np.median([p.y for p in groups[-1]]))) <= 0.6 * line_height:
                groups[-1].append(piece)
            else:
                groups.append([piece])

        centres = []
        for row, group in enumerate(groups):
            group.sort(key=lambda p: p.x0)
            line = group[0]
            for other in group[1:]:
                line = _merge(line, other)
            line.source = SOURCE_FIELD
            line.field = field
            line.row = row
            result.append(line)
            centres.append(line.y)
        spacings.extend(np.diff(centres))

    spacing = float(np.median(spacings)) if spacings else 2.0 * line_height
    return result, max(spacing, line_height)


def _crop_name(number, line, columns_by_index):
    if line.source == SOURCE_TEMPLATE:
        return f"{number:03d}-field{line.field['index'] + 1:02d}.png"
    if line.source == SOURCE_FIELD:
        return f"{number:03d}-field{line.field['index'] + 1:02d}-l{line.row + 1:02d}.png"
    row = "x" if FLAG_NO_ROW in line.flags else f"{line.row + 1:02d}"
    return f"{number:03d}-c{line.column + 1:02d}-r{row}.png"


# ============================================================ debug overlay

def draw_overlay(image, result, ignored, out_path):
    """overlay.png: the page with every outline, baseline, rule and flag drawn."""
    canvas = image.convert("RGB").copy()
    draw = ImageDraw.Draw(canvas, "RGBA")
    width, height = canvas.size

    grid = result.get("grid")
    if grid:
        left, top, right, bottom = grid["table"]
        for rule in grid["rules"]:
            colour = (40, 110, 220, 150) if rule["matched"] else (220, 40, 200, 170)
            draw.line([(left, rule["a"] + rule["b"] * left), (right, rule["a"] + rule["b"] * right)],
                      fill=colour, width=1)
        for column in grid["columns"]:
            for edge in (column["left"], column["right"]):
                colour = (40, 110, 220, 110) if edge["matched"] else (220, 40, 200, 150)
                draw.line([(edge["a"] + edge["b"] * top, top), (edge["a"] + edge["b"] * bottom, bottom)],
                          fill=colour, width=1)

    for line in ignored:
        for part in line.parts:
            draw.polygon([tuple(p) for p in part], outline=(150, 150, 150, 140))

    for record in result["lines"]:
        polygon = [tuple(p) for p in record["polygon"]]
        if record["flags"]:
            fill, outline = (230, 30, 30, 45), (220, 20, 20, 255)
        elif record["source"] == SOURCE_TEMPLATE:
            fill, outline = (250, 160, 20, 35), (230, 130, 0, 255)
        elif record["source"] == SOURCE_INK:
            fill, outline = (20, 170, 200, 40), (0, 140, 180, 255)
        else:
            fill, outline = (30, 180, 60, 40), (10, 140, 40, 255)
        draw.polygon(polygon, fill=fill, outline=outline)
        if record["baseline"]:
            draw.line([tuple(p) for p in record["baseline"]], fill=(0, 90, 0, 200), width=1)
        x, y = min(p[0] for p in polygon), min(p[1] for p in polygon)
        label = (
            f"{record['row'] or '?'}" + (" !" if record["flags"] else "")
            if record["source"] != SOURCE_TEMPLATE else record["column"][:12]
        )
        draw.text((x + 1, max(0, y - 10)), label, fill=(200, 0, 0, 255) if record["flags"] else (0, 70, 0, 255))

    canvas.save(out_path, "PNG")


def audit_outlines(result, debug):
    """Check every detected outline against the stroke ownership map.

    For each line: how many of its own ink pixels fall outside its outline (a
    clipped capital or tail) and how many pixels of other lines' ink fall
    inside it (a neighbour's strokes in the crop). Both should be zero.
    """
    owner = debug["owner"]
    height, width = owner.shape
    report = []
    for record in result["lines"]:
        owner_id = debug.get("owner_ids", {}).get(record["id"])
        if owner_id is None:
            continue
        mask = Image.new("L", (width, height), 0)
        ImageDraw.Draw(mask).polygon([tuple(p) for p in record["polygon"]], fill=1, outline=1)
        inside = np.asarray(mask, bool)
        own = owner == owner_id
        report.append({
            "id": record["id"],
            "column": record["column"],
            "row": record["row"],
            "own_outside": int(np.count_nonzero(own & ~inside)),
            "foreign_inside": int(np.count_nonzero(inside & (owner != 0) & ~own)),
        })
    return report


# ============================================================ manual corrections

def place_outline(polygon, lines_json):
    """Row and column for a hand-drawn outline, from the page's saved grid."""
    grid = (lines_json or {}).get("grid")
    if not grid:
        return None, None, []
    rules = [Rule(r["a"], r["b"], r["matched"]) for r in grid["rules"]]
    columns = [
        {"left_rule": Rule(c["left"]["a"], c["left"]["b"], True),
         "right_rule": Rule(c["right"]["a"], c["right"]["b"], True)}
        for c in grid["columns"]
    ]
    xs = [p[0] for p in polygon]
    ys = [p[1] for p in polygon]
    # A line outline runs from ascenders to descenders; its baseline sits a
    # little below the middle.
    base_y = min(ys) + 0.7 * (max(ys) - min(ys))
    cx = (min(xs) + max(xs)) / 2
    column = _column_at(cx, base_y, columns)
    row, _ = _nearest_row(base_y, cx, rules, grid["offset"])
    flags = [] if row is not None else [FLAG_NO_ROW]
    return column, (None if row is None else row + 1), flags


# ============================================================ template grid

def detect_grid(page):
    """Printed rules on a page: a template sample, or a document being scanned.

    Suggests ruled_ys (the longest run of evenly spaced row rules) and
    columns (the spans between vertical rules inside that run), all as page
    fractions. `page` is a path or a PIL image.
    """
    image = (Image.open(page) if isinstance(page, str) else page).convert("L")
    width, height = image.size
    gray = np.asarray(image, dtype=np.uint8)
    h_evidence, v_evidence = _rule_pixels(gray)
    h_rules, _ = _find_rules(h_evidence, "h", 0.2 * width)
    v_rules, _ = _find_rules(v_evidence, "v", 0.15 * height)

    suggestion = {"ruled_ys": [], "columns": [], "estimated_ys": 0}
    centre = width / 2
    rows = sorted(h_rules, key=lambda r: r["a"] + r["b"] * centre)
    ys = [r["a"] + r["b"] * centre for r in rows]
    if len(ys) >= 3:
        # A rule on warped paper, or printed twice, can come back as two
        # near-parallel rules a few pixels apart; that short gap would break
        # the even rhythm the table body is found by. Keep the longer one.
        typical = float(np.median(np.diff(ys)))
        kept = []
        for rule, y in zip(rows, ys):
            if kept and y - kept[-1][1] < 0.3 * typical:
                if rule["hi"] - rule["lo"] > kept[-1][0]["hi"] - kept[-1][0]["lo"]:
                    kept[-1] = (rule, y)
                continue
            kept.append((rule, y))
        rows = [rule for rule, _ in kept]
        ys = [y for _, y in kept]
    if len(ys) >= 3:
        gaps = np.diff(ys)
        typical = float(np.median(gaps))
        # The ruled body of the table is the longest run of evenly spaced rules;
        # the title, header row and bottom margin break the rhythm.
        best, start = (0, 0), 0
        for k, gap in enumerate(gaps):
            if not (0.75 * typical <= gap <= 1.25 * typical):
                start = k + 1
                continue
            if k + 1 - start > best[1] - best[0]:
                best = (start, k + 1)
        first, last = best
        # A header band a little taller than a row can pass for one; the
        # rows inside the run keep a steadier spacing than that.
        while last - first >= 3 and abs(gaps[first] - float(np.median(gaps[first:last]))) \
                > 0.15 * float(np.median(gaps[first:last])):
            first += 1
        run = ys[first:last + 1]
        run_rules = rows[first:last + 1]
        if len(run) >= 3:
            typical = float(np.median(np.diff(run)))
            # Registers often leave the last row(s) without a printed bottom
            # rule. Fill the space down to the table's bottom border with
            # estimated lines so the last written row still has a band.
            next_rule = ys[last + 1] if last + 1 < len(ys) else None
            estimated = []
            if next_rule is not None:
                y = run[-1] + typical
                while y <= next_rule - 0.5 * typical:
                    estimated.append(y)
                    y += typical
                tail = estimated[-1] if estimated else run[-1]
                if estimated and next_rule - tail <= 1.5 * typical:
                    estimated.append(next_rule)
            run = run + estimated
            suggestion["estimated_ys"] = len(estimated)
            suggestion["ruled_ys"] = [round(y / height, 5) for y in run]

            top, bottom = run[0], run[-1]
            span = bottom - top
            lo_x = min(r["lo"] for r in run_rules)
            hi_x = max(r["hi"] for r in run_rules)
            middle = (top + bottom) / 2
            xs = sorted(
                r["a"] + r["b"] * middle for r in v_rules
                if r["lo"] <= top + 0.5 * span and r["hi"] >= top + 0.5 * span
                and r["hi"] - r["lo"] >= 0.5 * span
            )
            xs = [x for x in xs if lo_x - 6 <= x <= hi_x + 6]
            deduped = []
            for x in xs:
                if not deduped or x - deduped[-1] > 8:
                    deduped.append(x)
            for left, right in zip(deduped[:-1], deduped[1:]):
                if right - left < 10:
                    continue
                suggestion["columns"].append({
                    "box": [round(left / width, 5), round(top / height, 5),
                            round((right - left) / width, 5), round(span / height, 5)],
                })

    return {
        "size": [width, height],
        "horizontal": [round((r["a"] + r["b"] * centre) / height, 5) for r in h_rules],
        "vertical": [round((r["a"] + r["b"] * height / 2) / width, 5) for r in v_rules],
        "suggestion": suggestion,
    }


# ============================================================ per-document detection
#
# Every scanned page sits differently: shifted, scaled by the scanner, and
# tilted. The Detect step straightens the page, finds this page's own table,
# fits the template's columns and rows onto it, and outlines every line - so
# Staff see what will be read before anything goes to TrOCR.

MAX_SKEW_DEGREES = 10.0


def estimate_skew(image, max_degrees=MAX_SKEW_DEGREES):
    """Degrees to rotate the page (counter-clockwise, as PIL rotates) to level it.

    Coarse-to-fine search for the rotation that makes the dark pixels' row
    profile sharpest - printed rules and lines of writing are what line up -
    then refined from the slope of the printed rules themselves.
    """
    gray = image.convert("L")
    scale = min(1.0, 1000.0 / max(gray.size))
    small = gray.resize((max(1, int(gray.width * scale)), max(1, int(gray.height * scale)))) if scale < 1 else gray
    pixels = np.asarray(small, dtype=np.float32)
    dark = Image.fromarray(((pixels < np.percentile(pixels, 90) - 40) * 255).astype(np.uint8))

    def sharpness(angle):
        rotated = np.asarray(dark.rotate(float(angle), resample=Image.NEAREST), dtype=bool)
        profile = rotated.sum(axis=1).astype(np.float64)
        return float(np.sum(np.diff(profile) ** 2))

    best = max(np.arange(-max_degrees, max_degrees + 0.01, 0.5), key=sharpness)
    best = max(np.arange(best - 0.5, best + 0.51, 0.05), key=sharpness)

    # The printed rules give a more exact residual than the profile.
    levelled = gray.rotate(float(best), resample=Image.BILINEAR, expand=True, fillcolor=int(np.percentile(pixels, 90)))
    h_evidence, _ = _rule_pixels(np.asarray(levelled, dtype=np.uint8))
    rules, _ = _find_rules(h_evidence, "h", 0.3 * levelled.width)
    if rules:
        weights = np.array([r["hi"] - r["lo"] for r in rules], dtype=float)
        slope = float(np.average([r["b"] for r in rules], weights=weights))
        best += math.degrees(math.atan(slope))
    return float(np.clip(best, -max_degrees, max_degrees))


def straighten_page(image, degrees):
    """The page rotated by `degrees`, grown so no corner is cut off, on paper tone."""
    image = image.convert("RGB")
    paper = tuple(int(v) for v in np.percentile(np.asarray(image).reshape(-1, 3), 90, axis=0))
    return image.rotate(float(degrees), resample=Image.BICUBIC, expand=True, fillcolor=paper)


def _straightened_point(x, y, degrees, old_size, new_size):
    """Where page pixel (x, y) lands after straighten_page(image, degrees)."""
    (width, height), (new_width, new_height) = old_size, new_size
    cos, sin = math.cos(math.radians(degrees)), math.sin(math.radians(degrees))
    dx, dy = x - width / 2, y - height / 2
    return new_width / 2 + dx * cos + dy * sin, new_height / 2 - dx * sin + dy * cos


def grid_tilt(geometry):
    """The ledger's tilt as Staff marked it, in degrees clockwise (0 if none).

    Staff tilt each column marker on its own; the columns' typical (median)
    tilt is taken as the page's.
    """
    angles = [float(column.get("angle") or 0.0) for column in geometry.get("columns") or []]
    return float(np.median(angles)) if angles else 0.0


def _straighten_geometry(geometry, degrees, old_size, new_size, grid_frame):
    """The markers moved onto the page straightened by `degrees`.

    Every field corner goes where the page takes it, so a field keeps its
    place and loses the page's tilt from its own.

    Columns and row lines are moved only when `grid_frame` (Staff tilted the
    columns to match the page); otherwise they stay for Detect to refit. A
    column turned about its own centre lands with that centre where the page
    takes it, standing upright: what remains of its own tilt after the page's
    is a column slightly off the typical one, and the printed rule it is
    snapped to decides its edges anyway. Row lines keep their distance from
    the columns' tops.
    """
    (width, height), (new_width, new_height) = old_size, new_size
    moved = dict(geometry)
    columns = geometry.get("columns") or []
    if grid_frame and columns:
        placed = []
        for column in columns:
            x, y, w, h = [float(v) for v in column["box"]]
            cx, cy = _straightened_point((x + w / 2) * width, (y + h / 2) * height, degrees, old_size, new_size)
            placed.append((column, cx, cy, w * width, h * height))
        moved["columns"] = [
            {key: value for key, value in column.items() if key != "angle"} | {"box": [
                (cx - w / 2) / new_width, (cy - h / 2) / new_height, w / new_width, h / new_height,
            ]}
            for column, cx, cy, w, h in placed
        ]
        old_top = float(np.median([float(column["box"][1]) * height for column in columns]))
        new_top = float(np.median([cy - h / 2 for _, _, cy, _, h in placed]))
        moved["ruled_ys"] = [(new_top + float(y) * height - old_top) / new_height for y in geometry.get("ruled_ys") or []]

    fields = []
    for field in geometry.get("fields") or []:
        angle = float(field.get("angle") or 0.0)
        if field.get("polygon"):
            corners = [[float(x) * width, float(y) * height] for x, y in field["polygon"]]
        else:
            x, y, w, h = [float(v) for v in field["box"]]
            corners = _turned_rect_polygon(x * width, y * height, w * width, h * height, angle)
        placed = [_straightened_point(x, y, degrees, old_size, new_size) for x, y in corners]
        polygon = [[min(1.0, max(0.0, x / new_width)), min(1.0, max(0.0, y / new_height))] for x, y in placed]
        xs, ys = [p[0] for p in polygon], [p[1] for p in polygon]
        fields.append(field | {
            "polygon": polygon,
            "box": [min(xs), min(ys), max(1e-3, max(xs) - min(xs)), max(1e-3, max(ys) - min(ys))],
            "angle": round(angle - degrees, 3),
        })
    moved["fields"] = fields
    return moved


def outline_page(page_path, geometry, out_dir, detector=kraken_lines, debug=None):
    """Scan with OCR from Staff's markers, straightening a tilted page first.

    When Staff tilted the ledger columns to match the page, the page is
    straightened by their typical tilt and saved in place (as Detect does), so the
    outlines, the crops and the page shown in Verify share one pixel grid.
    The result then also carries "deskew" and the "geometry" used.
    """
    degrees = grid_tilt(geometry)
    if abs(degrees) < 0.05:
        return process_page(page_path, geometry, out_dir, detector=detector, debug=debug)

    image = Image.open(page_path).convert("RGB")
    straight = straighten_page(image, degrees)
    straight.save(page_path, "PNG")
    moved = _straighten_geometry(geometry, degrees, image.size, straight.size, grid_frame=True)

    result = process_page(page_path, moved, out_dir, detector=detector, debug=debug)
    result["deskew"] = round(degrees, 3)
    result["geometry"] = _snapped_geometry(result, moved)
    with open(os.path.join(out_dir, "lines.json"), "w", encoding="utf-8") as handle:
        json.dump(result, handle)
    return result


def _fit_1d(template, found, tolerance, prior=None, max_gap=None):
    """Scale and shift that put the most template positions on found positions.

    Every pair of template positions is tried against every pair of found
    positions; the mapping that lands the most template positions within
    `tolerance` of a found one wins, ties going to the smaller residual and
    then to the mapping closest to `prior` (scale, shift). A rule the page
    lacks - faded, or cut off at the page edge - just scores one fewer.
    """
    t = np.asarray(sorted(template), dtype=float)
    f = np.asarray(sorted(found), dtype=float)
    if len(t) < 2 or len(f) < 2:
        return None
    best_key, best = None, None
    max_gap = max_gap or len(t)
    for i in range(len(t)):
        for k in range(i + 1, min(len(t), i + 1 + max_gap)):
            for j in range(len(f)):
                for m in range(j + 1, min(len(f), j + 1 + max_gap)):
                    scale = (f[m] - f[j]) / (t[k] - t[i])
                    if not 0.6 <= scale <= 1.6:
                        continue
                    shift = f[j] - scale * t[i]
                    distance = np.abs(f[None, :] - (scale * t + shift)[:, None]).min(axis=1)
                    hits = int(np.sum(distance <= tolerance))
                    residual = float(np.sum(np.minimum(distance, tolerance)))
                    closeness = 0.0 if prior is None else abs(scale - prior[0]) + abs(shift - prior[1])
                    key = (hits, -round(residual / tolerance, 1), -closeness)
                    if best_key is None or key > best_key:
                        best_key, best = key, (float(scale), float(shift), hits)
    return best


def fit_geometry(image, geometry, found=None):
    """Place the template's columns, rows and fields on this page's own table.

    The template says what the table holds (column names, how many rows); the
    page says where it is.

    Columns: the template's column edges are fitted to the page's vertical
    rules by the scale and shift most edges agree on, so a border cut off at
    the page edge or a faded rule does not throw the rest off.

    Rows: the template's first row line is the top of the page's evenly
    ruled run (both come from the same detector), and the row spacing sets
    the scale. Counting matches would be fooled here - the header's own rules
    and the lines estimated below the last printed rule line up with each
    other one row off. Markers that already sit on the printed rules as well
    as the fit would put them are kept where Staff put them.

    Rectangle fields follow the same mapping. process_page then snaps every
    edge and row line to the printed rule it belongs to.
    """
    columns = geometry.get("columns") or []
    ruled = [float(y) for y in geometry.get("ruled_ys") or []]
    fields = geometry.get("fields") or []
    if not columns or len(ruled) < 2:
        return geometry, {"fitted": False, "reason": "This template has no ledger grid to fit."}

    found = found or detect_grid(image)
    edges = []
    for column in sorted(columns, key=lambda c: c["box"][0]):
        for edge in (column["box"][0], column["box"][0] + column["box"][2]):
            if not edges or edge - edges[-1] > 0.004:
                edges.append(edge)
    x_fit = _fit_1d(edges, found["vertical"], tolerance=0.006, prior=(1.0, 0.0))

    suggestion = found["suggestion"]
    printed = suggestion["ruled_ys"][:len(suggestion["ruled_ys"]) - int(suggestion.get("estimated_ys", 0))]

    # Any two edges can always be matched to two rules; at least three, and
    # half of a wide table's edges, must agree before the fit is trusted.
    if x_fit is None or x_fit[2] < max(3, math.ceil(0.5 * len(edges))) or len(printed) < 3:
        return geometry, {"fitted": False, "reason": "No ruled table matching this template was found on this page."}

    sx, tx, x_hits = x_fit
    sy = float(np.median(np.diff(printed))) / max(1e-6, float(np.median(np.diff(ruled))))
    ty = printed[0] - sy * ruled[0]

    # A faded or damaged rule can cut the ruled run short, and its top then
    # lies rows below the table's. Rows Staff already placed on the printed
    # rules are never traded for a fit that puts fewer of them there.
    reach = 0.25 * sy * float(np.median(np.diff(ruled)))
    horizontal = np.asarray(found["horizontal"] or printed, dtype=float)

    def row_hits(scale, shift):
        lines = shift + scale * np.asarray(ruled)
        return int(np.sum(np.abs(horizontal[None, :] - lines[:, None]).min(axis=1) <= reach))

    # A tie goes to Staff: when the markers already sit on as many printed
    # rules as the fit would put them on, they are left where Staff put them.
    # A header band as tall as a row lies in the ruled run, and matching rules
    # alone cannot tell it from the first row; Staff's placement can.
    if row_hits(1.0, 0.0) >= row_hits(sy, ty):
        sy, ty = 1.0, 0.0
    y_hits = len(printed)

    def clamp(value):
        return float(min(1.0, max(0.0, value)))

    # Rows the template has and the page lacks fall past its edge. Clamping them
    # would stack several rule lines on the edge, so the grid ends where the page does.
    new_ruled = [clamp(y) for y in _within_page([ty + sy * y for y in ruled])]
    top, bottom = new_ruled[0], new_ruled[-1]
    fitted = {
        "columns": [
            {"name": column["name"],
             "box": [clamp(tx + sx * column["box"][0]), top,
                     max(0.001, min(sx * column["box"][2], 1 - clamp(tx + sx * column["box"][0]))),
                     max(0.001, bottom - top)]}
            for column in columns
        ],
        "ruled_ys": new_ruled,
        "fields": [
            {**field, "box": [clamp(tx + sx * field["box"][0]), clamp(ty + sy * field["box"][1]),
                              max(0.001, sx * field["box"][2]), max(0.001, sy * field["box"][3])],
             **({"polygon": [[clamp(tx + sx * float(x)), clamp(ty + sy * float(y))] for x, y in field["polygon"]]}
                if field.get("polygon") else {})}
            for field in fields
        ],
    }
    return fitted, {
        "fitted": True,
        "column_edges_matched": f"{x_hits}/{len(edges)}",
        "printed_row_lines": y_hits,
    }


def _rows_moved(before, after):
    """Whole rows the first row line moved between two geometries (down is positive).

    Zero when it moved less than three quarters of a row: that is a nudge onto
    the printed rule, not a different row.
    """
    old = [float(y) for y in before.get("ruled_ys") or []]
    new = [float(y) for y in after.get("ruled_ys") or []]
    if len(old) < 2 or len(new) < 2 or np.median(np.diff(old)) <= 0:
        return 0
    rows = (new[0] - old[0]) / float(np.median(np.diff(old)))
    return int(round(rows)) if abs(rows) >= 0.75 else 0


def _within_page(ruled):
    """Row lines (page fractions) cut to the page.

    Rows wholly off the top or bottom edge hold nothing. The edge itself ends
    the grid when at least half a row of page is left there. Fewer than two
    rules on the page is left alone: there is nothing to cut down to.
    """
    ruled = [float(y) for y in ruled]
    inside = []
    for y in ruled:
        if 0.0 <= y <= 1.0 and (not inside or y > inside[-1] + 1e-4):
            inside.append(y)
    if len(ruled) < 2 or len(inside) < 2:
        return ruled
    pitch = float(np.median(np.diff(ruled)))
    if ruled[0] < 0.0 and inside[0] >= 0.5 * pitch:
        inside.insert(0, 0.0)
    if ruled[-1] > 1.0 and 1.0 - inside[-1] >= 0.5 * pitch:
        inside.append(1.0)
    return inside


def _snapped_geometry(result, fitted):
    """The geometry process_page actually used, after snapping to printed rules."""
    grid = result.get("grid")
    if not grid:
        return fitted
    width, height = result["size"]
    left, top, right, bottom = grid["table"]
    centre_x = (left + right) / 2
    centre_y = (top + bottom) / 2
    ruled = [(r["a"] + r["b"] * centre_x) / height for r in grid["rules"]]
    # A page can have fewer rows than the template: the rules estimated below
    # its last printed one then fall past the page's edge. Rows off the page
    # hold nothing, and a marker that extends beyond the page is refused when
    # the markers are sent back (Detect again, Scan, Snap), so the grid ends
    # where the page does.
    ruled = _within_page(ruled)
    columns = []
    for column in grid["columns"]:
        x0 = column["left"]["a"] + column["left"]["b"] * centre_y
        x1 = column["right"]["a"] + column["right"]["b"] * centre_y
        columns.append({
            "name": column["name"],
            "box": [round(x0 / width, 5), round(ruled[0], 5), round((x1 - x0) / width, 5), round(ruled[-1] - ruled[0], 5)],
        })
    return {"columns": columns, "ruled_ys": [round(y, 5) for y in ruled], "fields": fitted.get("fields") or []}


def detect_page(page_path, geometry, out_dir, detector=kraken_lines, debug=None):
    """Straighten a scanned page, fit the template to it, and outline every line.

    The straightened page replaces `page_path`, so everything downstream - the
    outlines, the crops, the page Staff see - shares one pixel grid. Returns
    process_page's result with the rotation applied, the fit, and the
    geometry that was used.
    """
    image = Image.open(page_path).convert("RGB")
    degrees = estimate_skew(image)
    if abs(degrees) >= 0.1:
        original_size = image.size
        image = straighten_page(image, degrees)
        image.save(page_path, "PNG")
        # Fields follow the page. Columns do too if Staff had already tilted
        # them with the page; upright columns are refitted below.
        geometry = _straighten_geometry(geometry, degrees, original_size, image.size,
                                        grid_frame=abs(grid_tilt(geometry)) >= 0.05)
    else:
        degrees = 0.0

    fitted, fit = fit_geometry(image, geometry)
    result = process_page(page_path, fitted, out_dir, detector=detector, debug=debug,
                          row_reach=FITTED_ROW_REACH if fit["fitted"] else ROW_REACH, detect_fields=True)
    result["deskew"] = round(degrees, 3)
    result["fit"] = fit
    result["geometry"] = _snapped_geometry(result, fitted)
    moved = _rows_moved(geometry, fitted) if fit["fitted"] else 0
    if moved:
        result["notes"].insert(0, {"code": NOTE_GRID_MOVED, "rows": moved})

    with open(os.path.join(out_dir, "lines.json"), "w", encoding="utf-8") as handle:
        json.dump(result, handle)
    return result


def _snap_to_lines(geometry, vertical, horizontal):
    """Each column edge and row line onto its own printed rule, when one is near.

    The fit moves and scales the whole grid at once; real pages are a little
    uneven, so each edge still lands a few pixels off its rule. Edges with no
    printed rule close by (a faded border, the estimated rows below the last
    printed one) stay where the fit put them.
    """
    def nearest(value, lines, reach):
        if not lines:
            return value
        best = min(lines, key=lambda line: abs(line - value))
        return best if abs(best - value) <= reach else value

    ruled = [float(y) for y in geometry.get("ruled_ys") or []]
    spacing = float(np.median(np.diff(ruled))) if len(ruled) >= 2 else 0.02
    ruled = [nearest(y, horizontal, 0.35 * spacing) for y in ruled]
    # Rows stay in order even if two were drawn to one rule.
    ruled = [y for k, y in enumerate(ruled) if k == 0 or y > ruled[k - 1] + 1e-4]

    columns = []
    top, bottom = (ruled[0], ruled[-1]) if ruled else (None, None)
    for column in geometry.get("columns") or []:
        x, y, w, h = [float(v) for v in column["box"]]
        left, right = nearest(x, vertical, 0.015), nearest(x + w, vertical, 0.015)
        if right - left < 0.005:
            left, right = x, x + w
        if top is not None:
            y, h = top, bottom - top
        columns.append({**column, "box": [round(left, 5), round(y, 5), round(right - left, 5), round(h, 5)]})

    return {**geometry, "columns": columns, "ruled_ys": [round(y, 5) for y in ruled]}


def snap_page(page_path, geometry):
    """Snap to table: the markers fitted to this page's printed table, fast.

    The column-and-row fit Detect makes, without straightening the page or
    finding any handwriting, so it takes a moment rather than a Kraken pass.
    Also returns every printed rule found (page fractions), for the markers'
    magnetic edges while Staff drag them.
    """
    image = Image.open(page_path).convert("RGB")
    found = detect_grid(image)
    fitted, fit = fit_geometry(image, geometry, found=found)
    if fit["fitted"]:
        fitted = _snap_to_lines(fitted, found["vertical"], found["horizontal"])
    return {
        "size": list(image.size),
        "fit": fit,
        "geometry": fitted,
        "lines": {"vertical": found["vertical"], "horizontal": found["horizontal"]},
    }


# ============================================================ CLI

def _read_json_arg(value):
    if value.startswith("@"):
        with open(value[1:], "r", encoding="utf-8") as handle:
            return json.load(handle)
    if os.path.isfile(value):
        with open(value, "r", encoding="utf-8") as handle:
            return json.load(handle)
    return json.loads(value)


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    commands = parser.add_subparsers(dest="command", required=True)

    process = commands.add_parser("process", help="Outline and crop every line on a page.")
    process.add_argument("--page", required=True)
    process.add_argument("--geometry", required=True, help="JSON file or string, page fractions.")
    process.add_argument("--out", required=True)

    detect = commands.add_parser("detect", help="Straighten a page, fit the template to it, and outline every line.")
    detect.add_argument("--page", required=True, help="Replaced by the straightened page.")
    detect.add_argument("--geometry", required=True, help="JSON file or string, page fractions.")
    detect.add_argument("--out", required=True)

    crop = commands.add_parser("crop", help="Crop one outline (manual correction).")
    crop.add_argument("--page", required=True)
    crop.add_argument("--polygon", required=True, help="JSON file or string, page pixels.")
    crop.add_argument("--out", required=True)
    crop.add_argument("--lines", help="lines.json of the page, to place the outline in a row.")

    grid = commands.add_parser("grid", help="Detect printed rules on a template sample.")
    grid.add_argument("--page", required=True)

    snap = commands.add_parser("snap", help="Fit the markers to the page's printed table (no line detection).")
    snap.add_argument("--page", required=True)
    snap.add_argument("--geometry", required=True, help="JSON file or string, page fractions.")

    args = parser.parse_args(argv)

    try:
        if args.command == "process":
            result = outline_page(args.page, _read_json_arg(args.geometry), args.out)
            summary = {
                "ok": True,
                "lines": len(result["lines"]),
                "flagged": sum(1 for line in result["lines"] if line["flags"]),
                **({"deskew": result["deskew"]} if "deskew" in result else {}),
                "detector_device": result["detector_device"],
                "timings": result["timings"],
            }
        elif args.command == "detect":
            result = detect_page(args.page, _read_json_arg(args.geometry), args.out)
            summary = {
                "ok": True,
                "lines": len(result["lines"]),
                "flagged": sum(1 for line in result["lines"] if line["flags"]),
                "deskew": result["deskew"],
                "fit": result["fit"],
                "detector_device": result["detector_device"],
                "timings": result["timings"],
            }
        elif args.command == "crop":
            polygon = _read_json_arg(args.polygon)
            _, bbox = crop_line(args.page, polygon, args.out)
            lines_json = _read_json_arg(args.lines) if args.lines else None
            column, row, flags = place_outline(polygon, lines_json)
            summary = {"ok": True, "bbox": bbox, "column_index": column, "row": row, "flags": flags}
        elif args.command == "snap":
            summary = {"ok": True, **snap_page(args.page, _read_json_arg(args.geometry))}
        else:
            summary = {"ok": True, **detect_grid(args.page)}
    except Exception as error:  # reported to Laravel as a message, not a traceback
        print(json.dumps({"ok": False, "error": f"{type(error).__name__}: {error}"}))
        return 1

    print(json.dumps(summary))
    return 0


if __name__ == "__main__":
    sys.exit(main())
