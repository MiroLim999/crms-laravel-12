import ApexCharts from 'apexcharts';

/**
 * OCR Workspace front end (Super Admin only).
 *
 * This page owns direct model uploads, model-action modals (activate, rename,
 * delete), and service-status polling. Application URLs and server limits come from
 * window.crmsOcr, rendered by the Blade view.
 */

const config = window.crmsOcr ?? {};

// ---------------------------------------------------------------------- helpers

const $ = (selector, scope = document) => scope.querySelector(selector);

const url = (template, value) => template.replace('__KEY__', encodeURIComponent(value));

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

const post = (endpoint, body, headers = {}, options = {}) =>
    fetch(endpoint, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': config.csrf, Accept: 'application/json', ...headers },
        body,
        ...options,
    });

const getJson = async (endpoint) => {
    const response = await fetch(endpoint, { headers: { Accept: 'application/json' } });

    if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
    }

    return response.json();
};

/** Toggle the common spinner / icon / text arrangement used by action buttons. */
const setButtonBusy = (button, busy, busyLabel) => {
    if (!button) return;

    const spinner = $('.spinner-border', button);
    const icon = $('.icon-base', button);
    const labels = Array.from(button.children).filter(
        (child) => child.tagName === 'SPAN' && !child.classList.contains('spinner-border'),
    );
    const label = labels.at(-1);

    if (label && !button.dataset.idleLabel) {
        button.dataset.idleLabel = label.textContent.trim();
    }

    spinner?.classList.toggle('d-none', !busy);
    icon?.classList.toggle('d-none', busy);

    if (label) {
        label.textContent = busy ? busyLabel : button.dataset.idleLabel;
    }

    button.disabled = busy;
    button.setAttribute('aria-busy', busy ? 'true' : 'false');
};

// --------------------------------------------------------------- model list

// -------------------------------------------------------------- direct upload

/** Paths supplied by folder pickers and directory drag traversal. */
const relativePaths = new WeakMap();

const relativePathFor = (file) => {
    const path = relativePaths.get(file) || file.webkitRelativePath;
    return path ? path.replace(/\\/g, '/') : (file.name || 'file').split(/[\\/]/).pop();
};

const responseError = (payload, fallback) => {
    const validation = payload?.errors ? Object.values(payload.errors).flat()[0] : null;
    return validation ?? payload?.message ?? payload?.error ?? fallback;
};

async function postForm(endpoint, body) {
    const response = await post(endpoint, body);
    const contentType = response.headers.get('content-type') ?? '';
    const payload = contentType.includes('application/json')
        ? await response.json().catch(() => null)
        : null;

    if (response.redirected) {
        throw new Error('Your CRMS session has expired. Sign in again, reopen OCR Workspace, then use Rescan models.');
    }

    if (!response.ok) {
        throw new Error(responseError(payload, `Request failed (HTTP ${response.status}).`));
    }

    if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
        throw new Error('CRMS returned an unexpected response. Retry registration or use Rescan models.');
    }

    return payload;
}

/** Send the model once, directly to FastAPI, while retaining upload progress. */
function uploadDirect(endpoint, authorization, name, files, onProgress, signal) {
    return new Promise((resolve, reject) => {
        const body = new FormData();
        body.append('name', name);

        if (files.length === 1 && isZip(files[0])) {
            body.append('archive', files[0], files[0].name);
        } else {
            files.forEach((file) => body.append('files', file, file.name));
        }

        const xhr = new XMLHttpRequest();
        const abort = () => xhr.abort();
        const finish = () => signal?.removeEventListener('abort', abort);

        xhr.open('POST', endpoint);
        xhr.responseType = 'json';
        xhr.setRequestHeader('X-OCR-Upload-Authorization', authorization);

        xhr.upload.addEventListener('progress', (event) => {
            if (event.lengthComputable) {
                onProgress(Math.round((event.loaded / event.total) * 100));
            }
        });

        xhr.addEventListener('load', () => {
            finish();
            const payload = xhr.response && typeof xhr.response === 'object' ? xhr.response : {};

            if (xhr.status >= 200 && xhr.status < 300 && payload.ok) {
                resolve(payload);
                return;
            }

            reject(new Error(responseError(payload, `Upload failed (HTTP ${xhr.status}).`)));
        });
        xhr.addEventListener('error', () => {
            finish();
            reject(new Error('Could not reach the OCR upload service. Check its URL and CORS configuration.'));
        });
        xhr.addEventListener('abort', () => {
            finish();
            const error = new Error('Upload cancelled.');
            error.name = 'AbortError';
            reject(error);
        });

        if (signal?.aborted) {
            abort();
            return;
        }

        signal?.addEventListener('abort', abort, { once: true });
        xhr.send(body);
    });
}

// ---------------------------------------------------------------------- dropzone

function wireDropzone({ zone, onFiles }) {
    if (!zone) return;

    [
        ['browse', 'input'],
        ['browse-zip', 'input-zip'],
        ['browse-folder', 'input-folder'],
    ].forEach(([browseRole, inputRole]) => {
        const browse = $(`[data-role="${browseRole}"]`, zone);
        const input = $(`[data-role="${inputRole}"]`, zone);
        browse?.addEventListener('click', () => input?.click());
        input?.addEventListener('change', () => onFiles(Array.from(input.files ?? [])));
    });

    ['dragenter', 'dragover'].forEach((eventName) => {
        zone.addEventListener(eventName, (event) => {
            event.preventDefault();
            zone.classList.add('is-dragging');
        });
    });

    ['dragleave', 'drop'].forEach((eventName) => {
        zone.addEventListener(eventName, (event) => {
            event.preventDefault();
            zone.classList.remove('is-dragging');
        });
    });

    zone.addEventListener('drop', async (event) => {
        const items = Array.from(event.dataTransfer?.items ?? []);
        const entries = items
            .map((item) => (item.webkitGetAsEntry ? item.webkitGetAsEntry() : null))
            .filter(Boolean);

        const files = entries.length
            ? (await Promise.all(entries.map((entry) => readEntry(entry)))).flat()
            : Array.from(event.dataTransfer?.files ?? []);

        onFiles(files);
    });
}

/** Recursively collect dropped folder files while retaining each relative path. */
function readEntry(entry, parentPath = '') {
    return new Promise((resolve) => {
        const relativePath = parentPath ? `${parentPath}/${entry.name}` : entry.name;

        if (entry.isFile) {
            entry.file(
                (file) => {
                    relativePaths.set(file, relativePath);
                    resolve([file]);
                },
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
                    ...(await Promise.all(batch.map((child) => readEntry(child, relativePath)))),
                );
                readBatch();
            }, () => resolve(collected.flat()));
        };

        readBatch();
    });
}

// -------------------------------------------------------------------- model upload

const isZip = (file) => /\.zip$/i.test(file.name || '');

/** Return one concise selection state for the modal summary. */
function describeSelection(files) {
    const zips = files.filter(isZip);
    const bytes = files.reduce((sum, file) => sum + file.size, 0);

    if (zips.length && files.length > 1) {
        return {
            ok: false,
            title: 'Choose one source',
            detail: 'Upload one .zip or one model folder, not both.',
        };
    }

    if (zips.length === 1) {
        return {
            ok: true,
            title: zips[0].name,
            detail: `${formatBytes(bytes)} · Archive contents will be validated after upload.`,
        };
    }

    const names = files.map((file) => relativePathFor(file).split('/').pop());
    const hasConfig = names.includes('config.json');
    const hasWeights = names.includes('model.safetensors') || names.includes('pytorch_model.bin');

    if (!hasConfig || !hasWeights) {
        return {
            ok: false,
            title: 'Incomplete model folder',
            detail: `${!hasConfig ? 'config.json' : 'A supported weights file'} is missing.`,
        };
    }

    return {
        ok: true,
        title: `${files.length} model ${files.length === 1 ? 'file' : 'files'}`,
        detail: `${formatBytes(bytes)} · Folder structure looks valid.`,
    };
}

function initModelUpload() {
    const modal = $('#addModelModal');
    const zone = $('#model-dropzone');
    const form = $('#addModelForm');

    if (!modal || !zone || !form) return;

    const submit = $('#addModelSubmit');
    const nameInput = $('#model-name');
    const summary = $('#model-file-summary');
    const summaryIcon = $('#model-file-summary-icon');
    const summaryTitle = $('#model-file-summary-title');
    const summaryDetail = $('#model-file-summary-detail');
    const clearSelection = $('#model-selection-clear');
    const progressWrap = $('#model-progress-wrap');
    const progress = $('#model-progress');
    const progressStatus = $('#model-upload-status');
    const progressPercent = $('#model-upload-percent');
    const progressDetail = $('#model-upload-detail');
    const cancel = $('#add-model-cancel');
    const close = $('#add-model-close');
    const fileInputs = Array.from(form.querySelectorAll('input[type="file"]'));

    let selected = [];
    let uploading = false;
    let abortController = null;
    let pendingRegistration = null;

    const submitLabel = Array.from(submit.children).find(
        (child) => child.tagName === 'SPAN' && !child.classList.contains('spinner-border'),
    );

    const setSubmitLabel = (label) => {
        submit.dataset.idleLabel = label;
        if (!uploading && submitLabel) submitLabel.textContent = label;
    };

    const refreshSubmit = () => {
        submit.disabled = uploading || (
            pendingRegistration === null
            && (selected.length === 0 || nameInput.value.trim() === '')
        );
    };

    const renderSummary = ({ ok, title, detail }) => {
        summary.classList.remove('d-none', 'is-valid', 'is-invalid');
        summary.classList.add(ok ? 'is-valid' : 'is-invalid');
        summaryTitle.textContent = title;
        summaryDetail.textContent = detail;
        summaryIcon.className = `icon-base bx ${ok ? 'bx-check-circle' : 'bx-x-circle'}`;
    };

    const clearProgress = () => {
        progressWrap.classList.add('d-none');
        progress.classList.remove('bg-danger');
        progress.style.width = '0%';
        progress.setAttribute('aria-valuenow', '0');
        progressStatus.textContent = 'Uploading model';
        progressPercent.textContent = '0%';
        progressDetail.textContent = 'Keep this tab open until installation finishes.';
    };

    const resetSelection = () => {
        pendingRegistration = null;
        selected = [];
        fileInputs.forEach((input) => { input.value = ''; });
        nameInput.readOnly = false;
        clearSelection.disabled = false;
        fileInputs.forEach((input) => { input.disabled = false; });
        setSubmitLabel('Upload and install');
        summary.classList.add('d-none');
        summary.classList.remove('is-valid', 'is-invalid');
        zone.classList.remove('d-none', 'is-dragging');
        clearProgress();
        refreshSubmit();
    };

    const cancelUpload = () => {
        if (uploading && abortController) {
            abortController.abort();
        }
    };

    const setUploading = (busy) => {
        uploading = busy;
        form.setAttribute('aria-busy', busy ? 'true' : 'false');
        nameInput.readOnly = busy;
        clearSelection.disabled = busy;
        cancel.disabled = false;
        close.disabled = false;
        fileInputs.forEach((input) => { input.disabled = busy; });
        setButtonBusy(submit, busy, 'Uploading…');
        refreshSubmit();
    };

    const acceptFiles = (files) => {
        if (!files.length) {
            resetSelection();
            return;
        }

        const verdict = describeSelection(files);
        selected = verdict.ok ? files : [];
        renderSummary(verdict);
        zone.classList.toggle('d-none', verdict.ok);

        if (verdict.ok && !nameInput.value.trim()) {
            const source = isZip(files[0])
                ? files[0].name.replace(/\.zip$/i, '')
                : relativePathFor(files[0]).split('/')[0] || '';
            nameInput.value = source.replace(/[^\w\s._-]/g, '').replace(/\s+/g, ' ').trim();
        }

        refreshSubmit();
    };

    wireDropzone({ zone, onFiles: acceptFiles });
    nameInput.addEventListener('input', refreshSubmit);
    clearSelection.addEventListener('click', resetSelection);

    cancel.addEventListener('click', (e) => {
        if (uploading) {
            e.preventDefault();
            e.stopPropagation();
            cancelUpload();
        }
    });

    close.addEventListener('click', () => {
        if (uploading) {
            cancelUpload();
        }
    });

    modal.addEventListener('hide.bs.modal', (event) => {
        if (uploading) {
            cancelUpload();
        }
    });

    modal.addEventListener('hidden.bs.modal', () => {
        if (uploading) return;
        form.reset();
        resetSelection();
    });

    window.addEventListener('beforeunload', (event) => {
        if (!uploading && pendingRegistration === null) return;
        event.preventDefault();
        event.returnValue = '';
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        if ((pendingRegistration === null && !selected.length) || !form.checkValidity()) {
            form.reportValidity();
            return;
        }

        abortController = new AbortController();
        setUploading(true);
        progressWrap.classList.remove('d-none');
        let installedName = pendingRegistration;

        try {
            if (installedName === null) {
                progressStatus.textContent = 'Authorizing upload';
                progressDetail.textContent = 'Preparing a short-lived direct upload ticket…';

                const ticketBody = new FormData();
                ticketBody.append('name', nameInput.value.trim());
                const ticket = await postForm(config.urls.authorizeUpload, ticketBody);

                if (!ticket.upload_url || typeof ticket.upload_url !== 'string'
                    || !ticket.authorization || typeof ticket.authorization !== 'string') {
                    throw new Error('CRMS returned an invalid upload ticket. Please try again.');
                }

                progressStatus.textContent = 'Uploading model';
                progressDetail.textContent = `Sending ${formatBytes(selected.reduce((sum, file) => sum + file.size, 0))} directly to the OCR service.`;

                const installed = await uploadDirect(
                    ticket.upload_url,
                    ticket.authorization,
                    nameInput.value.trim(),
                    selected,
                    (percent) => {
                        progress.style.width = `${percent}%`;
                        progress.setAttribute('aria-valuenow', String(percent));
                        progressPercent.textContent = `${percent}%`;
                    },
                    abortController.signal,
                );

                if (typeof installed.name !== 'string' || installed.name === '') {
                    throw new Error('The OCR service returned an invalid installation result.');
                }

                installedName = installed.name;
            }

            progress.style.width = '100%';
            progress.setAttribute('aria-valuenow', '100');
            progressPercent.textContent = '100%';
            progressStatus.textContent = 'Registering model';
            progressDetail.textContent = 'Writing the CRMS registry and audit entry…';
            setButtonBusy(submit, true, 'Registering…');

            const registration = new FormData();
            registration.append('name', installedName);
            const registered = await postForm(config.urls.registerModel, registration);

            if (registered.registered !== true || registered.name !== installedName) {
                throw new Error('CRMS did not confirm model registration. Retry registration or use Rescan models.');
            }

            pendingRegistration = null;
            uploading = false;
            window.location.reload();
        } catch (error) {
            const wasAborted = error.name === 'AbortError' || abortController?.signal?.aborted;

            if (installedName !== null) {
                pendingRegistration = installedName;
                setUploading(false);
                nameInput.readOnly = true;
                clearSelection.disabled = true;
                fileInputs.forEach((input) => { input.disabled = true; });
                setSubmitLabel('Retry registration');
                progressWrap.classList.remove('d-none');
                progressStatus.textContent = 'Registration pending';
                progressDetail.textContent = 'The files are already installed. Retry after a temporary error. If your session expired, sign in again, reopen this workspace, and use Rescan models.';
                renderSummary({
                    ok: false,
                    title: 'Model installed; CRMS registration pending',
                    detail: error.message,
                });
                zone.classList.add('d-none');
                refreshSubmit();
                return;
            }

            setUploading(false);
            progressWrap.classList.add('d-none');
            renderSummary({
                ok: false,
                title: wasAborted ? 'Upload cancelled' : 'Upload failed',
                detail: wasAborted ? 'File upload was cancelled by the user.' : error.message,
            });
            zone.classList.add('d-none');
            refreshSubmit();
        } finally {
            abortController = null;
        }
    });

    refreshSubmit();
}

// ----------------------------------------------------------------- engine status

function initEngineStatus() {
    const status = $('#engine-status');
    if (!status) return;

    const state = $('#engine-state', status);
    let lastReachable = config.engineReachable;
    const FAST_MS = 15_000;
    const SLOW_MS = 30_000;

    const loop = async () => {
        let next = lastReachable ? FAST_MS : SLOW_MS;

        try {
            const engine = await getJson(config.urls.engineStatus);
            const reachable = engine?.reachable === true;

            status.classList.toggle('is-online', reachable);
            status.classList.toggle('is-offline', !reachable);
            if (state) {
                state.textContent = reachable ? 'OCR service online' : 'OCR service offline';
            }

            // A transition changes controls and model discovery; reload once
            // instead of leaving a partially patched page.
            if (reachable !== lastReachable) {
                lastReachable = reachable;
                window.location.reload();
                return;
            }

            next = reachable ? FAST_MS : SLOW_MS;
        } catch {
            next = SLOW_MS;
        }

        window.setTimeout(loop, next);
    };

    window.setTimeout(loop, lastReachable ? FAST_MS : SLOW_MS);
}

// ---------------------------------------------------------------- model actions

function initModelActions() {
    const activateModal = $('#activateModal');
    const activateForm = $('#activateForm');
    const activateSubmit = $('#activate-submit');

    // The Activate button on a row names the model; the dialog posts to its URL.
    activateModal?.addEventListener('show.bs.modal', (event) => {
        const trigger = event.relatedTarget;
        const key = trigger?.dataset.key ?? '';
        const label = trigger?.dataset.label ?? key;

        activateForm.action = url(config.urls.modelActivate, key);
        $('#activateLabel').textContent = label;
        $('#activateKey').textContent = key;
        $('#activateKey').classList.toggle('d-none', label === key);
    });

    activateForm?.addEventListener('submit', () => setButtonBusy(activateSubmit, true, 'Activating…'));

    const renameModal = $('#renameModal');
    const renameForm = $('#renameForm');
    const renameInput = $('#rename-input');
    const renameSubmit = $('#rename-submit');
    let originalKey = '';

    const refreshRename = () => {
        if (!renameSubmit) return;
        const value = renameInput.value.trim();
        renameSubmit.disabled = value === '' || value === originalKey;
    };

    renameModal?.addEventListener('show.bs.modal', (event) => {
        const trigger = event.relatedTarget;
        originalKey = trigger?.dataset.key ?? '';
        renameForm.action = url(config.urls.modelRename, originalKey);
        $('#renameOldKey').textContent = originalKey;
        renameInput.value = originalKey;
        refreshRename();
    });

    renameModal?.addEventListener('shown.bs.modal', () => {
        renameInput.focus();
        renameInput.select();
    });

    renameInput?.addEventListener('input', refreshRename);
    renameForm?.addEventListener('submit', () => setButtonBusy(renameSubmit, true, 'Renaming…'));

    const deleteModal = $('#deleteModal');
    const deleteForm = $('#deleteForm');
    const deleteSubmit = $('#delete-submit');

    deleteModal?.addEventListener('show.bs.modal', (event) => {
        const trigger = event.relatedTarget;
        const key = trigger?.dataset.key ?? '';
        const label = trigger?.dataset.label ?? key;

        deleteForm.action = url(config.urls.modelDestroy, key);
        $('#deleteLabel').textContent = label;
        $('#deleteKey').textContent = key;
        $('#deleteKeyRow').classList.toggle('d-none', label === key);
    });

    deleteForm?.addEventListener('submit', () => setButtonBusy(deleteSubmit, true, 'Deleting…'));
}

// ---------------------------------------------------------- model performance

function readModelPerformance() {
    const node = $('#ocr-model-performance-data');

    if (!node) return null;

    try {
        return JSON.parse(node.textContent || '{}');
    } catch {
        return null;
    }
}

function initRescanForm() {
    const rescanForm = $('#ocr-rescan-form');
    if (!rescanForm) return;

    rescanForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = $('#ocr-rescan-btn') || $('button[type="submit"]', rescanForm);
        setButtonBusy(btn, true, 'Rescanning...');

        try {
            const response = await fetch(rescanForm.action, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': config.csrf,
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                const payload = await response.json().catch(() => null);
                throw new Error(payload?.message || `HTTP ${response.status}`);
            }

            window.location.reload();
        } catch (err) {
            alert(`Failed to rescan models: ${err.message}`);
            setButtonBusy(btn, false);
        }
    });
}

function initModelPerformance() {
    let data = readModelPerformance();
    const target = $('#ocr-model-radar');
    const selector = $('#ocr-performance-model');

    if (!data || !target) return;

    let metrics = Array.isArray(data.metrics) ? data.metrics : [];
    let profiles = new Map(
        (Array.isArray(data.models) ? data.models : []).map((profile) => [String(profile.key), profile]),
    );
    const empty = $('#ocr-performance-empty');
    const emptyCopy = $('#ocr-performance-empty-copy');
    const emptyCommand = $('#ocr-empty-command');
    const inspectedModelBadge = $('#ocr-inspected-model-name');
    const btnRadar = $('#ocr-btn-view-radar');
    const btnBars = $('#ocr-btn-view-bars');
    const compareToggle = $('#ocr-compare-toggle');
    const compareToggleWrap = $('#ocr-compare-toggle-wrap');
    const toolbar = $('#ocr-performance-toolbar');
    const kpiWrap = $('#ocr-performance-kpis');

    let chart = null;
    // Radar first; the Side-by-side button switches to bars.
    let currentView = 'radar';
    let overlayBase = true;
    let currentProfileKey = String(data.selected ?? '');

    const kpiVals = new Map(
        Array.from(document.querySelectorAll('[data-kpi-val]')).map((n) => [n.dataset.kpiVal, n]),
    );
    const kpiDeltas = new Map(
        Array.from(document.querySelectorAll('[data-kpi-delta]')).map((n) => [n.dataset.kpiDelta, n]),
    );
    const kpiSubs = new Map(
        Array.from(document.querySelectorAll('[data-kpi-sub]')).map((n) => [n.dataset.kpiSub, n]),
    );

    const theme = () => {
        const styles = getComputedStyle(target.closest('.ocr-workspace') || document.documentElement);

        return {
            primary: styles.getPropertyValue('--bs-primary').trim() || '#696cff',
            character: styles.getPropertyValue('--ocr-metric-character').trim() || '#696cff',
            word: styles.getPropertyValue('--ocr-metric-word').trim() || '#03c3ec',
            exact: styles.getPropertyValue('--ocr-metric-exact').trim() || '#71dd37',
            heading: styles.getPropertyValue('--bs-heading-color').trim() || '#384551',
            text: styles.getPropertyValue('--bs-secondary-color').trim() || '#8592a3',
            border: styles.getPropertyValue('--bs-border-color').trim() || 'rgba(67, 89, 113, .12)',
            card: styles.getPropertyValue('--bs-card-bg').trim() || '#fff',
            font: styles.getPropertyValue('--bs-body-font-family').trim() || 'Public Sans, sans-serif',
        };
    };

    const scoreValue = (profile, key) => {
        const value = profile?.scores?.[key];

        return value === null || value === undefined || !Number.isFinite(Number(value))
            ? null
            : Number(value);
    };

    // One decimal, but two near either end of the scale: 99.97% must not read
    // as a perfect 100.0%, nor a 0.03% error rate as none at all.
    const pct = (value) => {
        const number = Number(value);
        if (value === null || value === undefined || !Number.isFinite(number)) return '—';
        const fine = (number > 99 && number < 100) || (number > 0 && number < 1);

        return `${number.toFixed(fine ? 2 : 1)}%`;
    };

    const updateSummary = (profile) => {
        const friendlyName = profile?.display_label || profile?.label || 'No model selected';
        if (inspectedModelBadge) {
            inspectedModelBadge.textContent = `${friendlyName}${profile?.is_active ? ' (active)' : ''}`;
        }
        if (emptyCommand && profile?.key) {
            emptyCommand.textContent = `python ml/test_finetuned.py --model ${profile.key}`;
        }

        // Reading speed from real scans, for whichever model is being inspected.
        const reading = profile?.reading;
        const hasSpeed = Boolean(reading?.ms_per_line);
        const speedVal = document.querySelector('[data-reading-val]');
        const speedSub = document.querySelector('[data-reading-sub]');
        if (speedVal) speedVal.textContent = hasSpeed ? `${Number(reading.ms_per_line).toLocaleString()} ms` : '—';
        if (speedSub) {
            speedSub.textContent = hasSpeed
                ? `per line · ${Number(reading.lines).toLocaleString()} lines in real scans`
                : 'No real scans read yet';
        }
        // The cards are hidden for a model without test scores, so the speed
        // is then said in the footer instead; otherwise there is no footer.
        document.getElementById('ocr-reading-speed')?.classList.toggle('d-none', Boolean(profile?.has_data));

        const speedText = document.querySelector('#ocr-reading-speed [data-reading-text]');
        if (speedText) {
            const reading = profile?.reading;
            speedText.textContent = reading?.ms_per_line
                ? `Reading speed: about ${Number(reading.ms_per_line).toLocaleString()} ms per line, from ${Number(reading.lines).toLocaleString()} lines read in real scans`
                : 'Reading speed: this model has not read any real scans yet';
        }

        const isBase = profile?.key === 'base';
        const baseProfile = !isBase ? profiles.get('base') : null;
        const baseHasData = Boolean(baseProfile?.has_data);
        const isComparing = baseHasData && overlayBase && !isBase;


        if (compareToggleWrap) compareToggleWrap.classList.toggle('d-none', isBase || !baseHasData);
        // Keep KPI cards and toolbar visible whenever the selected model has evaluation data
        if (kpiWrap) kpiWrap.classList.toggle('d-none', !profile?.has_data);
        if (toolbar) toolbar.classList.toggle('d-none', !profile?.has_data);

        // Update Quick Comparison KPI Delta Strip
        metrics.forEach((metric) => {
            const kpiValNode = kpiVals.get(metric.key);
            const kpiDeltaNode = kpiDeltas.get(metric.key);
            const kpiSubNode = kpiSubs.get(metric.key);

            const value = scoreValue(profile, metric.key);
            const baseValue = baseHasData ? scoreValue(baseProfile, metric.key) : null;

            if (kpiValNode) kpiValNode.textContent = pct(value);

            // Error rate for the first two cards (accuracy is 100 minus it), so the
            // last line carries the figure the evaluation report actually measured.
            const errorRate = value === null ? null : ({
                character_accuracy: profile?.cer != null ? `CER ${pct(profile.cer)}` : null,
                word_accuracy: profile?.wer != null ? `WER ${pct(profile.wer)}` : null,
            })[metric.key] ?? null;

            if (isComparing && value !== null && baseValue !== null) {
                // Percentage points, not percent: 76.0% against 48.5% is 27.5 points.
                const diff = value - baseValue;
                const sign = diff > 0 ? '+' : (diff < 0 ? '−' : '±');

                if (kpiDeltaNode) {
                    kpiDeltaNode.textContent = `${sign}${Math.abs(diff).toFixed(1)} pts`;
                    kpiDeltaNode.className = `badge ${diff >= 0 ? 'bg-label-success' : 'bg-label-danger'} ocr-kpi-delta`;
                    kpiDeltaNode.title = `${Math.abs(diff).toFixed(1)} percentage points ${diff >= 0 ? 'above' : 'below'} the base model`;
                }
                if (kpiSubNode) {
                    kpiSubNode.textContent = [`Base ${pct(baseValue)}`, errorRate].filter(Boolean).join(' · ');
                }
            } else if (isBase && value !== null) {
                if (kpiDeltaNode) {
                    kpiDeltaNode.textContent = 'Baseline';
                    kpiDeltaNode.className = 'badge bg-label-warning ocr-kpi-delta';
                    kpiDeltaNode.title = 'The base model is the reference the others are compared with';
                }
                if (kpiSubNode) kpiSubNode.textContent = errorRate ?? 'Reference';
            } else {
                if (kpiDeltaNode) {
                    kpiDeltaNode.className = 'badge ocr-kpi-delta d-none';
                    kpiDeltaNode.removeAttribute('title');
                }
                if (kpiSubNode) {
                    // A non-breaking space keeps the line, so card heights stay equal.
                    kpiSubNode.textContent = errorRate ?? (value === null ? 'Not evaluated' : '\u00a0');
                }
            }
        });
    };

    const makeTooltipHtml = (profile, baseProfile, scores, baseScores, showBaseComparison, dataPointIndex, w) => {
        const collapsed = w?.globals?.collapsedSeriesIndices || [];
        const isModelVisible = !collapsed.includes(0);
        const isBaseVisible = showBaseComparison && !collapsed.includes(1);

        if (!isModelVisible && !isBaseVisible) {
            return '';
        }

        const metric = metrics[dataPointIndex];
        const val = scores?.[dataPointIndex];
        const bVal = baseScores ? baseScores[dataPointIndex] : null;
        const diff = (val !== null && bVal !== null && val !== undefined && bVal !== undefined)
            ? val - bVal
            : null;
        const sign = diff !== null && diff >= 0 ? '+' : '';
        const relGain = (diff !== null && bVal > 0) ? ((diff / bVal) * 100).toFixed(1) : null;
        const profileLabel = profile?.display_label || profile?.label || 'Selected Model';

        return `<div class="ocr-apex-tooltip">
            <div class="ocr-tooltip-title">${metric.label}</div>
            ${isModelVisible ? `
            <div class="ocr-tooltip-row">
                <div><span class="ocr-tooltip-badge" style="background:#ff6384"></span><strong>${profileLabel}</strong></div>
                <span class="fw-bold text-primary">${pct(val)}</span>
            </div>` : ''}
            ${isBaseVisible && bVal !== null && bVal !== undefined ? `
            <div class="ocr-tooltip-row">
                <div><span class="ocr-tooltip-badge" style="background:#696cff"></span><span>TrOCR Base</span></div>
                <span class="text-muted">${pct(bVal)}</span>
            </div>` : ''}
            ${isModelVisible && isBaseVisible && diff !== null ? `
            <div class="ocr-tooltip-delta ${diff >= 0 ? 'text-success' : 'text-danger'}">
                <i class="icon-base bx ${diff >= 0 ? 'bx-trending-up' : 'bx-trending-down'}"></i>
                <span>${sign}${diff.toFixed(1)}% absolute gain ${relGain ? `(${sign}${relGain}% relative lift)` : ''}</span>
            </div>` : ''}
        </div>`;
    };

    const render = (profile) => {
        updateSummary(profile);

        const scores = metrics.map((metric) => scoreValue(profile, metric.key));
        const hasData = Boolean(profile?.has_data)
            && metrics.length >= 3
            && scores.every((score) => score !== null);

        // Destroying the chart empties its container for a moment. The page gets
        // shorter, the browser clamps the scroll position, and a reader at the
        // bottom is thrown upward. Hold the old height and scroll position until
        // the new chart has drawn.
        const scrollY = window.scrollY;
        if (chart && target.offsetHeight > 0) {
            target.style.minHeight = `${target.offsetHeight}px`;
        }
        const releaseLayout = () => {
            target.style.minHeight = '';
            if (window.scrollY !== scrollY) {
                window.scrollTo({ top: scrollY, behavior: 'instant' });
            }
        };

        chart?.destroy();
        chart = null;

        target.classList.toggle('d-none', !hasData);
        empty?.classList.toggle('d-none', hasData);

        if (!hasData) {
            releaseLayout();
            target.setAttribute('aria-label', 'No model performance data available');
            if (emptyCopy) {
                emptyCopy.textContent = profile
                    ? `${profile.display_label || profile.label} has no valid locked-test benchmark report.`
                    : 'Add a model before reviewing its performance.';
            }
            return;
        }

        const colors = theme();
        const metricColors = metrics.map((metric) => ({
            character_accuracy: colors.character,
            word_accuracy: colors.word,
            exact_match: colors.exact,
        })[metric.key] || colors.primary);
        target.setAttribute('aria-label', `Performance evaluation for ${profile.display_label || profile.label}`);

        const isBase = profile?.key === 'base';
        const baseProfile = !isBase ? profiles.get('base') : null;
        const baseHasData = Boolean(baseProfile?.has_data);
        const baseScores = baseHasData
            ? metrics.map((metric) => scoreValue(baseProfile, metric.key))
            : null;
        const showBaseComparison = baseHasData
            && overlayBase
            && !isBase
            && Array.isArray(baseScores)
            && baseScores.every((s) => s !== null);

        const series = [{
            name: `${profile.display_label || profile.label} (Fine-tuned)`,
            data: scores,
        }];

        if (showBaseComparison) {
            series.push({
                name: 'TrOCR Base (Baseline)',
                data: baseScores,
            });
        }

        // Same pink as the model triangle on the dashboard's OCR Performance card.
        const modelColor = '#ff6384';
        const comparisonColors = [modelColor, '#696cff'];

        if (currentView === 'radar') {
            const radarSeries = [
                ...series,
                {
                    name: 'ocr_radar_grid_anchor',
                    data: metrics.map(() => 0),
                },
            ];

            chart = new ApexCharts(target, {
                chart: {
                    type: 'radar',
                    height: 400,
                    fontFamily: colors.font,
                    foreColor: colors.text,
                    parentHeightOffset: 0,
                    toolbar: { show: false },
                    animations: {
                        enabled: true,
                        easing: 'easeinout',
                        speed: 350,
                    },
                },
                colors: showBaseComparison
                    ? [...comparisonColors, 'transparent']
                    : [modelColor, 'transparent'],
                dataLabels: { enabled: false },
                fill: showBaseComparison
                    ? { type: 'solid', opacity: [0.32, 0.18, 0] }
                    : { type: 'solid', opacity: [0.32, 0] },
                grid: {
                    padding: { top: 12, right: 24, bottom: 8, left: 24 },
                },
                legend: {
                    show: showBaseComparison,
                    position: 'bottom',
                    horizontalAlign: 'center',
                    fontSize: '13px',
                    fontWeight: 600,
                    fontFamily: colors.font,
                    markers: { radius: 6, width: 10, height: 10 },
                    itemMargin: { horizontal: 16, vertical: 6 },
                    offsetY: 8,
                },
                // Hollow dots like the dashboard card: card-coloured centre, series-coloured ring.
                markers: showBaseComparison
                    ? {
                        size: [5, 4, 0],
                        colors: [colors.card, colors.card, 'transparent'],
                        strokeColors: [modelColor, '#696cff', 'transparent'],
                        strokeWidth: [2.5, 1.5, 0],
                        hover: { size: 7 },
                    }
                    : {
                        size: [5, 0],
                        colors: [colors.card, 'transparent'],
                        strokeColors: [modelColor, 'transparent'],
                        strokeWidth: [2.5, 0],
                        hover: { size: 7 },
                    },
                plotOptions: {
                    radar: {
                        size: 185,
                        offsetY: 45,
                        polygons: {
                            strokeColors: colors.border,
                            strokeWidth: 1.5,
                            connectorColors: colors.border,
                            fill: { colors: ['rgba(67, 89, 113, 0.03)', 'transparent'] },
                        },
                    },
                },
                series: radarSeries,
                stroke: showBaseComparison
                    ? { width: [3, 1.5, 0], dashArray: [0, 4, 0] }
                    : { width: [3, 0], dashArray: [0, 0] },
                tooltip: {
                    custom: ({ dataPointIndex, w }) => makeTooltipHtml(profile, baseProfile, scores, baseScores, showBaseComparison, dataPointIndex, w),
                },
                xaxis: {
                    categories: metrics.map((metric) => metric.axis || metric.label),
                    labels: {
                        // Dashboard style: dark label with the model's score;
                        // the top one stays on a line, the side ones stack.
                        formatter: (label, opts) => {
                            const index = opts?.dataPointIndex ?? 0;
                            const score = Number.isFinite(scores[index]) ? pct(scores[index]) : '';

                            if (!score) return label;

                            return index === 0 ? `${label} ${score}` : [label, score];
                        },
                        style: {
                            colors: metrics.map(() => colors.heading),
                            fontSize: '12px',
                            fontWeight: 600,
                        },
                    },
                },
                yaxis: {
                    show: false,
                    min: 0,
                    max: 100,
                    tickAmount: 4,
                },
                responsive: [{
                    breakpoint: 575,
                    options: {
                        chart: { height: 320 },
                        grid: { padding: { top: 8, right: 8, bottom: 8, left: 8 } },
                        markers: {
                            size: showBaseComparison ? [5, 4, 0] : [5, 0],
                            strokeWidth: showBaseComparison ? [2, 1.5, 0] : [2, 0],
                            hover: { size: 7 },
                        },
                        plotOptions: { radar: { size: 105, offsetY: 0 } },
                    },
                }],
            });
        } else {
            // Grouped horizontal comparison bars
            chart = new ApexCharts(target, {
                chart: {
                    type: 'bar',
                    height: 380,
                    fontFamily: colors.font,
                    foreColor: colors.text,
                    toolbar: { show: false },
                    animations: {
                        enabled: true,
                        easing: 'easeinout',
                        speed: 350,
                    },
                },
                plotOptions: {
                    bar: {
                        horizontal: true,
                        barHeight: '52%',
                        borderRadius: 0,
                        dataLabels: { position: 'center' },
                    },
                },
                colors: showBaseComparison ? comparisonColors : [colors.primary],
                dataLabels: {
                    enabled: true,
                    style: {
                        fontSize: '12px',
                        fontWeight: 700,
                        colors: ['#fff'],
                    },
                    formatter: (v) => pct(v),
                },
                stroke: {
                    show: true,
                    width: 1,
                    colors: ['transparent'],
                },
                grid: {
                    borderColor: colors.border,
                    strokeDashArray: 4,
                    padding: { top: 8, right: 20, bottom: 12, left: 16 },
                },
                legend: {
                    show: showBaseComparison,
                    position: 'bottom',
                    horizontalAlign: 'center',
                    fontSize: '13px',
                    fontWeight: 600,
                    fontFamily: colors.font,
                    markers: { radius: 6, width: 10, height: 10 },
                    itemMargin: { horizontal: 16, vertical: 6 },
                    offsetY: 8,
                },
                series,
                tooltip: {
                    custom: ({ dataPointIndex, w }) => makeTooltipHtml(profile, baseProfile, scores, baseScores, showBaseComparison, dataPointIndex, w),
                },
                xaxis: {
                    categories: metrics.map((m) => m.label),
                    min: 0,
                    max: 100,
                    labels: {
                        formatter: (v) => `${Math.round(v)}%`,
                        style: { colors: colors.text, fontSize: '11px' },
                    },
                },
                yaxis: {
                    labels: {
                        style: {
                            colors: metricColors,
                            fontSize: '13px',
                            fontWeight: 600,
                        },
                    },
                },
            });
        }

        Promise.resolve(chart.render()).finally(releaseLayout);
    };

    const highlightModelRow = (key) => {
        document.querySelectorAll('.ocr-model-row').forEach((row) => {
            const isMatch = row.dataset.modelKey === key;
            row.classList.toggle('is-inspected', isMatch);
        });
    };

    const inspectModel = (key) => {
        if (!key) return;
        currentProfileKey = key;
        if (selector) selector.value = key;
        const profile = profiles.get(key);
        if (profile) {
            render(profile);
            highlightModelRow(key);
        }
    };

    const bindModelRowInteractions = () => {
        document.querySelectorAll('.ocr-model-row').forEach((row) => {
            row.addEventListener('click', (e) => {
                if (e.target.closest('button, form, a, input, .dropdown, .dropdown-menu')) {
                    return;
                }
                const key = row.dataset.modelKey;
                if (key) inspectModel(key);
            });

            row.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    if (e.target.closest('button, form, a, input, .dropdown, .dropdown-menu')) return;
                    e.preventDefault();
                    const key = row.dataset.modelKey;
                    if (key) inspectModel(key);
                }
            });
        });
    };

    bindModelRowInteractions();

    if (btnRadar && btnBars) {
        const setView = (view) => {
            if (currentView === view) return;
            currentView = view;

            [[btnRadar, 'radar'], [btnBars, 'bars']].forEach(([button, name]) => {
                const on = name === view;
                button.classList.toggle('btn-primary', on);
                button.classList.toggle('btn-label-secondary', !on);
                button.setAttribute('aria-pressed', on ? 'true' : 'false');
            });

            render(profiles.get(currentProfileKey));
        };

        btnRadar.addEventListener('click', () => setView('radar'));
        btnBars.addEventListener('click', () => setView('bars'));
    }

    if (compareToggle) {
        compareToggle.addEventListener('change', () => {
            overlayBase = compareToggle.checked;
            render(profiles.get(currentProfileKey));
        });
    }

    if (selector) {
        selector.addEventListener('change', () => inspectModel(selector.value));
    }

    // Chart colours are read when it is drawn; redraw after a colour-mode switch.
    window.addEventListener('crms:themechange', () => render(profiles.get(currentProfileKey)));

    function updatePerformanceDataset(nextData) {
        if (!nextData || typeof nextData !== 'object') return;

        data = nextData;
        metrics = Array.isArray(data.metrics) ? data.metrics : metrics;
        profiles = new Map(
            (Array.isArray(data.models) ? data.models : []).map((p) => [String(p.key), p]),
        );

        if (selector) {
            const prevVal = selector.value;
            selector.innerHTML = '';
            if (profiles.size === 0) {
                const opt = document.createElement('option');
                opt.value = '';
                opt.textContent = 'No models available';
                selector.appendChild(opt);
                selector.disabled = true;
            } else {
                profiles.forEach((p) => {
                    const opt = document.createElement('option');
                    opt.value = p.key;
                    opt.textContent = `${p.display_label || p.label}${p.is_active ? ' (active)' : ''}`;
                    selector.appendChild(opt);
                });
                selector.disabled = profiles.size < 2;
            }
        }

        const nextProfile = profiles.get(currentProfileKey)
            ?? profiles.get(String(data.selected))
            ?? profiles.values().next().value
            ?? null;

        if (nextProfile) {
            currentProfileKey = nextProfile.key;
            if (selector) selector.value = nextProfile.key;
            render(nextProfile);
            highlightModelRow(nextProfile.key);
        }
    }

    window.crmsUpdateModelPerformance = updatePerformanceDataset;
    window.addEventListener('ocr:performance-updated', (event) => {
        if (event.detail) updatePerformanceDataset(event.detail);
    });

    const initial = profiles.get(String(data.selected ?? currentProfileKey))
        ?? profiles.get(currentProfileKey)
        ?? (selector ? profiles.get(selector.value) : null)
        ?? profiles.values().next().value;

    if (initial) {
        currentProfileKey = initial.key;
        if (selector) selector.value = initial.key;
        render(initial);
        highlightModelRow(initial.key);
    }
}

// ----------------------------------------------------------------------- bootstrap

document.addEventListener('DOMContentLoaded', () => {
    initModelUpload();
    initModelActions();
    initEngineStatus();
    initRescanForm();
    initModelPerformance();
});
