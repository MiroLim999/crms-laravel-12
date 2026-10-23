import os
import unittest
from unittest import mock

from fastapi.testclient import TestClient

from ml.api import main

KEY_HEADER = "X-CRMS-Service-Key"
# A name no model folder can have, so nothing real is ever touched.
MISSING_MODEL = "no-such-model-for-tests"


class ServiceKeyTest(unittest.TestCase):
    def setUp(self):
        # Its own secret, so the test does not depend on the machine's .env.
        environment = mock.patch.dict(os.environ, {"OCR_UPLOAD_SECRET": "service-key-for-tests"})
        environment.start()
        self.addCleanup(environment.stop)
        # Without `with`, the app's lifespan (model discovery) does not run.
        self.client = TestClient(main.app)

    def key(self):
        return {KEY_HEADER: main._upload_secret().decode("utf-8")}

    def test_delete_model_without_the_key_is_refused(self):
        response = self.client.post("/delete_model", json={"model": MISSING_MODEL})

        self.assertEqual(401, response.status_code)

    def test_a_wrong_key_is_refused(self):
        response = self.client.post(
            "/delete_model", json={"model": MISSING_MODEL}, headers={KEY_HEADER: "not-the-key"}
        )

        self.assertEqual(401, response.status_code)

    def test_the_right_key_gets_past_the_check(self):
        response = self.client.post("/delete_model", json={"model": MISSING_MODEL}, headers=self.key())

        # Refused for being unknown, not for the key.
        self.assertEqual(404, response.status_code)

    def test_every_protected_call_needs_the_key(self):
        calls = [
            ("get", "/models", None),
            ("post", "/ocr", {"fields": [], "model": None}),
            ("post", "/delete_model", {"model": MISSING_MODEL}),
            ("post", "/rename_model", {"model": MISSING_MODEL, "newName": "renamed"}),
        ]
        for method, path, body in calls:
            with self.subTest(path=path):
                response = getattr(self.client, method)(path, **({"json": body} if body is not None else {}))
                self.assertEqual(401, response.status_code)

    def test_health_stays_open(self):
        self.assertEqual(200, self.client.get("/health").status_code)


if __name__ == "__main__":
    unittest.main()
