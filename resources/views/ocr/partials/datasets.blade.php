{{--
    Datasets tab.

    Upload is drag and drop and chunked, because a dataset of thousands of images is
    far past PHP's 40M limit. Validation is compulsory rather than advisory: training
    on a manifest that points at files which are not there fails hours into a run.
--}}
<x-card title="Upload a dataset"
        subtitle="Upload one zip archive, or choose/drop a folder containing manifest.csv.">
    <div class="dropzone-area border rounded p-4 text-center"
         id="dataset-dropzone"
         data-role="dataset">
        <i class="icon-base bx bx-cloud-upload icon-lg text-muted d-block mb-2"></i>
        <p class="mb-1"><strong>Drag one .zip or a dataset folder here</strong></p>
        <p class="text-muted small mb-3">
            or
            <button type="button" class="btn btn-sm btn-outline-primary" data-role="browse-zip">browse zip</button>
            <button type="button" class="btn btn-sm btn-outline-primary ms-1" data-role="browse-folder">browse folder</button>
        </p>
        <input type="file" class="d-none" accept=".zip" data-role="input-zip">
        <input type="file" class="d-none" webkitdirectory directory multiple data-role="input-folder">

        <p class="text-muted small mb-0">
            Choose exactly one zip, or one directory/file set containing
            <code>manifest.csv</code> with columns <code>filename,label,split,source</code>,
            plus <code>train/</code>, <code>val/</code>, and <code>test/</code>.
            Do not mix a zip with loose files. One wrapping folder is fine. Rows with
            an empty or <code>UNREADABLE</code> label are skipped by training.
        </p>
    </div>

    <form method="POST" action="{{ route('ocr.datasets.store') }}" class="mt-3" id="dataset-form">
        @csrf
        <input type="hidden" name="upload_id" id="dataset-upload-id">

        <div class="row g-3 align-items-end">
            <div class="col-md-6">
                <label for="dataset-name" class="form-label">Dataset name</label>
                <input type="text" class="form-control" id="dataset-name" name="name"
                       placeholder="e.g. handwritten-names-2026" maxlength="64" required>
                <div class="form-text">Becomes the folder name under <code>ml/datasets/</code>.</div>
            </div>
            <div class="col-md-6">
                <button type="submit" class="btn btn-primary" id="dataset-submit" disabled>
                    <i class="icon-base bx bx-upload icon-sm me-1"></i> Upload &amp; validate
                </button>
                <span class="text-muted small ms-2" id="dataset-status"></span>
            </div>
        </div>

        <div class="progress mt-3 d-none" style="height: 6px;" id="dataset-progress-wrap">
            <div class="progress-bar" id="dataset-progress" role="progressbar"
                 style="width: 0%" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"
                 aria-label="Upload progress"></div>
        </div>
    </form>

    <div class="alert alert-info mt-3 mb-0 small d-flex align-items-start">
        <i class="icon-base bx bx-info-circle icon-sm me-2"></i>
        <div>
            Uploads are sliced in the browser and reassembled server-side, so the
            40&nbsp;MB PHP limit does not apply. For large datasets (50–100&nbsp;GB)
            extraction and validation can take 10–30&nbsp;minutes — keep this tab
            open until you see the success message. If an upload is impractical
            over the network, place the folder under <code>ml/datasets/</code> by
            hand and click <em>Rescan</em>.
        </div>
    </div>
</x-card>

<x-card title="Datasets" subtitle="Per-split image counts and the last validation report."
        class="mt-4">
    @unless ($datasets['reachable'])
        <div class="alert alert-danger mb-3">
            {{ $datasets['error'] }}
        </div>
    @endunless

    @if ($datasets['datasets']->isEmpty())
        <x-empty-state icon="bx-folder" title="No datasets yet"
                       message="Upload a zip above, or place a folder under ml/datasets/ and click Rescan." />
    @else
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>Dataset</th>
                        <th>Images</th>
                        <th>Size</th>
                        <th>Validation</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($datasets['datasets'] as $dataset)
                        <tr>
                            <td>
                                <div class="fw-medium">{{ $dataset['name'] }}</div>
                                @if ($dataset['uploader'])
                                    <small class="text-muted">by {{ $dataset['uploader'] }}</small>
                                @endif
                                @if ($dataset['disk_deleted_at'])
                                    <span class="badge bg-label-danger ms-1">Deleted</span>
                                @elseif (! $dataset['on_disk'])
                                    <span class="badge bg-label-warning ms-1">Missing on disk</span>
                                @endif
                            </td>
                            <td class="small">
                                <div>
                                    train {{ number_format($dataset['train']) }}
                                    · val {{ number_format($dataset['val']) }}
                                    · test {{ number_format($dataset['test']) }}
                                </div>
                                <small class="text-muted">
                                    {{ number_format($dataset['usable_train']) }} usable training row(s)
                                </small>
                            </td>
                            <td class="small">
                                {{ $dataset['dataset']?->humanSize() ?? '—' }}
                            </td>
                            <td>
                                @if ($dataset['is_valid'] === true)
                                    <span class="badge bg-label-success">Passed</span>
                                @elseif ($dataset['is_valid'] === false)
                                    <span class="badge bg-label-danger">Failed</span>
                                @else
                                    <span class="badge bg-label-secondary">Not validated</span>
                                @endif

                                @if ($dataset['validated_at'])
                                    <div><small class="text-muted">{{ $dataset['validated_at']->diffForHumans() }}</small></div>
                                @endif

                                @if ($dataset['validation'])
                                    <button type="button" class="btn btn-link btn-sm p-0 mt-1"
                                            data-bs-toggle="modal" data-bs-target="#validationModal"
                                            data-dataset="{{ $dataset['name'] }}"
                                            data-report="{{ json_encode($dataset['validation']) }}">
                                        View report
                                    </button>
                                @endif
                            </td>
                            <td class="text-end">
                                <div class="d-inline-flex align-items-center gap-2">
                                    <form method="POST"
                                          action="{{ route('ocr.datasets.validate', $dataset['name']) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-secondary"
                                                @disabled(! $dataset['on_disk'])>
                                            <i class="icon-base bx bx-list-check icon-sm me-1"></i>
                                            Validate
                                        </button>
                                    </form>

                                    <button type="button" class="btn btn-sm btn-icon btn-text-danger rounded-pill"
                                            data-bs-toggle="modal" data-bs-target="#deleteDatasetModal"
                                            data-dataset="{{ $dataset['name'] }}"
                                            data-images="{{ $dataset['total'] }}"
                                            aria-label="Delete dataset"
                                            @disabled(! $dataset['on_disk'])>
                                        <i class="icon-base bx bx-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="small text-muted mb-0 mt-2">
            Only a dataset that has passed validation can be fine-tuned on. A manifest
            row pointing at a missing image fails deep into an epoch, wasting the GPU
            time spent up to that point.
        </p>
    @endif
</x-card>
