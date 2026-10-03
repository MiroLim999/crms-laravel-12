"""
trocr_common.py
The TrOCR helpers that the OCR service (api/main.py), predict.py and
test_finetuned.py share: finding a model, loading it, and scoring a reading.

Each of them used to carry its own copy, so a fix to one copy left the others
as they were. The confidence score most of all must be one calculation: the
number a Super Admin sees while spot-checking must be the number Staff see while
scanning.
"""

import math
import os

# Quiets the HF stack. Must precede torch/transformers.
import hf_quiet  # noqa: F401

import torch
from transformers import TrOCRProcessor, VisionEncoderDecoderModel

ML_ROOT = os.path.dirname(os.path.abspath(__file__))
MODELS_DIR = os.path.join(ML_ROOT, "models")

BASE_MODEL_KEY = "base"
BASE_MODEL_NAME = "microsoft/trocr-base-handwritten"


class ModelNotFoundError(Exception):
    """A model key that names no folder under ml/models/."""


def resolve_model(model):
    """Turn a model key from the UI into something from_pretrained accepts.

    Returns (source, key). No key, or 'base', is the Hugging Face base model;
    a folder path is used as it is; anything else must be a folder under
    ml/models/."""
    if not model or model == BASE_MODEL_KEY:
        return BASE_MODEL_NAME, BASE_MODEL_KEY

    if os.path.isdir(model):
        return model, os.path.basename(os.path.normpath(model))

    candidate = os.path.join(MODELS_DIR, model)
    if os.path.isdir(candidate):
        return candidate, model

    raise ModelNotFoundError(
        f"Model '{model}' was not found under ml/models/. Add it, or pick another."
    )


def load_model(source):
    """The processor and model at `source`: a folder, or a Hugging Face name."""
    return (
        TrOCRProcessor.from_pretrained(source),
        VisionEncoderDecoderModel.from_pretrained(source),
    )


def eos_token_id(net, processor):
    """The EOS id can live in several places depending on the model."""
    return (
        getattr(net.generation_config, "eos_token_id", None)
        or getattr(net.config, "eos_token_id", None)
        or getattr(net.config.decoder, "eos_token_id", None)
        or processor.tokenizer.sep_token_id
    )


def sequence_confidence(net, gen_output, eos_id):
    """Geometric mean of per-token probabilities up to the first EOS, as a %.

    `gen_output` is what generate() returns with output_scores=True and
    return_dict_in_generate=True, for one image."""
    try:
        scores = net.compute_transition_scores(
            gen_output.sequences, gen_output.scores, normalize_logits=True
        )[0]
        gen_tokens = gen_output.sequences[0][1:1 + len(scores)]
        log_probs = []
        for tok, lp in zip(gen_tokens, scores):
            if not torch.isfinite(lp):
                continue
            log_probs.append(lp.item())
            if tok.item() == eos_id:
                break
        if not log_probs:
            return 0.0
        return round(math.exp(sum(log_probs) / len(log_probs)) * 100.0, 1)
    except Exception:
        return 0.0
