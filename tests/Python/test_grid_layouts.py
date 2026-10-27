"""The row grid must hold on any ledger layout, not just the one the code was tuned on.

Synthetic evenly ruled ledgers with a fake detector that reports every written
cell exactly, so a wrong row can only be the grid logic's doing. Each layout
varies what real registers vary: columns, rows, row height, a header band, rules
the page lacks, a template that is a few percent off or has more rows than the
page, markers placed a little off.

Both ways in are checked, as the app runs them:
  Scan   = process_page(...)   Scan with OCR, no Detect
  Detect = detect_page(...)    the Detect button

Why these layouts: evenly ruled rows repeat, so a grid slid one row up or down
matches the printed rules exactly as well as the right one. On the real sample
pages (a header taller than a row, a template of 22 rows over pages of 20) the
Scan path slid the whole grid one row up onto the header: the header became
"Person 01", every person number was off by one, and the bottom rows were cut
in two. Layouts like these keep that from coming back.

    ml\\.venv-kraken\\Scripts\\python.exe -m unittest tests.Python.test_grid_layouts
"""

import importlib.util
import os
import sys
import tempfile
import unittest

import numpy as np
from PIL import Image, ImageDraw

sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "..", "ml"))

import line_markers as lm  # noqa: E402

HAS_TABLE_STACK = all(importlib.util.find_spec(name) for name in ("scipy", "skimage", "shapely"))

PAPER, INK, PRINT, RULE = 238, 20, 70, 120


class Ledger:
    """A synthetic ledger page, its written cells, and the template Staff would place on it."""

    def __init__(self, cols=4, rows=10, pitch=32, header=0.0, tpl_rows=None, pitch_err=0.0, dy=0.0,
                 unruled_bottom=0, cycle=None, no_h=False, no_v=False):
        s = pitch / 40.0
        col_w = int(220 * s)
        left = int(50 * s)
        width = left + cols * col_w + int(50 * s)
        top = int(140 * s)
        head = int(header * pitch)
        body_top = top + head
        ys = [body_top]
        for r in range(rows):
            ys.append(ys[-1] + pitch * (cycle[r % len(cycle)] if cycle else 1.0))
        height = int(ys[-1] + 90 * s)
        self.image = Image.new("RGB", (width, height), (PAPER,) * 3)
        draw = ImageDraw.Draw(self.image)
        edges = [left + k * col_w for k in range(cols + 1)]

        rules = ([top] if head else []) + ys
        for k, y in enumerate(rules):
            if unruled_bottom and len(rules) - 1 - k < unruled_bottom:
                continue
            if not no_h:
                draw.line([(edges[0], y), (edges[-1], y)], fill=(RULE,) * 3, width=1)
        if not no_v:
            for x in edges:
                draw.line([(x, top), (x, ys[-1] - (unruled_bottom * pitch if unruled_bottom else 0))],
                          fill=(RULE,) * 3, width=1)

        self.detected, self.cells = [], []
        step, ink_width, amp = max(4, int(6 * s)), max(2, int(round(2 * s))), 0.25 * pitch

        def word(x0, x1, base, colour):
            points = [(x, base if k % 2 == 0 else base - amp) for k, x in enumerate(range(int(x0), int(x1) + 1, step))]
            draw.line(points, fill=(colour,) * 3, width=ink_width)
            self.detected.append({
                "baseline": [[float(x0), float(base)], [float(x1), float(base)]],
                "boundary": [[x0 - 2, base - 0.4 * pitch], [x1 + 2, base - 0.4 * pitch],
                             [x1 + 2, base + 0.15 * pitch], [x0 - 2, base + 0.15 * pitch]],
            })

        if head:  # printed column titles: the detector finds them like handwriting
            for c in range(cols):
                base = top + head * 0.62
                word(edges[c] + 10 * s, edges[c + 1] - 10 * s, base, PRINT)
                self.cells.append({"row": -1, "y": base, "x": edges[c] + col_w / 2})
        for r in range(rows):
            base = ys[r + 1] - 0.15 * pitch
            for c in range(cols):
                x0, x1 = edges[c] + 8 * s, edges[c + 1] - 8 * s
                word(x0, x1, base, INK)
                self.cells.append({"row": r, "y": base, "x": (x0 + x1) / 2})

        tpl_rows = tpl_rows or rows
        if cycle:
            extended = list(ys)
            for r in range(len(extended) - 1, tpl_rows):
                extended.append(extended[-1] + pitch * cycle[r % len(cycle)])
            ruled = [min(1.0, (y + dy * pitch) / height) for y in extended[:tpl_rows + 1]]
        else:
            step_y = pitch * (1 + pitch_err)
            ruled = [min(1.0, (body_top + dy * pitch + k * step_y) / height) for k in range(tpl_rows + 1)]
        self.geometry = {
            "columns": [{"name": f"C{c + 1}", "box": [edges[c] / width, ruled[0], col_w / width, ruled[-1] - ruled[0]]}
                        for c in range(cols)],
            "ruled_ys": ruled,
            "fields": [],
        }
        self.rows, self.cols = rows, cols

    def detector(self, _image):
        return [dict(line) for line in self.detected]

    def run(self, way):
        folder = tempfile.mkdtemp()
        path = os.path.join(folder, "page.png")
        self.image.save(path)
        out = os.path.join(folder, "out")
        if way == "scan":
            return lm.process_page(path, self.geometry, out, detector=self.detector)
        return lm.detect_page(path, self.geometry, out, detector=self.detector)

    def rows_of(self, result):
        """(right, wrong, flagged, header_lines_in_the_grid) for every line the run returned."""
        right = wrong = flagged = header = 0
        for line in result["lines"]:
            if line["source"] not in (lm.SOURCE_DETECTED, lm.SOURCE_INK) or not line.get("baseline"):
                continue
            y = float(np.median([p[1] for p in line["baseline"]]))
            x = float(np.mean([p[0] for p in line["baseline"]]))
            cell = min(self.cells, key=lambda c: abs(c["y"] - y) + 0.05 * abs(c["x"] - x))
            if cell["row"] < 0:
                header += 1
            elif lm.FLAG_NO_ROW in line["flags"]:
                flagged += 1
            elif line["row"] == cell["row"] + 1:
                right += 1
            else:
                wrong += 1
        return right, wrong, flagged, header


@unittest.skipUnless(HAS_TABLE_STACK, "needs scipy, scikit-image and shapely (ml/.venv-kraken)")
class RowGridTest(unittest.TestCase):
    def assertEveryRowRight(self, ledger, ways=("scan", "detect")):
        for way in ways:
            right, wrong, flagged, header = ledger.rows_of(ledger.run(way))
            total = ledger.rows * ledger.cols
            self.assertEqual((total, 0, 0, 0), (right, wrong, flagged, header),
                             f"{way}: right, wrong, flagged, header lines in the grid")

    def test_the_layout_the_template_was_drawn_on(self):
        self.assertEveryRowRight(Ledger())

    def test_a_header_taller_than_a_row_is_not_taken_for_a_row(self):
        self.assertEveryRowRight(Ledger(header=1.3))

    def test_a_template_with_more_rows_than_the_page_and_a_slightly_smaller_row_height(self):
        # The shape of the real sample pages: header 1.3 rows tall, template rows
        # 5% short, the template 2 rows longer than the page. Scan used to slide the
        # whole grid one row up onto the header.
        self.assertEveryRowRight(Ledger(cols=6, rows=12, header=1.3, pitch_err=-0.05, tpl_rows=14))

    def test_the_same_without_a_header(self):
        self.assertEveryRowRight(Ledger(cols=6, rows=12, pitch_err=-0.05, tpl_rows=14))

    def test_a_template_with_more_rows_than_the_page_and_the_right_row_height(self):
        self.assertEveryRowRight(Ledger(rows=10, header=1.3, tpl_rows=12))

    def test_row_height_from_small_to_large_scans(self):
        for pitch in (26, 80):
            with self.subTest(pitch=pitch):
                self.assertEveryRowRight(Ledger(pitch=pitch, header=1.3, tpl_rows=12))

    def test_markers_placed_a_little_off(self):
        for dy in (-0.3, 0.3):
            with self.subTest(dy=dy):
                self.assertEveryRowRight(Ledger(dy=dy))

    def test_a_header_exactly_as_tall_as_a_row_when_the_markers_sit_on_the_first_entry(self):
        # Rule matching cannot tell such a header from a row. Scan trusts Staff's placement.
        self.assertEveryRowRight(Ledger(header=1.0, tpl_rows=12, pitch_err=0.02), ways=("scan",))
        # Detect leaves markers that already sit on as many printed rules as its fit would.
        self.assertEveryRowRight(Ledger(header=1.0, pitch_err=0.02))

    def test_rows_whose_lines_the_page_lacks_at_the_bottom(self):
        self.assertEveryRowRight(Ledger(unruled_bottom=2))

    def test_a_register_with_no_printed_row_lines_or_no_column_lines(self):
        self.assertEveryRowRight(Ledger(header=1.3, no_h=True))
        self.assertEveryRowRight(Ledger(header=1.3, no_v=True))

    def test_rows_of_different_heights(self):
        self.assertEveryRowRight(Ledger(header=1.3, cycle=[1.0, 1.6, 1.0, 1.0, 1.4], tpl_rows=12))

    def test_a_clean_page_has_nothing_to_report(self):
        for way in ("scan", "detect"):
            self.assertEqual([], Ledger(header=1.3).run(way)["notes"], way)


@unittest.skipUnless(HAS_TABLE_STACK, "needs scipy, scikit-image and shapely (ml/.venv-kraken)")
class GridNotesTest(unittest.TestCase):
    """When the grid may be wrong, Staff are told; nothing is fixed behind their back."""

    def codes(self, result):
        return {note["code"]: note for note in result["notes"]}

    def test_rows_below_the_template_are_reported_not_silently_dropped(self):
        # A template of 10 rows over a page of 14: rows 11 to 14 are not read, and Staff are told.
        ledger = Ledger(cols=4, rows=14, header=1.3, tpl_rows=10)
        result = ledger.run("scan")

        note = self.codes(result)[lm.NOTE_LINES_BELOW]
        self.assertEqual({"code": lm.NOTE_LINES_BELOW, "count": 4 * 4, "rows": 4}, note)
        self.assertEqual(4 * 10, sum(1 for line in result["lines"] if line["source"] != lm.SOURCE_TEMPLATE))

    def test_a_row_of_entries_above_the_first_row_is_reported(self):
        # Markers a whole row too low: the first entries lie above the grid.
        result = Ledger(dy=1.0).run("scan")

        self.assertEqual(4, self.codes(result)[lm.NOTE_LINES_ABOVE]["count"])

    def test_a_header_above_the_grid_stays_quiet(self):
        for way in ("scan", "detect"):
            self.assertNotIn(lm.NOTE_LINES_ABOVE, self.codes(Ledger(header=1.3).run(way)), way)

    def test_a_grid_on_the_header_has_a_row_that_differs_in_height(self):
        # Markers a whole row (and the header) too high: the first row band spans the header.
        result = Ledger(header=1.3, dy=-1.3).run("scan")

        self.assertIn(lm.NOTE_ROWS_UNEVEN, self.codes(result))
        self.assertIn(1, self.codes(result)[lm.NOTE_ROWS_UNEVEN]["rows"])

    def test_detect_says_when_it_moved_the_grid_whole_rows(self):
        # Detect still carries far-off markers onto the table (as Snap to table does), and says so.
        result = Ledger(dy=1.0).run("detect")

        self.assertEqual({"code": lm.NOTE_GRID_MOVED, "rows": -1}, self.codes(result)[lm.NOTE_GRID_MOVED])
        self.assertEqual(4 * 10, sum(1 for l in result["lines"] if l["source"] != lm.SOURCE_TEMPLATE))

    def test_detect_reports_it_when_a_header_as_tall_as_a_row_pulls_the_grid_up(self):
        # With more template rows than page rows the header's rule is one more match for a grid
        # slid up a row, and only the writing could say the row is a header. Detect moves the
        # grid (it must, to carry far-off markers onto the table) and says so.
        result = Ledger(header=1.0, tpl_rows=12).run("detect")

        self.assertEqual(-1, self.codes(result)[lm.NOTE_GRID_MOVED]["rows"])

    def test_detect_does_not_report_a_nudge(self):
        self.assertNotIn(lm.NOTE_GRID_MOVED, self.codes(Ledger(dy=0.3).run("detect")))


@unittest.skipUnless(HAS_TABLE_STACK, "needs scipy, scikit-image and shapely (ml/.venv-kraken)")
class FreeTextUnderALedgerTemplateTest(unittest.TestCase):
    """A ledger template over a page of free text (a list of diseases, not a register).

    The template's 22 rows squeezed over lines of writing several times taller
    than a row put words in different rows, flagged most of the page and split
    lines in two. The same box added as a field gave one clean line per written line.
    """

    def page(self):
        # Six lines of writing 60 px apart on a page with no printed rows, under a marker whose template rows
        # are 13.8 px high (not a divisor of 60: lines a whole number of rows apart would look aligned).
        return Ledger(cols=1, rows=6, pitch=60, pitch_err=-0.77, tpl_rows=26, no_h=True)

    def test_the_columns_are_read_as_free_text_line_by_line(self):
        for way in ("scan", "detect"):
            with self.subTest(way=way):
                result = self.page().run(way)
                lines = [line for line in result["lines"] if line["source"] != lm.SOURCE_TEMPLATE]

                self.assertEqual(6, len(lines))
                self.assertEqual({lm.SOURCE_FIELD}, {line["source"] for line in lines})
                self.assertEqual([1, 2, 3, 4, 5, 6], sorted(line["row"] for line in lines))
                self.assertEqual({"C1"}, {line["column"] for line in lines})
                self.assertEqual([[]] * 6, [line["flags"] for line in lines])
                self.assertIn({"code": lm.NOTE_ROWS_DO_NOT_FIT, "count": 1}, result["notes"])

    def test_a_real_ledger_is_left_alone(self):
        for way in ("scan", "detect"):
            with self.subTest(way=way):
                result = Ledger(cols=3, rows=8, header=1.3).run(way)

                self.assertNotIn(lm.NOTE_ROWS_DO_NOT_FIT, [note["code"] for note in result["notes"]])
                self.assertEqual({lm.SOURCE_DETECTED}, {line["source"] for line in result["lines"]})


@unittest.skipUnless(HAS_TABLE_STACK, "needs scipy, scikit-image and shapely (ml/.venv-kraken)")
class WrittenWordIsNotARuleTest(unittest.TestCase):
    """The low joins of a cursive word can look like a piece of printed rule.

    On a real page the body of "Legitimate" (82 px long, 4 px thick) was erased
    as a rule, and TrOCR read what was left as "Iegt-et".
    """

    def page(self):
        image = Image.new("L", (900, 300), PAPER)
        draw = ImageDraw.Draw(image)
        for y in (100, 150, 200):  # printed rules across the table
            draw.line([(40, y), (860, y)], fill=RULE, width=1)
        # A cursive word body in row 2: a low, thin, wavy streak 90 px long, 4 px thick,
        # 12 px above its row's rule - and a detected line of writing around it.
        xs = np.arange(600, 690)
        for x in xs:
            y = 136 + int(round(1.5 * np.sin(x / 6.0)))
            draw.line([(int(x), y), (int(x), y + 3)], fill=INK)
        writing = np.zeros((300, 900), bool)
        writing[112:152, 590:700] = True
        return np.asarray(image, dtype=np.uint8), writing

    def test_a_stretch_of_writing_is_kept_when_the_detector_saw_writing_there(self):
        gray, writing = self.page()
        h_evidence, _ = lm._rule_pixels(gray)
        word = (slice(130, 144), slice(600, 690))

        _, erased = lm._find_rules(h_evidence, "h", 300, max_thickness=6)
        self.assertTrue(erased[word].any(), "without the writing mask the word body passes for a rule")

        rules, kept = lm._find_rules(h_evidence, "h", 300, max_thickness=6, writing=writing, near=8)
        self.assertFalse(kept[word].any(), "the word body is kept as ink")
        self.assertEqual(3, len(rules))
        self.assertTrue(kept[150, 100:800].all(), "the printed rule under the writing is still erased")


@unittest.skipUnless(HAS_TABLE_STACK, "needs scipy, scikit-image and shapely (ml/.venv-kraken)")
class RowsFromThePageTest(unittest.TestCase):
    """Snap to table and Detect take the rows from the page, not the template's count.

    The template's grid on copies of the real sample page kept 20 rows over a
    table of 25 (the last 5 entries never read) and over one of 15 (5 rows past
    the table).
    """

    def snap(self, ledger):
        folder = tempfile.mkdtemp()
        path = os.path.join(folder, "page.png")
        ledger.image.save(path)
        return lm.snap_page(path, ledger.geometry)

    def rows(self, geometry):
        return len(geometry["ruled_ys"]) - 1

    def page_rules(self, ledger):
        """Page pixels of the printed row rules (template drawn on the page, rows as printed)."""
        return [y * ledger.image.height for y in ledger.geometry["ruled_ys"]]

    def erase_rule(self, ledger, y):
        columns = ledger.geometry["columns"]
        left = columns[0]["box"][0] * ledger.image.width
        right = (columns[-1]["box"][0] + columns[-1]["box"][2]) * ledger.image.width
        ImageDraw.Draw(ledger.image).line([(left + 2, y), (right - 2, y)], fill=(PAPER,) * 3, width=3)

    def test_a_page_with_more_rows_than_the_template_is_read_row_for_row(self):
        ledger = Ledger(cols=4, rows=14, header=1.3, tpl_rows=10)

        snapped = self.snap(ledger)
        self.assertEqual(14, self.rows(snapped["geometry"]))
        self.assertEqual({"from_page": True, "count": 14, "template": 10, "estimated": []}, snapped["fit"]["rows"])

        result = ledger.run("detect")
        self.assertEqual(14, self.rows(result["geometry"]))
        self.assertEqual((14 * 4, 0, 0, 0), ledger.rows_of(result))
        self.assertNotIn(lm.NOTE_LINES_BELOW, [note["code"] for note in result["notes"]])

    def test_a_page_with_fewer_rows_ends_the_grid_at_its_table(self):
        ledger = Ledger(cols=4, rows=8, header=1.3, tpl_rows=12)

        snapped = self.snap(ledger)
        self.assertEqual(8, self.rows(snapped["geometry"]))
        bottom = snapped["geometry"]["ruled_ys"][-1]
        for column in snapped["geometry"]["columns"]:
            self.assertAlmostEqual(bottom, column["box"][1] + column["box"][3], places=4)

        result = ledger.run("detect")
        self.assertEqual(8, self.rows(result["geometry"]))
        self.assertEqual((8 * 4, 0, 0, 0), ledger.rows_of(result))

    def test_rows_staff_set_by_hand_are_kept_by_detect(self):
        ledger = Ledger(cols=4, rows=14, header=1.3, tpl_rows=10)
        ledger.geometry["keep_rows"] = True

        result = ledger.run("detect")

        self.assertEqual(10, self.rows(result["geometry"]))
        self.assertIn(lm.NOTE_LINES_BELOW, [note["code"] for note in result["notes"]])
        # Snap to table is asked for: it always takes the page's rows.
        self.assertEqual(14, self.rows(self.snap(ledger)["geometry"]))

    def test_a_faded_rule_keeps_its_row_and_is_marked_estimated(self):
        for faded in (2, 9):  # near the top: the page's run starts below it; near the bottom: it ends above it
            with self.subTest(faded=faded):
                ledger = Ledger(cols=4, rows=12, header=1.3)
                rules = self.page_rules(ledger)
                self.erase_rule(ledger, rules[faded])

                snapped = self.snap(ledger)
                self.assertEqual(12, self.rows(snapped["geometry"]))
                for y, expected in zip(snapped["geometry"]["ruled_ys"], rules):
                    self.assertAlmostEqual(expected, y * ledger.image.height, delta=2)
                self.assertEqual([faded], snapped["fit"]["rows"]["estimated"])

                result = ledger.run("detect")
                self.assertEqual((12 * 4, 0, 0, 0), ledger.rows_of(result))
                self.assertEqual([faded], result["geometry"]["estimated_rows"])

    def test_rows_guessed_down_to_a_footer_line_stop_at_the_table(self):
        # A signature line well below the table: the rows the page's run guesses down to it lie
        # outside the table's column lines, so they are not rows.
        ledger = Ledger(cols=4, rows=8, header=1.3)
        rules = self.page_rules(ledger)
        pitch = rules[1] - rules[0]
        footer = rules[-1] + 4.6 * pitch
        canvas = Image.new("RGB", (ledger.image.width, int(footer + 2 * pitch)), (PAPER,) * 3)
        canvas.paste(ledger.image, (0, 0))
        ImageDraw.Draw(canvas).line([(60, footer), (canvas.width - 60, footer)], fill=(RULE,) * 3, width=1)
        scale = ledger.image.height / canvas.height
        ledger.image = canvas
        ledger.geometry = {
            **ledger.geometry,
            "columns": [{**c, "box": [c["box"][0], c["box"][1] * scale, c["box"][2], c["box"][3] * scale]}
                        for c in ledger.geometry["columns"]],
            "ruled_ys": [y * scale for y in ledger.geometry["ruled_ys"]],
        }

        self.assertGreater(lm.detect_grid(canvas)["suggestion"]["estimated_ys"], 0, "the run guesses rows")
        self.assertEqual(8, self.rows(self.snap(ledger)["geometry"]))

    def test_unruled_rows_with_writing_are_kept_by_detect(self):
        # The last two rows have no printed lines at all. Detect sees their writing and keeps the
        # template's rows there; Snap cannot see writing, and leaves those rows for Staff to add.
        ledger = Ledger(unruled_bottom=2)

        self.assertEqual(10, self.rows(ledger.run("detect")["geometry"]))
        self.assertEqual(8, self.rows(self.snap(ledger)["geometry"]))

    def test_rows_of_different_heights_keep_the_template_rows(self):
        ledger = Ledger(header=1.3, cycle=[1.0, 1.6, 1.0, 1.0, 1.4], tpl_rows=12)

        rows = self.snap(ledger)["fit"]["rows"]

        self.assertEqual(("uneven_rows", False, 12), (rows["reason"], rows["from_page"], rows["count"]))

    def test_a_template_of_another_row_height_keeps_its_rows(self):
        fitted = {"columns": [{"name": "C1", "box": [0.1, 0.2, 0.8, 0.5]}], "ruled_ys": [0.2, 0.3, 0.4, 0.5, 0.6, 0.7]}
        found = {"horizontal": [0.2, 0.25, 0.3], "suggestion": {"ruled_ys": [0.2, 0.25, 0.3, 0.35], "estimated_ys": 0}}

        geometry, rows = lm._rows_from_page(fitted, found)

        self.assertIs(fitted, geometry)
        self.assertEqual("row_height", rows["reason"])

    def test_the_table_frame_is_found(self):
        ledger = Ledger(cols=4, rows=8, header=1.3)
        frame = lm.detect_grid(ledger.image)["suggestion"]["frame_bottom"]

        self.assertAlmostEqual(self.page_rules(ledger)[-1], frame * ledger.image.height, delta=2)


@unittest.skipUnless(HAS_TABLE_STACK, "needs scipy, scikit-image and shapely (ml/.venv-kraken)")
class GridPastThePageTest(unittest.TestCase):
    def test_the_geometry_detect_saves_ends_where_the_page_does(self):
        # The template has 4 more rows than the page, so rules are estimated past its bottom edge.
        # A marker that extends beyond the page is refused when the markers are sent back.
        result = Ledger(cols=4, rows=8, header=1.3, pitch_err=-0.05, tpl_rows=12).run("detect")
        geometry = result["geometry"]

        ruled = geometry["ruled_ys"]
        self.assertLessEqual(max(ruled), 1.0)
        self.assertTrue(all(b > a for a, b in zip(ruled, ruled[1:])), ruled)
        for column in geometry["columns"]:
            self.assertLessEqual(column["box"][1] + column["box"][3], 1.00001)

    def test_within_page(self):
        self.assertEqual([0.2, 0.4, 0.6], lm._within_page([0.2, 0.4, 0.6]))
        # Rows past the bottom edge go; the edge ends the grid when half a row or more of page is left.
        self.assertEqual([0.5, 0.75, 1.0], lm._within_page([0.5, 0.75, 1.0, 1.25, 1.5]))
        self.assertEqual([0.5, 0.7, 0.85, 1.0], lm._within_page([0.5, 0.7, 0.85, 1.1, 1.3]))
        self.assertEqual([0.5, 0.7, 0.95], lm._within_page([0.5, 0.7, 0.95, 1.1, 1.3]))
        # The same at the top edge.
        self.assertEqual([0.0, 0.25, 0.5, 0.75], lm._within_page([-0.1, 0.25, 0.5, 0.75]))
        self.assertEqual([0.0, 0.3, 0.6], lm._within_page([-0.2, 0.0, 0.3, 0.6]))
        # Nothing to cut down to: left alone.
        self.assertEqual([1.2, 1.4], lm._within_page([1.2, 1.4]))


if __name__ == "__main__":
    unittest.main()
