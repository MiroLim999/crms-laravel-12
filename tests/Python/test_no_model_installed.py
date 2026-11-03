"""A fresh install has no models (ml/models is not in git; models come from a USB
and are added by hand). The service then lists none, downloads nothing, and says
plainly that no model is installed."""
import os
import tempfile
import unittest
from unittest import mock

from fastapi.testclient import TestClient

from ml.api import main

KEY_HEADER = "X-CRMS-Service-Key"
# A 1x1 white PNG.
PIXEL = (
    "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8"
    "/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg=="
)


class NoModelInstalledTest(unittest.TestCase):
    def setUp(self):
        environment = mock.patch.dict(os.environ, {"OCR_UPLOAD_SECRET": "service-key-for-tests"})
        environment.start()
        self.addCleanup(environment.stop)

        # An empty models folder in place of ml/models; the real one is never touched.
        empty = tempfile.mkdtemp(prefix="crms-no-models-")
        for name, value in (("MODELS_DIR", empty), ("BASE_MODEL_DIR", os.path.join(empty, main.BASE_MODEL_KEY))):
            patcher = mock.patch.object(main, name, value)
            patcher.start()
            self.addCleanup(patcher.stop)

        # Nothing may be loaded or downloaded.
        loader = mock.patch.object(main, "load_model", side_effect=AssertionError("tried to load a model"))
        loader.start()
        self.addCleanup(loader.stop)

        self.client = TestClient(main.app)

    def key(self):
        return {KEY_HEADER: main._upload_secret().decode("utf-8")}

    def test_no_models_are_listed(self):
        self.assertEqual([], main._model_info())
        self.assertIsNone(main._default_key())

        health = self.client.get("/health").json()
        self.assertEqual([], health["models"])
        self.assertIsNone(health["default"])

    def test_reading_says_no_model_is_installed(self):
        for model in (None, main.BASE_MODEL_KEY, "no-such-model"):
            with self.subTest(model=model):
                response = self.client.post(
                    "/ocr",
                    json={"model": model, "fields": [{"name": "f", "image": PIXEL}]},
                    headers=self.key(),
                )
                self.assertEqual(503, response.status_code)
                self.assertIn("No OCR model is installed", response.json()["error"])


if __name__ == "__main__":
    unittest.main()
