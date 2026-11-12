"""
trocr_common.py
The TrOCR helpers that the OCR service (api/main.py), predict.py and
test_finetuned.py share: finding a model, loading it, and scoring a reading.

Each of them used to carry its own copy, so a fix to one copy left the others
as they were. The confidence score most of all must be one calculation: the
number shown while spot-checking must be the number shown while scanning.
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

    Returns (source, key). No key, or 'base', is the base model: the copy in
    ml/models/base when download_trocr.py installed it there (it carries the
    tokenizer.json that Transformers 5 needs and the Hugging Face repository
    lacks), else the Hugging Face name. A folder path is used as it is; anything
    else must be a folder under ml/models/."""
    if not model or model == BASE_MODEL_KEY:
        installed = os.path.join(MODELS_DIR, BASE_MODEL_KEY)
        if os.path.isfile(os.path.join(installed, "config.json")):
            return installed, BASE_MODEL_KEY
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


def sequence_confidence(net, gen_output, eos_id, row=0):
    """Geometric mean of per-token probabilities up to the first EOS, as a %.

    `gen_output` is what generate() returns with output_scores=True and
    return_dict_in_generate=True. `row` picks one image of a batch; a reading
    of a single image is row 0. Only that row is scored, so scoring every row
    of a batch costs no more than scoring each image read alone. This assumes
    greedy decoding (one beam), which every CRMS model uses."""
    try:
        sequences = gen_output.sequences[row:row + 1]
        scores = net.compute_transition_scores(
            sequences,
            tuple(step[row:row + 1] for step in gen_output.scores),
            normalize_logits=True,
        )[0]
        gen_tokens = sequences[0][1:1 + len(scores)]
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


def batch_confidences(net, gen_output, eos_id):
    """sequence_confidence for every row of a batch, in row order.

    The same formula and, for a model in fp32, the same numbers: geometric mean
    of the token probabilities up to and including the first EOS, skipping a
    non-finite one. What differs is the cost. sequence_confidence asks the card
    for each token on its own (several waits per token, every line); this
    computes every row on the card at once and copies the result back once.
    The log-probabilities are always taken in fp32, so a model read in fp16
    is scored at full precision too."""
    try:
        logits = torch.stack(gen_output.scores, dim=1).float()       # [rows, steps, vocab]
        tokens = gen_output.sequences[:, 1:1 + logits.shape[1]]        # [rows, steps]
        chosen = logits.log_softmax(dim=-1).gather(-1, tokens.unsqueeze(-1)).squeeze(-1)
        all_log_probs, all_tokens = chosen.cpu().tolist(), tokens.cpu().tolist()
    except Exception:
        return [sequence_confidence(net, gen_output, eos_id, row)
                for row in range(len(gen_output.sequences))]

    confidences = []
    for row_tokens, row_log_probs in zip(all_tokens, all_log_probs):
        # Summed in Python, in token order, exactly as sequence_confidence does.
        log_probs = []
        for tok, lp in zip(row_tokens, row_log_probs):
            if not math.isfinite(lp):
                continue
            log_probs.append(lp)
            if tok == eos_id:
                break
        confidences.append(
            round(math.exp(sum(log_probs) / len(log_probs)) * 100.0, 1) if log_probs else 0.0
        )
    return confidences
