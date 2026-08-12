import hashlib
import json
import os
import tempfile
import unittest

from fastapi import HTTPException

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


if __name__ == "__main__":
    unittest.main()
