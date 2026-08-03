{{--
    Modals for the OCR workspace.

    Rename and Delete act on whatever is selected in the dropdown, so ocr-workspace.js
    reads that selection on show rather than each row carrying its own data attributes.
    Delete names what is being lost: ~1.3 GB of weights, irreversibly.
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
                    {{-- Two pickers: a browser cannot offer files and a directory from one
                         input. webkitdirectory keeps the folder fallback for browsers that
                         will not give up a directory on drop. --}}
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
                    <i class="icon-base bx bx-info-circle icon-sm me-2"></i>
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

{{-- Rename the selected model's folder. --}}
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
                    Renames the folder <code id="renameOldKey"></code> under
                    <code>ml/models/</code>.
                </p>
                <label for="rename-input" class="form-label">New name</label>
                <input type="text" id="rename-input" name="new_name" class="form-control"
                       maxlength="64" required>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>

{{-- Delete the selected model: removes ~1.3 GB of weights from disk. --}}
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
                <p class="mb-2">Permanently delete <code id="deleteKey"></code> from disk?</p>
                <p class="text-muted small mb-0">
                    This removes the folder and its weights, around 1.3&nbsp;GB, and cannot be
                    undone. Records already extracted with this model keep their values and
                    their reference to its name.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger">Delete permanently</button>
            </div>
        </form>
    </div>
</div>
