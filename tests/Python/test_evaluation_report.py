import hashlib
import json
import os
import tempfile
import unittest
from unittest import mock

from fastapi import HTTPException

import ml.api.main as api
from ml.api.main import EVALUATION_REPORT_FILE, _read_evaluation_report


class EvaluationReportTest(unittest.TestCase):
    def report(self, folder, weights=b"checkpoint"):
        weights_path = os.path.join(folder, "model.safetensors")
        with open(weights_path, "wb") as output:
            output.write(weights)

        report = {
            "schema_version": 1,
            "model_key": "trocr-v1",
            "dataset": "civil-records-v1",
            "manifest_sha256": "a" * 64,
            "split": "test",
            "sample_count": 5000,
            "metrics": {"cer": 0.001, "wer": 0.002, "exact_match": 0.99},
            "evaluated_at": "2026-08-12T00:00:00+00:00",
            "weights_file": "model.safetensors",
            "weights_sha256": hashlib.sha256(weights).hexdigest(),
        }
        with open(os.path.join(folder, EVALUATION_REPORT_FILE), "w", encoding="utf-8") as output:
            json.dump(report, output)

    def test_valid_report_is_normalized(self):
        with tempfile.TemporaryDirectory() as folder:
            self.report(folder)
            result = _read_evaluation_report(folder, strict=True)

        self.assertEqual("test", result["split"])
        self.assertEqual(5000, result["sample_count"])
        self.assertEqual(0.99, result["metrics"]["exact_match"])

    def test_report_for_different_weights_is_rejected(self):
        with tempfile.TemporaryDirectory() as folder:
            self.report(folder)
            with open(os.path.join(folder, "model.safetensors"), "wb") as output:
                output.write(b"different checkpoint")

            with self.assertRaises(HTTPException) as caught:
                _read_evaluation_report(folder, strict=True)

        self.assertEqual(400, caught.exception.status_code)
        self.assertIn("does not match", caught.exception.detail)


class BaseReportInstallTest(unittest.TestCase):
    """An upload's base-evaluation-report.json goes onto the base model only when
    it is for this computer's base weights and the same test set."""

    def setUp(self):
        self.workspace = tempfile.TemporaryDirectory()
        self.addCleanup(self.workspace.cleanup)
        self.base_dir = os.path.join(self.workspace.name, "base")
        self.model_dir = os.path.join(self.workspace.name, "trocr-v2")
        for folder in (self.base_dir, self.model_dir):
            os.makedirs(folder)
            with open(os.path.join(folder, "config.json"), "w", encoding="utf-8") as output:
                output.write("{}")
        self.base_weights = b"base checkpoint"
        with open(os.path.join(self.base_dir, "model.safetensors"), "wb") as output:
            output.write(self.base_weights)
        self.write(self.model_dir, EVALUATION_REPORT_FILE, self.report(b"fine-tuned"), weights=b"fine-tuned")
        patcher = mock.patch.object(api, "BASE_MODEL_DIR", self.base_dir)
        patcher.start()
        self.addCleanup(patcher.stop)

    def report(self, weights, dataset="names-50k", exact=0.99):
        return {
            "schema_version": 1, "model_key": "x", "dataset": dataset,
            "manifest_sha256": "a" * 64, "split": "test", "sample_count": 5000,
            "metrics": {"cer": 0.01, "wer": 0.02, "exact_match": exact},
            "evaluated_at": "2026-08-12T00:00:00+00:00",
            "weights_file": "model.safetensors",
            "weights_sha256": hashlib.sha256(weights).hexdigest(),
        }

    def write(self, folder, name, report, weights=None):
        if weights is not None:
            with open(os.path.join(folder, "model.safetensors"), "wb") as output:
                output.write(weights)
        with open(os.path.join(folder, name), "w", encoding="utf-8") as output:
            json.dump(report, output)

    def installed(self):
        path = os.path.join(self.base_dir, EVALUATION_REPORT_FILE)
        if not os.path.isfile(path):
            return None
        with open(path, encoding="utf-8") as source:
            return json.load(source)

    def test_no_base_report_changes_nothing(self):
        self.assertIsNone(api._install_base_report(self.model_dir))
        self.assertIsNone(self.installed())

    def test_matching_report_is_installed_and_the_old_one_kept(self):
        self.write(self.base_dir, EVALUATION_REPORT_FILE, self.report(self.base_weights, exact=0.30))
        self.write(self.model_dir, api.BASE_EVALUATION_REPORT_FILE, self.report(self.base_weights, exact=0.33))

        self.assertEqual("installed", api._install_base_report(self.model_dir))
        self.assertEqual(0.33, self.installed()["metrics"]["exact_match"])
        with open(os.path.join(self.base_dir, "evaluation-report.previous.json"), encoding="utf-8") as source:
            self.assertEqual(0.30, json.load(source)["metrics"]["exact_match"])
        self.assertEqual(0.33, api._read_evaluation_report(self.base_dir)["metrics"]["exact_match"])

        self.assertEqual("unchanged", api._install_base_report(self.model_dir))

    def test_report_for_other_base_weights_is_skipped(self):
        self.write(self.model_dir, api.BASE_EVALUATION_REPORT_FILE, self.report(b"someone else's base"))

        outcome = api._install_base_report(self.model_dir)

        self.assertTrue(outcome.startswith("skipped:"))
        self.assertIn("does not match", outcome)
        self.assertIsNone(self.installed())

    def test_report_from_another_test_set_is_skipped(self):
        self.write(self.model_dir, api.BASE_EVALUATION_REPORT_FILE,
                   self.report(self.base_weights, dataset="names-100k"))

        outcome = api._install_base_report(self.model_dir)

        self.assertTrue(outcome.startswith("skipped:"))
        self.assertIn("dataset", outcome)
        self.assertIsNone(self.installed())


if __name__ == "__main__":
    unittest.main()
