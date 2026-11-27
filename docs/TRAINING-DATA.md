# Training Data of the AI Model in Use

What the model `trocr-finetuned-final` was trained and tested on, where that data is kept, and what is not known yet.
Written on 27 November 2026 from the datasets' own metadata files and the model's `training-summary.json`. No data
(images or texts) is in this repository, and none is quoted here.

| | |
| :--- | :--- |
| **Model** | `trocr-finetuned-final` (its reports call it `TrOcr-ledger-synth-30k-plus-real`), installed 25 November 2026 |
| **Stage 1** | Microsoft's `trocr-base-handwritten` → fine-tuned on `30k-real-ledger-synthetic` on **Kaggle** (2 × T4) |
| **Stage 2** | Stage 1's model → fine-tuned on `raw-cropped-dataset` (**real** ledger lines) on the development laptop (RTX 4050) |
| **Notebooks** | Stage 1: [`finetuning-kaggle/trocr-finetuning-code.ipynb`](../finetuning-kaggle/trocr-finetuning-code.ipynb). Stage 2: [`finetuning-kaggle/trocr-finetuning-from-model.ipynb`](../finetuning-kaggle/trocr-finetuning-from-model.ipynb). The copies in the repository have their outputs removed, because outputs show real handwriting. |
| **Where the data is** | Only on the training computer, in the developer's `Finetuning\datasets` folder. Not in git. |
| **Scores** | See [SYSTEM-OVERVIEW 7.7](SYSTEM-OVERVIEW.md#77-results) |

## 1. `raw-cropped-dataset`: real ledger lines (stage 2)

| Item | Value |
| :--- | :--- |
| Source | **Real** (`"source": "real"` in its `run-metadata.json`): line images cut from scanned register pages |
| Made by | "Kraken Word Cropper (cropper-miro)", 22 November 2026 |
| Images | 11,638 cut; after a label review **11,589** |
| Label review | 435 crops were flagged for checking (confirmed problems, duplicates, heuristics, a second review). 48 labels were corrected and 49 crops removed (`raw-cropped-changes.csv`). |
| Preparation | Grayscale, trimmed to the ink with a 6-pixel margin, scaled to 64 pixels tall, shape kept (`gray-trim-h64-v1`) |
| Split | 80/10/10 by a seeded shuffle of the file names (seed 42): **9,270 training, 1,160 validation, 1,159 test** |
| Checks before training | No exact copies between the splits; no label longer than the model reads (the longest is 15 tokens) |
| Fingerprints | manifest SHA-256 `34375e97c9b569abd8f040b24dc62843020259ca8a8b63c1629d0bebe479dc55`, images SHA-256 `38170fef4ce485d31d6f9acbef244f3f137d642ebb0a43422949163befc60661` |

**What it does not record:** which books, pages and years the lines came from, and who wrote them. The manifest has no
page, book or writer column (`writer_id` is empty), so the split could not keep the pages of a book together: test
lines share pages, writers and many texts with training lines (837 of the 1,159 test texts also appear in training).
That is why the overall test score is optimistic, and why the "never seen" part of the test set is the fairer figure.

## 2. `30k-real-ledger-synthetic`: computer-made ledger lines (stage 1)

| Item | Value |
| :--- | :--- |
| Made by | `synthetic-generator`, `src/real_ledger.py` (not in this repository), 23 November 2026, seed 42 |
| Images | 30,000: ledger entries 10,445, names 7,875, "mix" 3,835, dates 3,024, other fields 2,960, places 1,861 |
| Handwriting | 14 handwriting fonts, 120 simulated writers, 14,885 different texts |
| Made from | A "real-ledger profile" of the real dataset's **training** split. The profile's own README says it holds real data: scanned paper, real words and names, and must not be committed or uploaded. |
| Real handwriting in it | The 3,835 "mix" images are pieces of real handwriting from the real training split, recombined (`run-metadata.json`: "mix samples are real TRAIN handwriting recombined") |
| Split | 23,922 training, 3,054 validation, 3,024 test |
| Used on | **Kaggle**: stage 1's report says `"run_on": "kaggle"`, and its manifest SHA-256 (`02f49189c078de6fcf2e5a1843dda59233e654f85f30a09f960ed28f85f3d2aa`) is this dataset's |

## 3. Privacy: what to settle before the defense

The rule in CRMS is that real ledger data never leaves the office computer. Stage 2 kept to it (it ran on the laptop).
Stage 1 did not: its dataset carries real handwriting, real words and names, and real paper, and it was trained on
Kaggle. To settle it:

1. Confirm with the person who ran stage 1 that this dataset was uploaded to Kaggle.
2. If it was: delete the Kaggle dataset and its notebook versions, and say in the manuscript's Data Privacy section
   what was uploaded, why, and that it was removed.
3. Better for the future: make the synthetic set from public name lists and plain paper only, with no "mix" images,
   and retrain stage 1 on that.

## 4. Making the score fairer next time

- Keep the page (and book) of every crop in the manifest, so the split can put whole pages, or a whole book, in the
  test set.
- Keep at least one book completely out of training, and score the base model and the model in use on it.
- Write down who checked the labels and how, beside the review files.
