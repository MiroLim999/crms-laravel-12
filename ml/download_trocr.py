"""
download_trocr.py
Downloads the TrOCR processor and model from Hugging Face into ml/models/base.
Model: microsoft/trocr-base-handwritten
"""

from pathlib import Path

from huggingface_hub import hf_hub_download, snapshot_download


def main():
    model_name = "microsoft/trocr-base-handwritten"
    target = Path(__file__).resolve().parent / "models" / "base"

    print("=" * 50)
    print("DOWNLOADING LOCAL TrOCR BASE MODEL")
    print("=" * 50)
    print(f"Source : {model_name}")
    print(f"Target : {target}")
    print("The safetensors checkpoint is about 1.3 GB and the download can resume.")
    target.mkdir(parents=True, exist_ok=True)

    snapshot_download(
        repo_id=model_name,
        local_dir=target,
        allow_patterns=[
            "config.json",
            "generation_config.json",
            "model.safetensors",
            "preprocessor_config.json",
            "processor_config.json",
            "special_tokens_map.json",
            "tokenizer.json",
            "tokenizer_config.json",
            "merges.txt",
            "vocab.json",
        ],
    )

    # The upstream main branch still lacks tokenizer.json. Transformers 5.x no
    # longer constructs this legacy tokenizer from vocab/merges alone, so use
    # the upstream repository's pending compatibility-file revision when needed.
    if not (target / "tokenizer.json").is_file():
        print("Downloading the Transformers 5 tokenizer compatibility file...")
        hf_hub_download(
            repo_id=model_name,
            filename="tokenizer.json",
            revision="refs/pr/11",
            local_dir=target,
        )

    print("=" * 50)
    print("DOWNLOAD COMPLETE")
    print("=" * 50)
    print(f"Saved to: {target}")
    print("Restart the OCR service, then click Rescan models in OCR Workspace.")


if __name__ == "__main__":
    main()
