{{-- Add model: folder picker, mirroring the legacy prototype's upload flow. --}}
<div class="modal fade" id="addModelModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="{{ route('ocr.store') }}"
              enctype="multipart/form-data" id="addModelForm">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title">Add a model</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label for="model-name" class="form-label">Model name</label>
                    <input type="text" id="model-name" name="name" class="form-control"
                           placeholder="e.g. v3-finetuned-model" required>
                    <div class="form-text">
                        Becomes the folder name under <code>Models/</code>. Unsafe characters are replaced.
                    </div>
                </div>

                <div class="mb-3">
                    <label for="model-files" class="form-label">Model folder</label>
                    <input type="file" id="model-files" name="files[]" class="form-control"
                           webkitdirectory directory multiple required>
                    <div class="form-text">
                        Must contain <code>config.json</code>, the weights
                        (<code>model.safetensors</code> or <code>pytorch_model.bin</code>),
                        and the tokenizer files.
                    </div>
                </div>

                <div id="model-file-summary" class="d-none alert alert-info mb-0"></div>

                <div class="alert alert-warning mb-0 mt-3 small">
                    Model weights are around 1.3&nbsp;GB. Uploads are streamed to the OCR
                    service, but PHP's <code>upload_max_filesize</code> must be raised to match
                    or the request is rejected before it reaches the app.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary" id="addModelSubmit">Upload &amp; add</button>
            </div>
        </form>
    </div>
</div>

{{-- Rename --}}
<div class="modal fade" id="renameModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="" id="renameForm">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title">Rename model</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small">
                    Renames the folder <code id="renameOldKey"></code> under <code>Models/</code>.
                </p>
                <label for="rename-input" class="form-label">New name</label>
                <input type="text" id="rename-input" name="new_name" class="form-control" required>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>

{{-- Delete: destructive, removes the folder from disk. --}}
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="" id="deleteForm">
            @csrf
            @method('DELETE')
            <div class="modal-header">
                <h5 class="modal-title">Delete model</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2">
                    Permanently delete <code id="deleteKey"></code> from disk?
                </p>
                <p class="text-muted small mb-0">
                    This removes the folder and its weights. Records already extracted with
                    this model keep their values and their reference to its name.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger">Delete permanently</button>
            </div>
        </form>
    </div>
</div>

{{-- Record evaluation metrics from the offline scripts. --}}
<div class="modal fade" id="evalModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="" id="evalForm">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title">Record evaluation</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small">
                    Figures from <code>test_finetuned.py</code> for <code id="evalKey"></code>.
                    Enter rates as decimals, e.g. 0.0842 for 8.42%.
                </p>

                <div class="row g-3">
                    <div class="col-4">
                        <label for="eval-cer" class="form-label">CER</label>
                        <input type="number" step="0.0001" min="0" max="1" id="eval-cer" name="cer" class="form-control">
                    </div>
                    <div class="col-4">
                        <label for="eval-wer" class="form-label">WER</label>
                        <input type="number" step="0.0001" min="0" max="1" id="eval-wer" name="wer" class="form-control">
                    </div>
                    <div class="col-4">
                        <label for="eval-exact" class="form-label">Exact match</label>
                        <input type="number" step="0.0001" min="0" max="1" id="eval-exact" name="exact_match" class="form-control">
                    </div>
                </div>

                <div class="mt-3">
                    <label for="eval-notes" class="form-label">Notes</label>
                    <textarea id="eval-notes" name="notes" rows="3" class="form-control"
                              placeholder="Dataset, epochs, anything worth remembering."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
    // Modals are shared across rows; the triggering button carries the model key.
    document.addEventListener('DOMContentLoaded', () => {
        const wire = (modalId, formId, setup) => {
            const modal = document.getElementById(modalId);
            if (!modal) return;
            modal.addEventListener('show.bs.modal', (event) => {
                const key = event.relatedTarget?.dataset.modelKey;
                if (!key) return;
                setup(document.getElementById(formId), key, event.relatedTarget.dataset);
            });
        };

        wire('renameModal', 'renameForm', (form, key) => {
            form.action = @json(route('ocr.rename', '__KEY__')).replace('__KEY__', encodeURIComponent(key));
            document.getElementById('renameOldKey').textContent = key;
            form.querySelector('[name="new_name"]').value = key;
        });

        wire('deleteModal', 'deleteForm', (form, key) => {
            form.action = @json(route('ocr.destroy', '__KEY__')).replace('__KEY__', encodeURIComponent(key));
            document.getElementById('deleteKey').textContent = key;
        });

        wire('evalModal', 'evalForm', (form, key, data) => {
            form.action = @json(route('ocr.evaluation', '__KEY__')).replace('__KEY__', encodeURIComponent(key));
            document.getElementById('evalKey').textContent = key;
            form.querySelector('[name="cer"]').value = data.cer || '';
            form.querySelector('[name="wer"]').value = data.wer || '';
            form.querySelector('[name="exact_match"]').value = data.exact || '';
            form.querySelector('[name="notes"]').value = data.notes || '';
        });

        // Show what the folder picker actually selected, and flag a folder that is
        // missing the files the service requires before the upload is attempted.
        const filesInput = document.getElementById('model-files');
        const summary = document.getElementById('model-file-summary');

        filesInput?.addEventListener('change', () => {
            const names = Array.from(filesInput.files).map((f) => f.name);
            if (!names.length) {
                summary.classList.add('d-none');
                return;
            }

            const hasConfig = names.includes('config.json');
            const hasWeights = names.includes('model.safetensors') || names.includes('pytorch_model.bin');
            const bytes = Array.from(filesInput.files).reduce((sum, f) => sum + f.size, 0);
            const mb = (bytes / 1024 / 1024).toFixed(1);

            summary.className = 'alert mb-0 ' + (hasConfig && hasWeights ? 'alert-success' : 'alert-danger');
            summary.textContent = hasConfig && hasWeights
                ? `${names.length} file(s), ${mb} MB. Looks like a valid model folder.`
                : `${names.length} file(s) selected, but ${!hasConfig ? 'config.json' : 'the weights file'} is missing.`;
            summary.classList.remove('d-none');
        });

        // Large uploads take a while; make it obvious the click registered.
        document.getElementById('addModelForm')?.addEventListener('submit', () => {
            const btn = document.getElementById('addModelSubmit');
            btn.disabled = true;
            btn.textContent = 'Uploading...';
        });
    });
</script>
@endpush
