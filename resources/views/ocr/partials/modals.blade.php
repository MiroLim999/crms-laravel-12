{{--
    Modals for the OCR workspace.

    Every destructive action - delete a model, delete a dataset, cancel a run, stop
    the engine - names exactly what is being lost. The activate modal is not
    destructive but is confirmed too, because it changes what every Staff scan runs
    against.

    Each modal is shared across rows; the triggering button carries the key in a data
    attribute and ocr-workspace.js fills the form in on show.
--}}

{{-- Promote a model for Staff scanning. --}}
<div class="modal fade" id="activateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="" id="activateForm">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title">Use this model for scanning</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-3">
                    Make <code id="activateKey"></code> the model Staff use when they scan
                    certificates?
                </p>

                <div id="activateMetrics" class="alert alert-info small mb-3 d-none"></div>
                <div id="activateNoMetrics" class="alert alert-warning small mb-3 d-none">
                    This model has no recorded evaluation. Consider running one first so the
                    decision is based on measured CER and WER rather than a guess.
                </div>

                <p class="text-muted small mb-0">
                    Takes effect on the next scan. Records already extracted keep their
                    values and their reference to the model that produced them.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="icon-base bx bx-check-circle icon-sm me-1"></i> Save as active
                </button>
            </div>
        </form>
    </div>
</div>

{{-- Add a model folder, drag and drop, uploaded in chunks. --}}
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

                <div class="dropzone-area border rounded p-4 text-center"
                     id="model-dropzone" data-role="model">
                    <i class="icon-base bx bx-cloud-upload icon-lg text-muted d-block mb-2"></i>
                    <p class="mb-1"><strong>Drag the model folder here</strong></p>
                    <p class="text-muted small mb-3">
                        or <button type="button" class="btn btn-sm btn-outline-primary"
                                   data-role="browse">choose a folder</button>
                    </p>
                    {{-- webkitdirectory keeps the folder-picker fallback for browsers
                         that will not give up a directory on drop. --}}
                    <input type="file" class="d-none" webkitdirectory directory multiple
                           data-role="input">
                    <p class="text-muted small mb-0">
                        Must contain <code>config.json</code>, the weights
                        (<code>model.safetensors</code> or <code>pytorch_model.bin</code>),
                        and the tokenizer files.
                    </p>
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
                        the upload still takes a while. Alternatively, place the folder under
                        <code>ml/models/</code> by hand and click <em>Rescan</em>.
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

{{-- Rename a model. --}}
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
                    Renames the folder <code id="renameOldKey"></code> under <code>ml/models/</code>.
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

{{-- Delete a model: removes ~1.3 GB of weights from disk. --}}
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

{{-- Delete a dataset: removes every image in it. --}}
<div class="modal fade" id="deleteDatasetModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="" id="deleteDatasetForm">
            @csrf
            @method('DELETE')
            <div class="modal-header">
                <h5 class="modal-title">Delete dataset</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2">
                    Permanently delete <code id="deleteDatasetName"></code> and its
                    <strong id="deleteDatasetImages">0</strong> image(s)?
                </p>
                <p class="text-muted small mb-0">
                    The manifest and every split folder go with it. Models already trained on
                    this dataset are unaffected, but the run could not be reproduced.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger">Delete permanently</button>
            </div>
        </form>
    </div>
</div>

{{-- The full validation report. --}}
<div class="modal fade" id="validationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Validation report — <span id="validationName"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="validationBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

{{-- Cancel a running job. --}}
<div class="modal fade" id="cancelJobModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="" id="cancelJobForm">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title">Cancel this run</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2">
                    Stop the running <strong id="cancelJobType">job</strong>?
                </p>
                <p class="text-muted small mb-0">
                    The run stops at the next safe point, so no half-written checkpoint is
                    left behind. Any epoch already saved to
                    <code id="cancelJobOutput">the output folder</code> is kept; progress since
                    that epoch is lost.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                    Keep running
                </button>
                <button type="submit" class="btn btn-danger">Cancel the run</button>
            </div>
        </form>
    </div>
</div>

{{-- Stop the engine. --}}
<div class="modal fade" id="stopEngineModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="{{ route('ocr.engine.stop') }}">
            @csrf
            <input type="hidden" name="tab" value="{{ $tab }}">
            <div class="modal-header">
                <h5 class="modal-title">Stop the OCR service</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-3">
                    Stop the service process? <strong>Staff will not be able to scan
                    documents</strong> until it is manually started again. You can restart it with
                    <em>Start FastAPI</em> straight afterwards.
                </p>

                @if ($engine['reachable'] && ! $engine['owned'])
                    <div class="alert alert-warning small mb-3">
                        This service was not started from here. Stopping it ends
                        @if ($engine['listener_pid'])
                            <strong>PID {{ $engine['listener_pid'] }}</strong>,
                        @endif
                        whichever process is serving on the port — including one running in
                        a terminal window.
                    </div>
                @endif

                @if ($activeJob !== null)
                    <div class="alert alert-danger small mb-3">
                        A {{ $activeJob->type->label() }} run is at
                        {{ $activeJob->percent() }}%. Killing the process abandons it. Cancel
                        the run first if you want it to stop cleanly.
                    </div>

                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" value="1"
                               name="force" id="force-stop">
                        <label class="form-check-label" for="force-stop">
                            Stop anyway and abandon the running job
                        </label>
                    </div>
                @endif
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger">
                    <i class="icon-base bx bx-stop icon-sm me-1"></i> Stop service
                </button>
            </div>
        </form>
    </div>
</div>

{{-- Record metrics measured outside the app. --}}
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
                    For figures measured outside the app, e.g. a CLI run of
                    <code>test_finetuned.py</code>, for <code id="evalKey"></code>. An
                    evaluation job fills these in on its own. Enter rates as decimals —
                    0.0842 for 8.42%.
                </p>

                <div class="row g-3">
                    <div class="col-4">
                        <label for="eval-cer" class="form-label">CER</label>
                        <input type="number" step="0.0001" min="0" max="1" id="eval-cer"
                               name="cer" class="form-control">
                    </div>
                    <div class="col-4">
                        <label for="eval-wer" class="form-label">WER</label>
                        <input type="number" step="0.0001" min="0" max="1" id="eval-wer"
                               name="wer" class="form-control">
                    </div>
                    <div class="col-4">
                        <label for="eval-exact" class="form-label">Exact match</label>
                        <input type="number" step="0.0001" min="0" max="1" id="eval-exact"
                               name="exact_match" class="form-control">
                    </div>
                </div>

                <div class="mt-3">
                    <label for="eval-notes" class="form-label">Notes</label>
                    <textarea id="eval-notes" name="notes" rows="3" class="form-control"
                              placeholder="Dataset, split, sample count — anything worth remembering."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>
