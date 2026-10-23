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


class StayPut(list):
    """Pixel values that ignore .to(device), so a fake card needs no real GPU
    (a CPU-only torch refuses .to("cuda") before the model is even called)."""

    def to(self, device):
        return self

    def tolist(self):
        return list(self)


class FakeProcessor:
    def __init__(self, on_card=False):
        self.on_card = on_card

    def __call__(self, images, return_tensors):
        shades = [image.getpixel((0, 0))[0] for image in images]
        return SimpleNamespace(pixel_values=StayPut(shades) if self.on_card else torch.tensor(shades))

    def batch_decode(self, sequences, skip_special_tokens):
        return [" ".join(f"t{token}" for token in row.tolist() if token > EOS) for row in sequences]


class FakeModel:
    """Reads each image as one token, then EOS. How sure it is depends on the
    token, so every row of a batch has its own confidence."""

    def __init__(self, fail_batches=False, broken=False):
        self.fail_batches = fail_batches
        self.broken = broken  # a card that fails every call, however small
        self.batch_sizes = []

    def generate(self, pixel_values, **kwargs):
        size = len(pixel_values)
        self.batch_sizes.append(size)
        if self.broken:
            raise RuntimeError("CUDA error: no kernel image is available for execution on the device")
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

    def test_a_card_that_fails_even_one_crop_at_a_time_hands_the_rest_to_the_cpu(self):
        on_card, on_cpu = FakeModel(broken=True), FakeModel()
        device = ["cuda"]
        entries = {
            "cuda": {"model": on_card, "processor": FakeProcessor(on_card=True), "eos_id": EOS, "label": "Fake"},
            "cpu": {"model": on_cpu, "processor": FakeProcessor(), "eos_id": EOS, "label": "Fake"},
        }

        def stop_using_gpu(reason):
            device[0] = "cpu"

        with mock.patch.object(main, "_get_device", lambda: device[0]), \
                mock.patch.object(main, "_stop_using_gpu", side_effect=stop_using_gpu) as stop, \
                mock.patch.object(main, "_load_model", lambda key: entries[device[0]]), \
                self.assertLogs("ocr-api", level="WARNING"):
            response = self.client.post(
                "/ocr",
                json={"fields": [{"name": n, "image": crop(s)} for n, s in [("a", 2), ("b", 3), ("c", 4)]]},
                headers={KEY_HEADER: main._upload_secret().decode("utf-8")},
            )

        results = response.json()["results"]
        self.assertEqual(["t2", "t3", "t4"], [row["text"] for row in results])
        self.assertTrue(all("error" not in row for row in results))
        stop.assert_called_once()
        # The card got the first batch and one crop of it; the CPU read the rest.
        self.assertEqual([2, 1], on_card.batch_sizes)
        self.assertEqual([1, 1, 1], on_cpu.batch_sizes)


class DeviceChoiceTest(unittest.TestCase):
    """Which device the service reads on, and how it gives up on a card that fails."""

    def setUp(self):
        saved = (main._device, dict(main._models))

        def restore():
            main._device = saved[0]
            main._models.clear()
            main._models.update(saved[1])

        self.addCleanup(restore)
        main._device = None
        main._models.clear()

    def device_with(self, setting, card):
        main._device = None
        with mock.patch.dict(os.environ, {"OCR_DEVICE": setting}), \
                mock.patch.object(main.torch.cuda, "is_available", return_value=card):
            return str(main._get_device())

    def test_the_setting_picks_the_device(self):
        self.assertEqual("cuda", self.device_with("auto", card=True))
        self.assertEqual("cpu", self.device_with("auto", card=False))
        self.assertEqual("cpu", self.device_with("cpu", card=True))
        with self.assertLogs("ocr-api", level="WARNING"):
            self.assertEqual("cpu", self.device_with("cuda", card=False))

    def test_giving_up_on_the_card_drops_its_models(self):
        main._device = main.torch.device("cuda")
        main._models["fake"] = {"model": object()}
        with self.assertLogs("ocr-api", level="WARNING"):
            main._stop_using_gpu(RuntimeError("CUDA error"))
        self.assertEqual("cpu", str(main._get_device()))
        self.assertEqual({}, main._models)

    def test_a_model_that_cannot_go_on_the_card_is_loaded_on_the_cpu(self):
        main._device = main.torch.device("cuda")
        loaded = []

        def load(source):
            model = mock.Mock()
            loaded.append(model)
            return FakeProcessor(), model

        with mock.patch.object(main, "load_model", side_effect=load), \
                mock.patch.object(main, "_check_gpu", side_effect=RuntimeError("no kernel image")), \
                mock.patch.object(main, "eos_token_id", return_value=EOS), \
                self.assertLogs("ocr-api", level="WARNING"):
            entry = main._load_model(main.BASE_MODEL_KEY)

        self.assertEqual("cpu", str(main._get_device()))
        # A fresh copy went to the CPU; the one half on the card was dropped.
        self.assertEqual(2, len(loaded))
        self.assertIs(loaded[1], entry["model"])
        loaded[1].to.assert_called_once_with(main.torch.device("cpu"))


if __name__ == "__main__":
    unittest.main()
