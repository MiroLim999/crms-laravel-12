import base64
import io
import os
import unittest
from types import SimpleNamespace
from unittest import mock

import torch
from fastapi.testclient import TestClient
from PIL import Image

from ml.api import main

KEY_HEADER = "X-CRMS-Service-Key"
VOCAB = 8
START, EOS = 0, 1


def crop(shade):
    """A one-colour PNG data URL. Its shade (2 to 7) is the token the fake model reads."""
    buffer = io.BytesIO()
    Image.new("RGB", (4, 4), (shade, shade, shade)).save(buffer, format="PNG")
    return "data:image/png;base64," + base64.b64encode(buffer.getvalue()).decode()


class FakeProcessor:
    def __call__(self, images, return_tensors):
        shades = [image.getpixel((0, 0))[0] for image in images]
        return SimpleNamespace(pixel_values=torch.tensor(shades))

    def batch_decode(self, sequences, skip_special_tokens):
        return [" ".join(f"t{token}" for token in row.tolist() if token > EOS) for row in sequences]


class FakeModel:
    """Reads each image as one token, then EOS. How sure it is depends on the
    token, so every row of a batch has its own confidence."""

    def __init__(self, fail_batches=False):
        self.fail_batches = fail_batches
        self.batch_sizes = []

    def generate(self, pixel_values, **kwargs):
        size = len(pixel_values)
        self.batch_sizes.append(size)
        if self.fail_batches and size > 1:
            raise RuntimeError("CUDA out of memory")

        first, second = torch.zeros(size, VOCAB), torch.zeros(size, VOCAB)
        for row, token in enumerate(pixel_values.tolist()):
            first[row, token] = float(token)
            second[row, EOS] = 5.0
        sequences = torch.tensor([[START, token, EOS] for token in pixel_values.tolist()])
        return SimpleNamespace(sequences=sequences, scores=(first, second))

    def compute_transition_scores(self, sequences, scores, normalize_logits):
        log_probs = torch.stack(scores, dim=1).log_softmax(dim=-1)
        return log_probs.gather(2, sequences[:, 1:].unsqueeze(-1)).squeeze(-1)


class OcrBatchTest(unittest.TestCase):
    def setUp(self):
        # Its own secret, so the test does not depend on the machine's .env.
        environment = mock.patch.dict(os.environ, {"OCR_UPLOAD_SECRET": "service-key-for-tests"})
        environment.start()
        self.addCleanup(environment.stop)
        for name, value in {
            "_resolve_key": lambda requested: "fake",
            "_get_device": lambda: "cpu",
            "OCR_BATCH_SIZE": 2,
        }.items():
            patcher = mock.patch.object(main, name, value)
            patcher.start()
            self.addCleanup(patcher.stop)
        self.client = TestClient(main.app)

    def read(self, model, fields):
        entry = {"model": model, "processor": FakeProcessor(), "eos_id": EOS, "label": "Fake"}
        with mock.patch.object(main, "_load_model", lambda key: entry):
            response = self.client.post(
                "/ocr",
                json={"fields": fields},
                headers={KEY_HEADER: main._upload_secret().decode("utf-8")},
            )
        self.assertEqual(200, response.status_code)
        return response.json()["results"]

    def alone(self, shade):
        """The reading of one crop sent on its own."""
        return self.read(FakeModel(), [{"name": "alone", "image": crop(shade)}])[0]

    def test_a_broken_crop_gets_an_error_row_and_the_others_are_read_in_order(self):
        model = FakeModel()
        results = self.read(model, [
            {"name": "a", "image": crop(2)},
            {"name": "b", "image": crop(3)},
            {"name": "broken", "image": "data:image/png;base64,bm90IGFuIGltYWdl"},
            {"name": "c", "image": crop(4)},
            {"name": "d", "image": crop(5)},
        ])

        self.assertEqual(["a", "b", "broken", "c", "d"], [row["name"] for row in results])
        self.assertEqual(["t2", "t3", "", "t4", "t5"], [row["text"] for row in results])
        self.assertIn("error", results[2])
        self.assertEqual(0.0, results[2]["confidence"])
        for row, shade in zip([results[0], results[1], results[3], results[4]], [2, 3, 4, 5]):
            self.assertNotIn("error", row)
            # Each row is scored on its own row of the batch.
            self.assertEqual(self.alone(shade)["confidence"], row["confidence"])
        # Four readable crops, two per generate() call.
        self.assertEqual([2, 2], model.batch_sizes)

    def test_a_chunk_that_fails_together_is_read_one_crop_at_a_time(self):
        model = FakeModel(fail_batches=True)
        with self.assertLogs("ocr-api", level="WARNING"):
            results = self.read(model, [
                {"name": "a", "image": crop(6)},
                {"name": "b", "image": crop(7)},
            ])

        self.assertEqual(["t6", "t7"], [row["text"] for row in results])
        self.assertTrue(all("error" not in row for row in results))
        self.assertEqual([2, 1, 1], model.batch_sizes)


if __name__ == "__main__":
    unittest.main()
