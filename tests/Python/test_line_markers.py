"""Tests for ml/line_markers.py on synthetic register pages.

The ledger tests draw a small ruled table with "handwriting" whose ownership is
known exactly: every stroke is drawn for one line. A fake detector stands in for
Kraken, so these run without the Kraken environment. The table tests need
scipy, scikit-image and shapely, which ml/.venv-kraken has:

    ml\\.venv-kraken\\Scripts\\python.exe -m unittest tests.Python.test_line_markers
"""

import importlib.util
import json
import os
import sys
import tempfile
import unittest
import unittest.mock

import numpy as np
from PIL import Image, ImageDraw

sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "..", "ml"))

import line_markers as lm  # noqa: E402

HAS_TABLE_STACK = all(importlib.util.find_spec(name) for name in ("scipy", "skimage", "shapely"))

WIDTH, HEIGHT = 800, 560
COLUMN_EDGES = [50, 300, 550, 750]
RULED = [100, 140, 180, 220, 260, 300, 340, 380]  # 7 rows, 40 px each
PAPER, INK, RULE = 238, 20, 120


def blank_page():
    image = Image.new("RGB", (WIDTH, HEIGHT), (PAPER, PAPER, PAPER))
    draw = ImageDraw.Draw(image)
    for y in RULED:
        draw.line([(COLUMN_EDGES[0], y), (COLUMN_EDGES[-1], y)], fill=(RULE,) * 3, width=1)
    for x in COLUMN_EDGES:
        draw.line([(x, RULED[0] - 40), (x, RULED[-1] + 60)], fill=(RULE,) * 3, width=1)
    return image


class Page:
    """A synthetic page that remembers which pixels each line's strokes cover."""

    def __init__(self):
        self.image = blank_page()
        self.truth = {}  # name -> bool mask of that line's strokes
        self.detected = []  # what the fake detector reports

    def word(self, name, x0, x1, base, drift=0.0, capital=0, tail=0, detect=True):
        mask = Image.new("L", (WIDTH, HEIGHT), 0)
        for target, colour in ((ImageDraw.Draw(self.image), (INK,) * 3), (ImageDraw.Draw(mask), 255)):
            points = []
            for k, x in enumerate(range(x0, x1 + 1, 6)):
                y = base + drift * (x - x0) / max(1, x1 - x0)
                points.append((x, y if k % 2 == 0 else y - 10))
            target.line(points, fill=colour, width=2)
            if capital:
                target.line([(x0, base), (x0, base - capital)], fill=colour, width=2)
            if tail:
                end = base + drift
                target.line([(x1, end), (x1, end + tail)], fill=colour, width=2)
        self.truth[name] = np.asarray(mask) > 0
        if detect:
            self.detected.append({
                "baseline": [[x0, base], [x1, base + drift]],
                "boundary": [[x0 - 2, base - 16], [x1 + 2, base + drift - 16],
                             [x1 + 2, base + drift + 6], [x0 - 2, base + 6]],
            })

    def detector(self, _image):
        return [dict(line) for line in self.detected]


def geometry(fields=None):
    top, bottom = RULED[0], RULED[-1]
    names = ["Name", "Date", "Place"]
    return {
        "columns": [
            {"name": names[k], "box": [COLUMN_EDGES[k] / WIDTH, top / HEIGHT,
                                       (COLUMN_EDGES[k + 1] - COLUMN_EDGES[k]) / WIDTH, (bottom - top) / HEIGHT]}
            for k in range(3)
        ],
        "ruled_ys": [y / HEIGHT for y in RULED],
        "fields": fields or [],
    }


def base_of(row):
    """Baseline of a line written on row `row`'s bottom rule (1-based)."""
    return RULED[row] - 6


def polygon_mask(polygon):
    mask = Image.new("L", (WIDTH, HEIGHT), 0)
    ImageDraw.Draw(mask).polygon([tuple(p) for p in polygon], fill=1, outline=1)
    return np.asarray(mask, bool)


def run(page, fields=None):
    folder = tempfile.mkdtemp()
    path = os.path.join(folder, "page.png")
    page.image.save(path)
    debug = {}
    result = lm.process_page(path, geometry(fields), os.path.join(folder, "out"), detector=page.detector, debug=debug)
    return result, folder, debug


def by_cell(result):
    cells = {}
    for line in result["lines"]:
        if line["source"] != lm.SOURCE_TEMPLATE:
            cells.setdefault((line["column"], line["row"]), []).append(line)
    return cells


class CropLineTest(unittest.TestCase):
    def test_everything_outside_the_polygon_is_blanked(self):
        image = Image.new("RGB", (100, 60), (0, 0, 0))
        triangle = [[10, 10], [90, 10], [10, 50]]
        crop, bbox = lm.crop_line(image, triangle, fill=(255, 255, 255))

        self.assertEqual([10, 10, 81, 41], bbox)
        pixels = np.asarray(crop)
        pad = lm.CROP_PADDING
        # Inside the triangle the page shows through; its far corner is blank.
        self.assertTrue((pixels[pad + 2, pad + 2] == 0).all())
        self.assertTrue((pixels[pad + 38, pad + 78] == 255).all())
        # The padding frame is blank too.
        self.assertTrue((pixels[0, :] == 255).all())

    def test_by_default_the_mask_is_filled_with_the_local_paper_tone(self):
        image = Image.new("RGB", (100, 60), (230, 215, 180))  # aged paper
        ImageDraw.Draw(image).line([(20, 30), (80, 30)], fill=(20, 20, 20), width=3)
        crop, _ = lm.crop_line(image, [[15, 20], [85, 20], [85, 40], [15, 40]])

        corner = np.asarray(crop)[0, 0]
        self.assertEqual((230, 215, 180), tuple(int(v) for v in corner))

    def test_an_outline_outside_the_page_is_refused(self):
        with self.assertRaises(ValueError):
            lm.crop_line(Image.new("RGB", (50, 50)), [[60, 60], [80, 60], [80, 80]])


class TemplateRectangleTest(unittest.TestCase):
    """Older templates: rectangles become four-point polygons, no detector."""

    def test_rectangles_are_cropped_as_four_point_polygons_without_the_detector(self):
        folder = tempfile.mkdtemp()
        path = os.path.join(folder, "page.png")
        blank_page().save(path)

        def detector(_image):
            raise AssertionError("A rectangle-only template must not run line detection.")

        result = lm.process_page(path, {"fields": [
            {"name": "Registry number", "box": [0.1, 0.05, 0.3, 0.05], "person_group": None},
            {"name": "Child", "box": [0.5, 0.05, 0.2, 0.05], "person_group": 1, "person_field_order": 0},
        ]}, os.path.join(folder, "out"), detector=detector)

        self.assertEqual(["Registry number", "Child"], [line["column"] for line in result["lines"]])
        first = result["lines"][0]
        self.assertEqual(lm.SOURCE_TEMPLATE, first["source"])
        self.assertEqual([[80, 28], [320, 28], [320, 56], [80, 56]], first["polygon"])
        self.assertEqual([], first["flags"])
        self.assertEqual(1, result["lines"][1]["person_group"])
        self.assertTrue(os.path.isfile(os.path.join(folder, "out", first["crop"])))
        self.assertTrue(os.path.isfile(os.path.join(folder, "out", "overlay.png")))
        with open(os.path.join(folder, "out", "lines.json"), encoding="utf-8") as handle:
            self.assertEqual(2, len(json.load(handle)["lines"]))


@unittest.skipUnless(HAS_TABLE_STACK, "needs scipy, scikit-image and shapely (ml/.venv-kraken)")
class LedgerTest(unittest.TestCase):
    def test_every_line_gets_one_outline_in_its_own_cell(self):
        page = Page()
        for row in (1, 2, 3):
            page.word(f"name{row}", 70, 250, base_of(row))
            page.word(f"date{row}", 320, 480, base_of(row))

        result, _, _ = run(page)
        cells = by_cell(result)

        for row in (1, 2, 3):
            self.assertEqual(1, len(cells[("Name", row)]))
            self.assertEqual(1, len(cells[("Date", row)]))
        self.assertTrue(all(not line["flags"] for line in result["lines"]))

    def test_a_line_that_drifts_across_its_rule_stays_in_its_row(self):
        page = Page()
        for row in (1, 2, 3, 4):
            page.word(f"name{row}", 70, 250, base_of(row))
        # Starts on row 2's rule and sinks well below it by the end.
        page.word("drifting", 320, 520, base_of(2), drift=18)
        page.word("date3", 320, 520, base_of(3))

        result, _, _ = run(page)
        cells = by_cell(result)

        self.assertEqual(1, len(cells[("Date", 2)]))
        self.assertEqual(1, len(cells[("Date", 3)]))
        self.assertEqual([], cells[("Date", 2)][0]["flags"])

    def test_a_line_merged_across_a_column_rule_is_split_at_the_rule(self):
        page = Page()
        page.word("name", 70, 280, base_of(2), detect=False)
        page.word("date", 318, 480, base_of(2), detect=False)
        page.detected.append({
            "baseline": [[70, base_of(2)], [480, base_of(2)]],
            "boundary": [[68, base_of(2) - 16], [482, base_of(2) - 16], [482, base_of(2) + 6], [68, base_of(2) + 6]],
        })

        result, _, debug = run(page)
        cells = by_cell(result)

        self.assertEqual(1, len(cells[("Name", 2)]))
        self.assertEqual(1, len(cells[("Date", 2)]))
        self.assertTrue(polygon_mask(cells[("Name", 2)][0]["polygon"])[page.truth["name"]].all())
        self.assertFalse(polygon_mask(cells[("Name", 2)][0]["polygon"])[page.truth["date"]].any())

    def test_capitals_and_tails_stay_whole_and_neighbours_stay_out(self):
        page = Page()
        page.word("above", 70, 250, base_of(2))
        # A capital reaching past the rule into the row above (where that
        # row's tails hang), and a tail reaching past the rule into the row below.
        page.word("middle", 80, 240, base_of(3), capital=36, tail=18)
        page.word("below", 70, 250, base_of(4))

        result, _, _ = run(page)
        cells = by_cell(result)
        outlines = {name: polygon_mask(cells[("Name", row)][0]["polygon"])
                    for name, row in (("above", 2), ("middle", 3), ("below", 4))}

        for name, outline in outlines.items():
            self.assertTrue(outline[page.truth[name]].all(), f"{name} has strokes outside its outline")
            for other, strokes in page.truth.items():
                if other != name:
                    self.assertFalse(outline[strokes].any(), f"{name}'s outline holds {other}'s strokes")

    def test_stacked_lines_in_one_cell_are_flagged_shared_cell(self):
        page = Page()
        for row in (1, 2, 3):
            page.word(f"name{row}", 70, 250, base_of(row))
        page.word("upper", 320, 480, base_of(2) - 18)
        page.word("lower", 320, 480, base_of(2))
        page.word("date1", 320, 480, base_of(1))
        page.word("date3", 320, 480, base_of(3))

        result, _, _ = run(page)
        shared = [line for line in result["lines"] if lm.FLAG_SHARED_CELL in line["flags"]]

        self.assertEqual(2, len(shared))
        self.assertEqual({("Date", 2)}, {(line["column"], line["row"]) for line in shared})

    def test_a_line_straddling_two_rows_is_flagged_no_row(self):
        page = Page()
        for row in (1, 2, 3, 4):
            page.word(f"name{row}", 70, 250, base_of(row))
        # Halfway between where row 2 and row 3 are written.
        page.word("straddling", 320, 480, base_of(2) + 20)

        result, _, _ = run(page)
        flagged = [line for line in result["lines"] if lm.FLAG_NO_ROW in line["flags"]]

        self.assertEqual(1, len(flagged))
        self.assertEqual("Date", flagged[0]["column"])
        self.assertIsNone(flagged[0]["row"])

    def test_ink_the_detector_missed_still_gets_a_line(self):
        page = Page()
        for row in (1, 2, 3):
            page.word(f"name{row}", 70, 250, base_of(row))
        page.word("missed", 330, 400, base_of(2), detect=False)

        result, _, _ = run(page)
        line = by_cell(result)[("Date", 2)][0]

        self.assertEqual(lm.SOURCE_INK, line["source"])
        self.assertTrue(polygon_mask(line["polygon"])[page.truth["missed"]].all())

    def test_audit_reports_clean_outlines(self):
        page = Page()
        for row in (1, 2, 3):
            page.word(f"name{row}", 70, 250, base_of(row), capital=30)

        result, _, debug = run(page)
        report = lm.audit_outlines(result, debug)

        self.assertEqual(3, len(report))
        self.assertTrue(all(r["own_outside"] == 0 and r["foreign_inside"] == 0 for r in report))

    def test_a_hand_drawn_outline_is_placed_in_its_row(self):
        page = Page()
        for row in (1, 2, 3):
            page.word(f"name{row}", 70, 250, base_of(row))

        result, _, _ = run(page)
        outline = [[330, base_of(2) - 22], [480, base_of(2) - 22], [480, base_of(2) + 8], [330, base_of(2) + 8]]
        column, row, flags = lm.place_outline(outline, result)

        self.assertEqual(1, column)
        self.assertEqual(2, row)
        self.assertEqual([], flags)


@unittest.skipUnless(HAS_TABLE_STACK, "needs scipy, scikit-image and shapely (ml/.venv-kraken)")
class DetectTest(unittest.TestCase):
    """Per-document Detect: straighten, fit the template to this page, outline."""

    def moved_page(self, dx, dy, scale):
        """The synthetic table drawn shifted and scaled, as a different scan would be."""
        page = Page()
        image = Image.new("RGB", (WIDTH, HEIGHT), (PAPER, PAPER, PAPER))
        draw = ImageDraw.Draw(image)
        ruled = [dy + scale * y for y in RULED]
        edges = [dx + scale * x for x in COLUMN_EDGES]
        for y in ruled:
            draw.line([(edges[0], y), (edges[-1], y)], fill=(RULE,) * 3, width=1)
        for x in edges:
            draw.line([(x, ruled[0] - 40), (x, ruled[-1] + 60)], fill=(RULE,) * 3, width=1)
        page.image = image
        return page, ruled, edges

    def test_the_tilt_of_a_page_is_measured(self):
        page = Page()
        for row in (1, 2, 3, 4):
            page.word(f"name{row}", 70, 250, base_of(row))
        tilted = page.image.rotate(-2.5, resample=Image.BICUBIC, expand=True, fillcolor=(PAPER,) * 3)

        self.assertAlmostEqual(2.5, lm.estimate_skew(tilted), delta=0.2)
        self.assertAlmostEqual(0.0, lm.estimate_skew(page.image), delta=0.2)

    def test_the_template_is_fitted_to_where_this_page_put_its_table(self):
        page, ruled, edges = self.moved_page(dx=37, dy=-22, scale=0.9)

        fitted, fit = lm.fit_geometry(page.image, geometry())

        self.assertTrue(fit["fitted"])
        for column, (left, right) in zip(fitted["columns"], zip(edges[:-1], edges[1:])):
            self.assertAlmostEqual(left, column["box"][0] * WIDTH, delta=3)
            self.assertAlmostEqual(right, (column["box"][0] + column["box"][2]) * WIDTH, delta=3)
        self.assertAlmostEqual(ruled[0], fitted["ruled_ys"][0] * HEIGHT, delta=3)
        self.assertAlmostEqual(ruled[-1], fitted["ruled_ys"][-1] * HEIGHT, delta=4)

    def test_snap_to_table_puts_every_column_edge_and_row_line_on_its_printed_rule(self):
        page, ruled, edges = self.moved_page(dx=37, dy=-22, scale=0.9)
        folder = tempfile.mkdtemp()
        path = os.path.join(folder, "page.png")
        page.image.save(path)

        result = lm.snap_page(path, geometry())
        snapped = result["geometry"]

        self.assertTrue(result["fit"]["fitted"])
        for column, (left, right) in zip(snapped["columns"], zip(edges[:-1], edges[1:])):
            self.assertAlmostEqual(left, column["box"][0] * WIDTH, delta=1.5)
            self.assertAlmostEqual(right, (column["box"][0] + column["box"][2]) * WIDTH, delta=1.5)
        for y, expected in zip(snapped["ruled_ys"], ruled):
            self.assertAlmostEqual(expected, y * HEIGHT, delta=1.5)
        # The printed lines themselves, for magnetic edges while dragging (a
        # faint or short one may be missed; the fit does not need them all).
        self.assertGreaterEqual(len(result["lines"]["vertical"]), len(edges) - 1)
        self.assertGreaterEqual(len(result["lines"]["horizontal"]), len(ruled))

    def test_a_page_without_a_matching_table_keeps_the_markers(self):
        blank = Image.new("RGB", (WIDTH, HEIGHT), (PAPER,) * 3)
        fitted, fit = lm.fit_geometry(blank, geometry())

        self.assertFalse(fit["fitted"])
        self.assertEqual(geometry()["columns"], fitted["columns"])

    def test_detect_outlines_a_moved_page_into_the_right_cells(self):
        page, ruled, edges = self.moved_page(dx=40, dy=-25, scale=0.9)
        base = lambda row: ruled[row] - 5  # noqa: E731
        for row in (1, 2, 3):
            page.word(f"name{row}", int(edges[0] + 20), int(edges[1] - 30), int(base(row)))
            page.word(f"date{row}", int(edges[1] + 20), int(edges[2] - 40), int(base(row)))

        folder = tempfile.mkdtemp()
        path = os.path.join(folder, "page.png")
        page.image.save(path)
        result = lm.detect_page(path, geometry(), os.path.join(folder, "out"), detector=page.detector)

        self.assertEqual(0.0, result["deskew"])
        self.assertTrue(result["fit"]["fitted"])
        cells = by_cell(result)
        for row in (1, 2, 3):
            self.assertEqual(1, len(cells[("Name", row)]))
            self.assertEqual(1, len(cells[("Date", row)]))
        self.assertAlmostEqual(ruled[0] / HEIGHT, result["geometry"]["ruled_ys"][0], delta=0.005)
        with open(os.path.join(folder, "out", "lines.json"), encoding="utf-8") as handle:
            self.assertIn("geometry", json.load(handle))


@unittest.skipUnless(HAS_TABLE_STACK, "needs scipy, scikit-image and shapely (ml/.venv-kraken)")
class FieldLinesTest(unittest.TestCase):
    """Detect on a drawn box: every written line inside it gets its own outline."""

    FIELD_BOX = [0.1, 0.25, 0.8, 0.55]  # x 80-720, y 140-448

    def ruled_paper(self):
        """A plain ruled page, no table: faint lines right across it."""
        page = Page()
        page.image = Image.new("RGB", (WIDTH, HEIGHT), (PAPER,) * 3)
        draw = ImageDraw.Draw(page.image)
        for y in range(120, HEIGHT, 40):
            draw.line([(10, y), (WIDTH - 10, y)], fill=(RULE,) * 3, width=1)
        return page

    def small_word(self, page, name, x0, x1, base):
        """Writing so small that its lowercase body is only 6 px tall."""
        mask = Image.new("L", (WIDTH, HEIGHT), 0)
        for target, colour in ((ImageDraw.Draw(page.image), (INK,) * 3), (ImageDraw.Draw(mask), 255)):
            target.line([(x, base if k % 2 == 0 else base - 5) for k, x in enumerate(range(x0, x1 + 1, 2))],
                        fill=colour, width=2)
        page.truth[name] = np.asarray(mask) > 0
        page.detected.append({
            "baseline": [[x0, base], [x1, base]],
            "boundary": [[x0 - 2, base - 16], [x1 + 2, base - 16], [x1 + 2, base + 6], [x0 - 2, base + 6]],
        })

    def detect(self, page):
        folder = tempfile.mkdtemp()
        path = os.path.join(folder, "page.png")
        page.image.save(path)
        debug = {}
        fields = [{"name": "Diseases", "box": self.FIELD_BOX, "person_group": None}]
        result = lm.detect_page(path, {"columns": [], "ruled_ys": [], "fields": fields},
                                os.path.join(folder, "out"), detector=page.detector, debug=debug)
        return result, debug

    def test_a_drawn_box_is_split_into_one_outline_per_written_line(self):
        page = self.ruled_paper()
        page.word("heading", 200, 400, 100)  # above the box: not part of the field
        for k, name in enumerate(("first", "second", "third")):
            # Each line is written as two words the detector reports separately.
            page.word(f"{name}-a", 120, 300, 196 + 40 * k)
            page.word(f"{name}-b", 340, 560, 196 + 40 * k)

        result, _ = self.detect(page)
        lines = result["lines"]

        self.assertEqual([lm.SOURCE_FIELD] * 3, [line["source"] for line in lines])
        self.assertEqual(["Diseases"] * 3, [line["column"] for line in lines])
        self.assertEqual([1, 2, 3], [line["row"] for line in lines])
        for line, name in zip(lines, ("first", "second", "third")):
            outline = polygon_mask(line["polygon"])
            self.assertTrue(outline[page.truth[f"{name}-a"] | page.truth[f"{name}-b"]].all(), name)
            for other, strokes in page.truth.items():
                if not other.startswith(name):
                    self.assertFalse(outline[strokes].any(), f"{name}'s outline holds {other}'s strokes")
            self.assertTrue(line["crop"].endswith(f"-field01-l{line['row']:02d}.png"))

    def test_small_writing_is_not_taken_for_a_printed_rule(self):
        # At low resolution a row of lowercase letters is thinner than the
        # rule finder's window, and used to be erased as print.
        page = self.ruled_paper()
        for k in range(3):
            self.small_word(page, f"line{k}", 120, 520, 196 + 40 * k)

        result, _ = self.detect(page)

        self.assertEqual(3, len(result["lines"]))
        for line, k in zip(result["lines"], range(3)):
            covered = polygon_mask(line["polygon"])[page.truth[f"line{k}"]]
            self.assertGreater(covered.mean(), 0.97, f"line {k + 1} lost its letters")

    def test_the_bar_of_a_capital_joins_the_line_below_it(self):
        page = self.ruled_paper()
        page.word("upper", 120, 500, 196)
        page.word("lower", 120, 500, 236)
        # A detached T-bar written high above "lower": 12 px under the upper
        # line's baseline, so it is nearer that line's middle than its own.
        mask = Image.new("L", (WIDTH, HEIGHT), 0)
        for target, colour in ((ImageDraw.Draw(page.image), (INK,) * 3), (ImageDraw.Draw(mask), 255)):
            target.line([(300, 209), (322, 208)], fill=colour, width=2)
        bar = np.asarray(mask) > 0

        result, _ = self.detect(page)
        upper, lower = (polygon_mask(line["polygon"]) for line in result["lines"])

        self.assertTrue(lower[bar].all())
        self.assertFalse(upper[bar].any())

    def test_rule_finding_rejects_fragments_as_thick_as_small_letters(self):
        evidence = np.zeros((100, 400), bool)
        evidence[20, 10:390] = True  # a hairline rule
        evidence[50:56, 100:220] = True  # a 6 px band: the body of small letters

        rules, pixels = lm._find_rules(evidence, "h", 80, max_thickness=3)

        self.assertEqual(1, len(rules))
        self.assertAlmostEqual(20, rules[0]["a"], delta=0.5)
        self.assertFalse(pixels[50:56].any())
        # A page border is as thick, but runs across the page: still print.
        evidence[80:86, 5:395] = True
        rules, _ = lm._find_rules(evidence, "h", 80, max_thickness=3)
        self.assertEqual(2, len(rules))


class TurnedMarkersTest(unittest.TestCase):
    """Markers Staff tilted by hand: fields turn alone, the ledger grid turns with the page."""

    def test_a_turned_field_is_cropped_level(self):
        # A dark 200 x 12 px stroke of "writing", turned 12 degrees clockwise.
        image = Image.new("RGB", (400, 300), (PAPER,) * 3)
        stroke = lm._turned_rect_polygon(100, 144, 200, 12, 12)
        ImageDraw.Draw(image).polygon([tuple(p) for p in stroke], fill=(INK,) * 3)
        field = lm._turned_rect_polygon(90, 125, 220, 50, 12)

        crop, _ = lm.crop_turned_box(image, field, fill=(255, 255, 255), padding=0)
        dark = np.asarray(crop.convert("L")) < 128

        self.assertEqual((220, 50), crop.size)
        rows = np.nonzero(dark.any(axis=1))[0]
        # Level: the stroke spans the crop's width within a band about as thick as it is.
        self.assertLessEqual(rows.max() - rows.min() + 1, 16)
        self.assertGreater(dark.any(axis=0).sum(), 190)

    def test_points_land_where_straightening_takes_them(self):
        image = Image.new("RGB", (500, 300), (PAPER,) * 3)
        ImageDraw.Draw(image).ellipse([395, 55, 405, 65], fill=(0, 0, 0))
        straight = lm.straighten_page(image, 7.0)

        ys, xs = np.nonzero(np.asarray(straight.convert("L")) < 100)
        expected = lm._straightened_point(400, 60, 7.0, image.size, straight.size)
        self.assertAlmostEqual(expected[0], xs.mean(), delta=1.0)
        self.assertAlmostEqual(expected[1], ys.mean(), delta=1.0)

    def test_a_turned_template_field_is_read_from_a_level_crop(self):
        folder = tempfile.mkdtemp()
        path = os.path.join(folder, "page.png")
        blank_page().save(path)

        result = lm.process_page(path, {"fields": [
            {"name": "Remarks", "box": [0.1, 0.05, 0.3, 0.05], "angle": 8},
        ]}, os.path.join(folder, "out"), detector=lambda _image: [])

        line = result["lines"][0]
        with Image.open(os.path.join(folder, "out", line["crop"])) as crop:
            size = crop.size
        # The field's own 240 x 28 px box plus padding, not the wider box around its turned corners.
        pad = 2 * lm.CROP_PADDING
        self.assertEqual((240 + pad, 28 + pad), size)
        self.assertEqual(4, len(line["polygon"]))

    @unittest.skipUnless(HAS_TABLE_STACK, "needs scipy, scikit-image and shapely (ml/.venv-kraken)")
    def test_ledger_columns_tilted_one_by_one_to_the_page_are_straightened_and_read(self):
        page = Page()
        for row in (1, 2, 3):
            page.word(f"name{row}", 70, 250, base_of(row))
            page.word(f"date{row}", 320, 480, base_of(row))
        # The scan came out turned 3 degrees clockwise. Staff tilted each column
        # marker 3 degrees and dragged it onto its column: its centre goes
        # where the turn of the page took it.
        page.image = page.image.rotate(-3, resample=Image.BICUBIC, fillcolor=(PAPER,) * 3)
        tilted = geometry()
        cos, sin = np.cos(np.radians(3)), np.sin(np.radians(3))
        tops = []
        for column in tilted["columns"]:
            x, y, w, h = column["box"]
            dx, dy = (x + w / 2 - 0.5) * WIDTH, (y + h / 2 - 0.5) * HEIGHT
            cx, cy = WIDTH / 2 + dx * cos - dy * sin, HEIGHT / 2 + dx * sin + dy * cos
            column["box"] = [cx / WIDTH - w / 2, cy / HEIGHT - h / 2, w, h]
            column["angle"] = 3
            tops.append(column["box"][1])
        # Row lines follow the columns' typical top, as the Align step sends them.
        top = float(np.median(tops))
        tilted["ruled_ys"] = [top + (y - RULED[0]) / HEIGHT for y in RULED]

        def detector(image):
            # Straightening grows the canvas; the writing moves with it.
            shift_x, shift_y = (image.width - WIDTH) / 2, (image.height - HEIGHT) / 2
            moved = []
            for line in page.detected:
                moved.append({key: [[x + shift_x, y + shift_y] for x, y in points] for key, points in line.items()})
            return moved

        folder = tempfile.mkdtemp()
        path = os.path.join(folder, "page.png")
        page.image.save(path)
        result = lm.outline_page(path, tilted, os.path.join(folder, "out"), detector=detector)

        self.assertEqual(3.0, result["deskew"])
        with Image.open(path) as saved:
            self.assertGreater(saved.width, WIDTH)  # saved straightened, in place
        cells = by_cell(result)
        for row in (1, 2, 3):
            self.assertEqual(1, len(cells[("Name", row)]), f"Name row {row}")
            self.assertEqual(1, len(cells[("Date", row)]), f"Date row {row}")
        self.assertTrue(all(not line["flags"] for line in result["lines"]))
        self.assertNotIn("angle", result["geometry"]["columns"][0])


@unittest.skipUnless(importlib.util.find_spec("kraken"), "needs Kraken (ml/.venv-kraken)")
class DetectorDeviceTest(unittest.TestCase):
    """Kraken on the GPU when there is one, and never a failed scan because of it."""

    def test_cpu_can_be_forced(self):
        self.assertEqual("cpu", lm.detector_device("cpu"))
        with unittest.mock.patch.dict(os.environ, {"LINE_MARKERS_DEVICE": "cpu"}):
            self.assertEqual("cpu", lm.detector_device())

    def test_a_failing_gpu_falls_back_to_the_cpu(self):
        from kraken import blla

        calls = []

        def segment(_image, device):
            calls.append(device)
            if device == "cuda":
                raise RuntimeError("CUDA out of memory")
            return type("Segmentation", (), {"lines": []})()

        with unittest.mock.patch.object(lm, "detector_device", return_value="cuda"), \
                unittest.mock.patch.object(blla, "segment", side_effect=segment):
            self.assertEqual([], lm.kraken_lines(Image.new("RGB", (40, 30), (PAPER,) * 3)))
        self.assertEqual(["cuda", "cpu"], calls)


if __name__ == "__main__":
    unittest.main()
