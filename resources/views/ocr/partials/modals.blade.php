{{--
    Modals for the OCR workspace.

    Rename and Delete are triggered by inline row buttons, each carrying
    data-key (and data-label for delete) set by the row.  The JS writes those
    values into the modal on show.bs.modal.
--}}

{{-- Install a model: a folder, or one .zip containing the same files. --}}
<div class="modal fade" id="addModelModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form class="modal-content" method="POST" action="{{ route('ocr.store') }}" id="addModelForm">
            @csrf
            <input type="hidden" name="upload_id" id="model-upload-id">
            <div class="modal-header">
                <h5 class="modal-title">Add a model</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label for="model-name" class="form-label">Model name</label>
                    <input type="text" id="model-name" name="name" class="form-control"
                           placeholder="e.g. trocr-v3" maxlength="64" required>
                    <div class="form-text">
                        Becomes the folder name under <code>ml/models/</code>. Unsafe characters
                        are replaced.
                    </div>
                </div>

                <label class="form-label">Model files</label>
                <p class="text-muted small mb-2">
                    A <code>.zip</code> of the model, or the folder itself. Either way it needs
                    <code>config.json</code>, the weights
                    (<code>model.safetensors</code> or <code>pytorch_model.bin</code>), and the
                    tokenizer files. A wrapping folder inside the zip is fine — the service
                    finds the model within it.
                </p>

                <div class="dropzone-area border rounded p-4 text-center"
                     id="model-dropzone" data-role="model">
                    <i class="icon-base bx bx-cloud-upload icon-lg text-muted d-block mb-2"></i>
                    <p class="mb-1"><strong>Drag one .zip or the model folder here</strong></p>
                    <p class="text-muted small mb-3">
                        or
                        <button type="button" class="btn btn-sm btn-outline-primary"
                                data-role="browse-zip">browse zip</button>
                        <button type="button" class="btn btn-sm btn-outline-primary"
                                data-role="browse-folder">browse folder</button>
                    </p>
                    <input type="file" class="d-none" accept=".zip,application/zip"
                           data-role="input-zip">
                    <input type="file" class="d-none" webkitdirectory directory multiple
                           data-role="input-folder">
                </div>

                <div id="model-file-summary" class="alert mb-0 mt-3 d-none"></div>

                <div class="progress mt-3 d-none" style="height: 6px;" id="model-progress-wrap">
                    <div class="progress-bar" id="model-progress" role="progressbar"
                         style="width: 0%" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"
                         aria-label="Upload progress"></div>
                </div>

                <div class="alert alert-info mt-3 mb-0 small d-flex align-items-start">
                    <i class="icon-base bx bx-info-circle icon-sm me-2 flex-shrink-0"></i>
                    <div>
                        Weights are around 1.3&nbsp;GB. The browser slices each file and
                        Laravel reassembles it, so PHP's 40&nbsp;MB limit does not apply — but
                        the upload still takes a while, and a zip has to be extracted on the
                        server afterwards. Keep this tab open until you see the result.
                        Alternatively, place the folder under <code>ml/models/</code> by hand
                        and click <em>Rescan</em>.
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary" id="addModelSubmit" disabled>
                    Upload &amp; add
                </button>
            </div>
        </form>
    </div>
</div>

{{-- Rename a model: the row button sets data-key; JS fills the form on show. --}}
<div class="modal fade" id="renameModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="" id="renameForm">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title">Rename model</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-3">
                    Renames the folder <code id="renameOldKey"></code> under
                    <code>ml/models/</code>. The key used in existing records is not changed.
                </p>
                <label for="rename-input" class="form-label">New name</label>
                <input type="text" id="rename-input" name="new_name" class="form-control"
                       maxlength="64" required autofocus>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="icon-base bx bx-check icon-sm me-1"></i> Save name
                </button>
            </div>
        </form>
    </div>
</div>

{{-- Delete a model: the row button sets data-key + data-label; JS fills the form on show. --}}
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="" id="deleteForm">
            @csrf
            @method('DELETE')
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title text-danger">
                    <i class="icon-base bx bx-error-circle icon-sm me-1"></i>
                    Delete model
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body pt-2">
                <p class="mb-1">
                    Permanently delete <strong id="deleteLabel"></strong>?
                </p>
                <p class="text-muted small mb-0">
                    This removes the folder <code id="deleteKey"></code> and its weights
                    (~1.3&nbsp;GB) from disk. This cannot be undone. Records already extracted
                    with this model keep their values and their reference to its name.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger">
                    <i class="icon-base bx bx-trash icon-sm me-1"></i> Delete permanently
                </button>
            </div>
        </form>
    </div>
</div>
