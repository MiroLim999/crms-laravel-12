{{-- Add a model: one archive or one model folder. --}}
<div class="modal fade" id="addModelModal" tabindex="-1"
     aria-labelledby="addModelTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form class="modal-content" id="addModelForm">

            <div class="modal-header">
                <div class="d-flex align-items-center gap-3">
                    <span class="ocr-modal-icon bg-label-primary" aria-hidden="true">
                        <i class="icon-base bx bx-cloud-upload"></i>
                    </span>
                    <div>
                        <h2 class="modal-title h5 mb-0" id="addModelTitle">Add model</h2>
                        <p class="text-muted small mb-0 mt-1">Install a folder or .zip into the OCR service.</p>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"
                        id="add-model-close" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <div class="mb-4">
                    <label for="model-name" class="form-label fw-medium">Model name</label>
                    <input type="text" id="model-name" name="name" class="form-control"
                           placeholder="e.g. trocr-v3" maxlength="64" autocomplete="off" required>
                    <div class="form-text">
                        Used as the folder name under <span class="font-monospace">ml/models/</span>.
                    </div>
                </div>

                <div class="ocr-upload-zone" id="model-dropzone" data-role="model">
                    <span class="ocr-upload-icon" aria-hidden="true">
                        <i class="icon-base bx bx-cloud-upload"></i>
                    </span>
                    <h3 class="h6 mb-1">Drop a .zip or model folder here</h3>
                    <p class="text-muted small mb-3">or choose one from this computer</p>
                    <div class="d-flex flex-wrap justify-content-center gap-2">
                        <button type="button" class="btn btn-sm btn-label-primary" data-role="browse-zip">
                            Choose .zip
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-primary" data-role="browse-folder">
                            Choose folder
                        </button>
                    </div>
                    <input type="file" class="d-none" accept=".zip,application/zip" data-role="input-zip">
                    <input type="file" class="d-none" webkitdirectory directory multiple data-role="input-folder">
                </div>

                <div class="ocr-file-summary d-none" id="model-file-summary" role="status" aria-live="polite">
                    <span class="ocr-file-summary-icon" aria-hidden="true">
                        <i class="icon-base bx bx-file" id="model-file-summary-icon"></i>
                    </span>
                    <div class="min-w-0 flex-grow-1">
                        <div class="fw-medium text-truncate" id="model-file-summary-title"></div>
                        <div class="text-muted small" id="model-file-summary-detail"></div>
                    </div>
                    <button class="btn btn-sm btn-text-secondary" type="button" id="model-selection-clear">
                        Change
                    </button>
                </div>

                <div class="ocr-upload-progress d-none" id="model-progress-wrap" role="status" aria-live="polite">
                    <div class="d-flex align-items-center justify-content-between gap-3 mb-2">
                        <div class="fw-medium" id="model-upload-status">Uploading model</div>
                        <div class="small text-primary fw-medium" id="model-upload-percent">0%</div>
                    </div>
                    <div class="progress" aria-label="Upload progress">
                        <div class="progress-bar" id="model-progress" role="progressbar"
                             style="width:0%" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
                    </div>
                    <div class="text-muted small mt-2" id="model-upload-detail">
                        Keep this tab open until installation finishes.
                    </div>
                </div>

                <div class="ocr-modal-note mt-3">
                    <i class="icon-base bx bx-info-circle icon-sm flex-shrink-0"></i>
                    <span>
                        Requires <span class="font-monospace">config.json</span>, tokenizer files,
                        and <span class="font-monospace">model.safetensors</span> or
                        <span class="font-monospace">pytorch_model.bin</span>. The model uploads directly to the OCR service.
                    </span>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal"
                        id="add-model-cancel">Cancel</button>
                <button type="submit" class="btn btn-primary" id="addModelSubmit" disabled>
                    <span class="spinner-border spinner-border-sm me-1 d-none" aria-hidden="true"></span>
                    <span>Upload and install</span>
                </button>
            </div>
        </form>
    </div>
</div>

{{-- Rename the selected model folder. --}}
<div class="modal fade" id="renameModal" tabindex="-1"
     aria-labelledby="renameModelTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="" id="renameForm">
            @csrf
            <div class="modal-header">
                <div class="d-flex align-items-center gap-3">
                    <span class="ocr-modal-icon bg-label-primary" aria-hidden="true">
                        <i class="icon-base bx bx-rename"></i>
                    </span>
                    <div>
                        <h2 class="modal-title h5 mb-0" id="renameModelTitle">Rename model</h2>
                        <p class="text-muted small mb-0 mt-1">Change its folder name and service key.</p>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="ocr-current-model mb-4">
                    <span class="text-muted small">Current model</span>
                    <span class="font-monospace fw-medium text-break" id="renameOldKey"></span>
                </div>

                <label for="rename-input" class="form-label fw-medium">New model name</label>
                <input type="text" id="rename-input" name="new_name" class="form-control"
                       maxlength="64" autocomplete="off" required>
                <div class="form-text">
                    Existing records retain the model key originally stored with their OCR readings.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary" id="rename-submit" disabled>
                    <span class="spinner-border spinner-border-sm me-1 d-none" aria-hidden="true"></span>
                    <span>Rename model</span>
                </button>
            </div>
        </form>
    </div>
</div>

{{-- Permanently remove the selected model folder. --}}
<div class="modal fade" id="deleteModal" tabindex="-1"
     aria-labelledby="deleteModelTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="" id="deleteForm">
            @csrf
            @method('DELETE')
            <div class="modal-body text-center p-4 p-sm-5">
                <span class="ocr-delete-icon" aria-hidden="true">
                    <i class="icon-base bx bx-trash"></i>
                </span>
                <h2 class="modal-title h5 mt-3 mb-2" id="deleteModelTitle">Delete this model?</h2>
                <p class="mb-1">
                    <strong id="deleteLabel"></strong> will be removed from disk.
                </p>
                <p class="text-muted small mb-0 d-none" id="deleteKeyRow">
                    Folder: <span class="font-monospace" id="deleteKey"></span>
                </p>
                <div class="alert alert-danger text-start small mt-4 mb-0">
                    The model weights cannot be restored from CRMS. Existing records keep their submitted values
                    and model reference.
                </div>
            </div>
            <div class="modal-footer justify-content-center pt-0">
                <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal">Keep model</button>
                <button type="submit" class="btn btn-danger" id="delete-submit">
                    <span class="spinner-border spinner-border-sm me-1 d-none" aria-hidden="true"></span>
                    <span>Delete permanently</span>
                </button>
            </div>
        </form>
    </div>
</div>
