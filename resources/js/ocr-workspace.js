/**
 * ocr-workspace.js
 * Front-end for the OCR workspace (Super Admin).
 *
 * Its own Vite entry, listed as an input in vite.config.js. Only this page loads it,
 * so the chunked-upload machinery stays out of app.js.
 *
 * Four jobs:
 *   1. Chunked upload   - PHP caps uploads at 40M, models are ~1.3 GB. The browser
 *                         slices, the server reassembles.
 *   2. Drag and drop    - every upload surface, with a click-to-browse fallback.
 *   3. Polling          - job progress and engine state. Deliberately polling: a run
 *                         reports at epoch/step boundaries, so there is nothing a
 *                         socket would buy.
 *   4. Modal wiring     - one modal per action, shared across table rows.
 *
 * Endpoints arrive via window.crmsOcr, set in the Blade view. No URLs are hardcoded.
 */

const config = window.crmsOcr ?? {};

/** 5 MB, comfortably under PHP's upload_max_filesize of 40M. */
const CHUNK_SIZE = 5 * 1024 * 1024;

/** How often to ask about a running job. */
const JOB_POLL_MS = 3000;

// ---------------------------------------------------------------------- helpers

const $ = (selector, scope = document) => scope.querySelector(selector);
const $$ = (selector, scope = document) => Array.from(scope.querySelectorAll(selector));

const url = (template, value) =>
    template.replace('__KEY__', encodeURIComponent(value)).replace('__ID__', encodeURIComponent(value));

const formatBytes = (bytes) => {
    const units = ['B', 'KB', 'MB', 'GB'];
    let value = bytes;
    let unit = 0;
    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit += 1;
    }
    return `${value.toFixed(unit === 0 ? 0 : 1)} ${units[unit]}`;
};

/** Random enough to be unguessable; the server validates the shape and namespaces
 *  the directory by user id, so it is an identifier and never an authorisation. */
const randomId = () =>
    Array.from(crypto.getRandomValues(new Uint8Array(16)))
        .map((b) => b.toString(16).padStart(2, '0'))
        .join('');

const post = (endpoint, body, headers = {}) =>
    fetch(endpoint, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': config.csrf, Accept: 'application/json', ...headers },
        body,
    });

const getJson = async (endpoint) => {
    const response = await fetch(endpoint, {
        headers: { Accept: 'application/json' },
    });
    if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
    }
    return response.json();
};

// ------------------------------------------------------------------ chunked upload

/**
 * Send one file in slices. Progress is reported in bytes so a multi-file model
 * folder can show a single total.
 */
async function uploadFile(uploadId, file, onProgress) {
    const total = Math.max(Math.ceil(file.size / CHUNK_SIZE), 1);
    const fileKey = randomId();
    // A folder picker yields webkitRelativePath; the server only ever wants the
    // basename, but strip the path here too so nothing surprising is sent.
    const name = (file.name || 'file').split(/[\\/]/).pop();

    for (let index = 0; index < total; index += 1) {
        const start = index * CHUNK_SIZE;
        const slice = file.slice(start, start + CHUNK_SIZE);

        const body = new FormData();
        body.append('upload_id', uploadId);
        body.append('file_key', fileKey);
        body.append('filename', name);
        body.append('index', String(index));
        body.append('total', String(total));
        body.append('chunk', slice, `${name}.part${index}`);

        const response = await post(config.urls.chunk, body);

        if (!response.ok) {
            const payload = await response.json().catch(() => ({}));
            throw new Error(payload.message ?? `Upload of ${name} failed (HTTP ${response.status}).`);
        }

        onProgress(slice.size);
    }
}

async function uploadAll(uploadId, files, onProgress) {
    const totalBytes = files.reduce((sum, file) => sum + file.size, 0);
    let sent = 0;

    for (const file of files) {
        // Sequential on purpose: parallel slices of a 1.3 GB file would compete for
        // the same disk and make progress meaningless.
        await uploadFile(uploadId, file, (bytes) => {
            sent += bytes;
            onProgress(totalBytes === 0 ? 100 : Math.round((sent / totalBytes) * 100));
        });
    }
}

// ---------------------------------------------------------------------- dropzones

/**
 * Wire a drag-and-drop area with a click-to-browse fallback.
 *
 * @param {object} options
 * @param {HTMLElement} options.zone
 * @param {(files: File[]) => void} options.onFiles
 */
function wireDropzone({ zone, onFiles }) {
    if (!zone) return;

    const input = $('[data-role="input"]', zone);
    const browse = $('[data-role="browse"]', zone);

    browse?.addEventListener('click', () => input?.click());
    input?.addEventListener('change', () => onFiles(Array.from(input.files ?? [])));

    // Without preventDefault on dragover the browser navigates to the file instead.
    ['dragenter', 'dragover'].forEach((event) =>
        zone.addEventListener(event, (e) => {
            e.preventDefault();
            zone.classList.add('border-primary', 'bg-lighter');
        }),
    );

    ['dragleave', 'drop'].forEach((event) =>
        zone.addEventListener(event, (e) => {
            e.preventDefault();
            zone.classList.remove('border-primary', 'bg-lighter');
        }),
    );

    zone.addEventListener('drop', async (e) => {
        const items = Array.from(e.dataTransfer?.items ?? []);
        const entries = items
            .map((item) => (item.webkitGetAsEntry ? item.webkitGetAsEntry() : null))
            .filter(Boolean);

        // A dropped folder arrives as a directory entry, which has to be walked.
        // Falling back to dataTransfer.files covers browsers without the entry API.
        const files = entries.length
            ? (await Promise.all(entries.map(readEntry))).flat()
            : Array.from(e.dataTransfer?.files ?? []);

        onFiles(files);
    });
}

/** Recursively collect files from a dropped directory entry. */
function readEntry(entry, path = '') {
    return new Promise((resolve) => {
        if (entry.isFile) {
            entry.file(
                (file) => resolve([file]),
                () => resolve([]),
            );
            return;
        }

        const reader = entry.createReader();
        const collected = [];

        const readBatch = () => {
            reader.readEntries(async (batch) => {
                if (!batch.length) {
                    resolve(collected.flat());
                    return;
                }
                collected.push(
                    ...(await Promise.all(batch.map((child) => readEntry(child, `${path}/${entry.name}`)))),
                );
                readBatch(); // readEntries returns at most 100 at a time
            }, () => resolve(collected.flat()));
        };

        readBatch();
    });
}

// ------------------------------------------------------------------ dataset upload

function initDatasetUpload() {
    const zone = $('#dataset-dropzone');
    if (!zone) return;

    const form = $('#dataset-form');
    const submit = $('#dataset-submit');
    const status = $('#dataset-status');
    const bar = $('#dataset-progress');
    const barWrap = $('#dataset-progress-wrap');
    const nameInput = $('#dataset-name');
    const uploadIdInput = $('#dataset-upload-id');

    let selected = null;

    const setStatus = (message, tone = 'text-muted') => {
        status.className = `small ms-2 ${tone}`;
        status.textContent = message;
    };

    wireDropzone({
        zone,
        onFiles: (files) => {
            const zip = files.find((file) => file.name.toLowerCase().endsWith('.zip'));

            if (!zip) {
                setStatus('That is not a .zip archive.', 'text-danger');
                submit.disabled = true;
                return;
            }

            selected = zip;
            submit.disabled = false;
            setStatus(`${zip.name} · ${formatBytes(zip.size)} ready to upload.`);

            // Save a step: default the dataset name to the archive's own name.
            if (!nameInput.value) {
                nameInput.value = zip.name.replace(/\.zip$/i, '').replace(/[^A-Za-z0-9._-]+/g, '-');
            }
        },
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (!selected) {
            setStatus('Choose a .zip first.', 'text-danger');
            return;
        }

        const uploadId = randomId();
        submit.disabled = true;
        barWrap.classList.remove('d-none');
        setStatus('Uploading…');

        try {
            await uploadAll(uploadId, [selected], (percent) => {
                bar.style.width = `${percent}%`;
                bar.setAttribute('aria-valuenow', String(percent));
                setStatus(`Uploading… ${percent}%`);
            });

            setStatus('Unpacking and validating on the server…');
            uploadIdInput.value = uploadId;

            // The upload is done; hand off with a normal post so the result arrives
            // as a flash message and the page re-renders with the new dataset.
            form.submit();
        } catch (error) {
            submit.disabled = false;
            setStatus(error.message, 'text-danger');
        }
    });
}

// -------------------------------------------------------------------- model upload

function initModelUpload() {
    const zone = $('#model-dropzone');
    if (!zone) return;

    const form = $('#addModelForm');
    const submit = $('#addModelSubmit');
    const summary = $('#model-file-summary');
    const bar = $('#model-progress');
    const barWrap = $('#model-progress-wrap');
    const uploadIdInput = $('#model-upload-id');

    let selected = [];

    wireDropzone({
        zone,
        onFiles: (files) => {
            selected = files;

            if (!files.length) {
                summary.classList.add('d-none');
                submit.disabled = true;
                return;
            }

            const names = files.map((file) => file.name);
            const hasConfig = names.includes('config.json');
            const hasWeights =
                names.includes('model.safetensors') || names.includes('pytorch_model.bin');
            const bytes = files.reduce((sum, file) => sum + file.size, 0);
            const valid = hasConfig && hasWeights;

            // Checked here so an hour of uploading is not spent on a folder the
            // service will reject on arrival.
            summary.className = `alert mb-0 mt-3 ${valid ? 'alert-success' : 'alert-danger'}`;
            summary.textContent = valid
                ? `${files.length} file(s), ${formatBytes(bytes)}. Looks like a valid model folder.`
                : `${files.length} file(s) selected, but ${
                      !hasConfig ? 'config.json' : 'the weights file'
                  } is missing.`;
            summary.classList.remove('d-none');

            submit.disabled = !valid;
        },
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (!selected.length) return;

        const uploadId = randomId();
        submit.disabled = true;
        submit.textContent = 'Uploading…';
        barWrap.classList.remove('d-none');

        try {
            await uploadAll(uploadId, selected, (percent) => {
                bar.style.width = `${percent}%`;
                bar.setAttribute('aria-valuenow', String(percent));
                submit.textContent = `Uploading… ${percent}%`;
            });

            uploadIdInput.value = uploadId;
            submit.textContent = 'Saving…';
            form.submit();
        } catch (error) {
            submit.disabled = false;
            submit.textContent = 'Upload & add';
            summary.className = 'alert alert-danger mb-0 mt-3';
            summary.textContent = error.message;
            summary.classList.remove('d-none');
        }
    });
}

// ------------------------------------------------------------------------- predict

function initPredict() {
    const zone = $('#predict-dropzone');
    if (!zone) return;

    const run = $('#predict-run');
    const clear = $('#predict-clear');
    const status = $('#predict-status');
    const results = $('#predict-results');
    const rows = $('#predict-rows');

    let selected = [];

    const setStatus = (message, tone = 'text-muted') => {
        status.className = `small ${tone}`;
        status.textContent = message;
    };

    const reset = () => {
        selected = [];
        run.disabled = true;
        clear.classList.add('d-none');
        results.classList.add('d-none');
        rows.innerHTML = '';
        setStatus('');
    };

    wireDropzone({
        zone,
        onFiles: (files) => {
            const images = files.filter((file) => file.type.startsWith('image/'));

            if (!images.length) {
                setStatus('No images in that drop.', 'text-danger');
                return;
            }

            // Matches the service's own cap. Anything larger belongs in an
            // evaluation run, which measures against ground truth.
            selected = images.slice(0, 50);
            run.disabled = false;
            clear.classList.remove('d-none');
            setStatus(
                `${selected.length} image(s) ready${
                    images.length > 50 ? ' (capped at 50)' : ''
                }.`,
            );
        },
    });

    clear.addEventListener('click', reset);

    run.addEventListener('click', async () => {
        if (!selected.length) return;

        const body = new FormData();
        body.append('model', $('#predict-model').value);
        selected.forEach((file) => body.append('images[]', file, file.name));

        run.disabled = true;
        setStatus('Running on the GPU…');

        try {
            const response = await post(config.urls.predict, body);
            const payload = await response.json();

            if (!response.ok) {
                throw new Error(payload.message ?? `Prediction failed (HTTP ${response.status}).`);
            }

            renderPredictions(payload);
            setStatus('');
        } catch (error) {
            setStatus(error.message, 'text-danger');
        } finally {
            run.disabled = false;
        }
    });

    function renderPredictions(payload) {
        $('#predict-result-model').textContent = payload.model || payload.modelKey;
        $('#predict-result-count').textContent = payload.count;
        $('#predict-result-average').textContent = `${payload.average_confidence}%`;
        $('#predict-result-low').textContent = `${payload.low_confidence} of ${payload.count}`;

        rows.innerHTML = '';

        payload.rows.forEach((row) => {
            const tr = document.createElement('tr');
            const low = !row.error && row.confidence < payload.threshold;

            const file = document.createElement('td');
            file.className = 'small text-muted';
            file.textContent = row.filename;

            const text = document.createElement('td');
            if (row.error) {
                text.innerHTML = '<span class="text-danger small"></span>';
                text.firstChild.textContent = row.error;
            } else {
                text.className = 'fw-medium';
                text.textContent = row.text || '(nothing read)';
            }

            const confidence = document.createElement('td');
            confidence.className = 'text-end';
            if (row.error) {
                confidence.textContent = '—';
            } else {
                const badge = document.createElement('span');
                // A review flag, not a verdict on correctness.
                badge.className = `badge ${low ? 'bg-label-warning' : 'bg-label-success'}`;
                badge.textContent = `${row.confidence}%`;
                confidence.appendChild(badge);
            }

            tr.append(file, text, confidence);
            rows.appendChild(tr);
        });

        results.classList.remove('d-none');
    }
}

// -------------------------------------------------------------------- job polling

function initJobPolling() {
    const banner = $('#job-banner');
    const jobId = config.activeJobId;

    if (!banner || !jobId) return;

    const set = (id, value) => {
        const el = document.getElementById(id);
        if (el) el.textContent = value ?? '—';
    };

    const tick = async () => {
        let job;

        try {
            job = await getJson(url(config.urls.jobStatus, jobId));
        } catch {
            // A transient failure while the GPU is saturated is expected; try again.
            return true;
        }

        const percent = job.percent ?? 0;
        const bar = $('#job-bar');
        bar.style.width = `${percent}%`;
        bar.setAttribute('aria-valuenow', String(percent));

        set('job-percent', percent);
        set('job-stage', job.stage);
        set('job-duration', job.duration);
        set('job-epoch', job.progress?.epoch ?? 0);
        set('job-total-epochs', job.progress?.total_epochs ?? '?');
        set('job-step', job.progress?.step ?? 0);
        set('job-total-steps', job.progress?.total_steps ?? '?');
        set('job-loss', job.progress?.loss ?? '—');

        const status = $('#job-status');
        status.textContent = job.status_label;
        status.className = `badge ${job.badge} ms-2`;

        const log = $('#job-log');
        if (log && Array.isArray(job.log)) {
            log.textContent = job.log.join('\n');
        }

        if (job.live) {
            return true;
        }

        // Finished. Reload so every tab reflects the outcome: a new model in the
        // list, fresh metrics, a new chart.
        $('#job-spinner')?.classList.add('d-none');
        bar.classList.remove('progress-bar-animated', 'progress-bar-striped');
        set('job-title', `${job.type_label} ${job.status}`);

        window.setTimeout(() => window.location.reload(), 1500);

        return false;
    };

    const loop = async () => {
        if (await tick()) {
            window.setTimeout(loop, JOB_POLL_MS);
        }
    };

    window.setTimeout(loop, JOB_POLL_MS);
}

// ----------------------------------------------------------------- engine polling

function initEnginePolling() {
    if (!$('#engine-card')) return;

    let lastReachable = config.engineReachable;
    let lastOwned = config.engineOwned;
    let lastStoppable = config.engineStoppable;
    let lastPid = config.enginePid;
    let lastListenerPid = config.engineListenerPid;

    // Poll less aggressively when the service is offline: there's nothing useful
    // to show, and each probe pays a full connection-refused wait (~500ms).
    // Snap back to the fast interval the moment it comes up.
    const FAST_MS  = 10_000;   // service is up - refresh the device/job info
    const SLOW_MS  = 30_000;   // service is down - just waiting for it to start

    const loop = async () => {
        let next = lastReachable ? FAST_MS : SLOW_MS;

        try {
            const engine = await getJson(config.urls.engineStatus);

            $('#engine-dot').className = `badge rounded-circle p-1 ${
                engine.reachable ? 'bg-success' : 'bg-danger'
            }`;
            $('#engine-state').textContent = engine.reachable
                ? 'OCR service online'
                : 'OCR service offline';
            $('#engine-device').textContent = engine.device || 'not-loaded';

            const pidWrap = $('#engine-pid-wrap');
            if (engine.pid) {
                $('#engine-pid').textContent = engine.pid;
                pidWrap.classList.remove('d-none');
            } else {
                pidWrap.classList.add('d-none');
            }

            // Reload on a transition so the whole page (forms, buttons, warnings)
            // matches reality rather than being patched piecemeal.
            if (engine.reachable !== lastReachable) {
                lastReachable = engine.reachable;
                window.location.reload();
                return;
            }

            // Ownership and PID discovery decide whether Stop can act safely. On
            // Windows the listener may be discovered after the page first renders,
            // so refresh whenever any process-control value changes.
            if (
                engine.owned !== lastOwned
                || engine.stoppable !== lastStoppable
                || engine.pid !== lastPid
                || engine.listener_pid !== lastListenerPid
            ) {
                lastOwned = engine.owned;
                lastStoppable = engine.stoppable;
                lastPid = engine.pid;
                lastListenerPid = engine.listener_pid;
                window.location.reload();
                return;
            }

            // When online, use the fast interval; when offline, slow down.
            next = engine.reachable ? FAST_MS : SLOW_MS;
        } catch {
            // Ignore and retry at the slow interval.
            next = SLOW_MS;
        }

        window.setTimeout(loop, next);
    };

    window.setTimeout(loop, lastReachable ? FAST_MS : SLOW_MS);
}

// ------------------------------------------------------------------------- modals

/**
 * Point a shared modal's form at the row that opened it.
 */
function wireModal(modalId, setup) {
    const modal = document.getElementById(modalId);
    if (!modal) return;

    modal.addEventListener('show.bs.modal', (event) => {
        setup(modal, event.relatedTarget?.dataset ?? {});
    });
}

function initModals() {
    wireModal('activateModal', (modal, data) => {
        $('#activateForm').action = url(config.urls.modelActivate, data.modelKey);
        $('#activateKey').textContent = data.modelKey;

        const metrics = $('#activateMetrics');
        const none = $('#activateNoMetrics');

        if (data.modelCer) {
            metrics.textContent =
                `Measured CER ${(parseFloat(data.modelCer) * 100).toFixed(2)}%` +
                (data.modelEvaluated ? `, evaluated ${data.modelEvaluated}.` : '.');
            metrics.classList.remove('d-none');
            none.classList.add('d-none');
        } else {
            metrics.classList.add('d-none');
            none.classList.remove('d-none');
        }
    });

    wireModal('renameModal', (modal, data) => {
        const form = $('#renameForm');
        form.action = url(config.urls.modelRename, data.modelKey);
        $('#renameOldKey').textContent = data.modelKey;
        form.querySelector('[name="new_name"]').value = data.modelKey;
    });

    wireModal('deleteModal', (modal, data) => {
        $('#deleteForm').action = url(config.urls.modelDestroy, data.modelKey);
        $('#deleteKey').textContent = data.modelKey;
    });

    wireModal('evalModal', (modal, data) => {
        const form = $('#evalForm');
        form.action = url(config.urls.modelEvaluation, data.modelKey);
        $('#evalKey').textContent = data.modelKey;
        form.querySelector('[name="cer"]').value = data.cer || '';
        form.querySelector('[name="wer"]').value = data.wer || '';
        form.querySelector('[name="exact_match"]').value = data.exact || '';
        form.querySelector('[name="notes"]').value = data.notes || '';
    });

    wireModal('deleteDatasetModal', (modal, data) => {
        $('#deleteDatasetForm').action = url(config.urls.datasetDestroy, data.dataset);
        $('#deleteDatasetName').textContent = data.dataset;
        $('#deleteDatasetImages').textContent = data.images ?? '0';
    });

    wireModal('cancelJobModal', (modal, data) => {
        $('#cancelJobForm').action = url(config.urls.jobCancel, data.jobId);
        $('#cancelJobType').textContent = data.jobType ?? 'job';
        $('#cancelJobOutput').textContent = data.jobOutput || 'the output folder';
    });

    wireModal('validationModal', (modal, data) => {
        $('#validationName').textContent = data.dataset ?? '';
        renderValidationReport($('#validationBody'), JSON.parse(data.report ?? '{}'));
    });
}

function renderValidationReport(container, report) {
    container.innerHTML = '';

    const verdict = document.createElement('div');
    verdict.className = `alert ${report.ok ? 'alert-success' : 'alert-danger'}`;
    verdict.textContent = report.ok
        ? 'This dataset passed validation and can be used for fine-tuning.'
        : 'This dataset failed validation and cannot be used for fine-tuning.';
    container.appendChild(verdict);

    const list = (title, items, tone) => {
        if (!items?.length) return;
        const heading = document.createElement('strong');
        heading.className = 'd-block mt-3 mb-1';
        heading.textContent = title;
        const ul = document.createElement('ul');
        ul.className = `small mb-0 ${tone}`;
        items.forEach((item) => {
            const li = document.createElement('li');
            li.textContent = item;
            ul.appendChild(li);
        });
        container.append(heading, ul);
    };

    list('Errors', report.errors, 'text-danger');
    list('Warnings', report.warnings, 'text-warning');

    const counts = document.createElement('div');
    counts.className = 'row g-3 mt-3 small';
    const splits = report.split_distribution ?? {};
    const usable = report.usable ?? {};

    ['train', 'val', 'test'].forEach((split) => {
        const col = document.createElement('div');
        col.className = 'col-4';
        col.innerHTML = '<div class="border rounded p-2"></div>';
        col.firstChild.textContent = `${split}: ${usable[split] ?? 0} usable of ${
            splits[split] ?? 0
        } rows`;
        counts.appendChild(col);
    });

    container.appendChild(counts);

    list('Manifest rows with no image on disk', report.missing_images, 'text-danger');
    list('Images with no manifest row', report.orphan_images, 'text-muted');

    if (report.missing_image_count > (report.missing_images?.length ?? 0)) {
        const more = document.createElement('p');
        more.className = 'small text-muted mt-2 mb-0';
        more.textContent = `…and ${
            report.missing_image_count - report.missing_images.length
        } more missing image(s).`;
        container.appendChild(more);
    }
}

// ------------------------------------------------------------------ tabs + history

/**
 * Keep ?tab= in the URL so a redirect after an action reopens the same section.
 * replaceState rather than a navigation: switching tabs is not a new page.
 */
function initTabs() {
    $$('#ocrTabs [data-tab-key]').forEach((button) => {
        button.addEventListener('shown.bs.tab', () => {
            const next = new URL(window.location.href);
            next.searchParams.set('tab', button.dataset.tabKey);
            window.history.replaceState({}, '', next);
        });
    });
}

/**
 * Expandable history rows.
 *
 * Bootstrap's collapse does not work inside a <table>: it animates by measuring
 * height, tables force a layout pass mid-animation, so it measures 0 and snaps
 * shut. Animating max-height by hand is the working approach - same as audit/index.
 */
function initHistoryRows() {
    $$('[data-role="expand-run"]').forEach((button) => {
        button.addEventListener('click', () => {
            const row = document.getElementById(button.dataset.target);
            const inner = row?.querySelector('.run-detail-inner');
            if (!inner) return;

            const open = button.getAttribute('aria-expanded') === 'true';

            inner.style.transition = 'max-height 0.25s ease';
            inner.style.maxHeight = open ? '0px' : `${inner.scrollHeight}px`;

            button.setAttribute('aria-expanded', String(!open));
            button.querySelector('i').className = open
                ? 'icon-base bx bx-chevron-right'
                : 'icon-base bx bx-chevron-down';
        });
    });
}

// ----------------------------------------------------------------------- bootstrap

document.addEventListener('DOMContentLoaded', () => {
    initTabs();
    initModals();
    initHistoryRows();
    initDatasetUpload();
    initModelUpload();
    initPredict();
    initJobPolling();
    initEnginePolling();
});
