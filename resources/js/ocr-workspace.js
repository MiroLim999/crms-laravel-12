import ApexCharts from 'apexcharts';

/**
 * OCR Workspace front end (Super Admin only).
 *
 * This page owns direct model uploads, model-action modals, dirty settings state,
 * and service-status polling. Application URLs and server limits come from
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

function initModelList() {
    const toggle = $('[data-models-toggle]');
    const list = $('#installed-models-list');
    const card = toggle?.closest('.ocr-models-card');
    const header = $('.card-header', card);
    const policyCard = $('.ocr-policy-card');
    const desktopLayout = window.matchMedia('(min-width: 1200px)');
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    let cardAnimation;

    if (!toggle || !list || !card || !header) return;

    toggle.addEventListener('click', () => {
        const expanded = toggle.getAttribute('aria-expanded') !== 'true';
        const startHeight = card.getBoundingClientRect().height;

        cardAnimation?.cancel();

        if (expanded) {
            card.classList.add('has-expanded-models');
        }

        list.classList.toggle('is-expanded', expanded);
        list.setAttribute('aria-hidden', expanded ? 'false' : 'true');
        list.inert = !expanded;
        toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        toggle.setAttribute('aria-label', expanded ? 'Hide models' : 'Show models');
        toggle.setAttribute('title', expanded ? 'Hide models' : 'Show models');

        const label = $('span', toggle);
        if (label) label.textContent = expanded ? 'Hide models' : 'Show models';

        if (!desktopLayout.matches || reducedMotion.matches) {
            if (!expanded) card.classList.remove('has-expanded-models');
            return;
        }

        const endHeight = expanded
            ? (policyCard?.getBoundingClientRect().height ?? card.getBoundingClientRect().height)
            : header.getBoundingClientRect().height;

        cardAnimation = card.animate(
            [
                { height: `${startHeight}px` },
                { height: `${endHeight}px` },
            ],
            {
                duration: 300,
                easing: 'cubic-bezier(.4, 0, .2, 1)',
            },
        );

        cardAnimation.addEventListener('finish', () => {
            if (toggle.getAttribute('aria-expanded') === 'false') {
                card.classList.remove('has-expanded-models');
            }

            cardAnimation = null;
        }, { once: true });
    });
}

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

// ----------------------------------------------------------------- settings form

function initSettingsForm() {
    const form = $('#ocrSettingsForm');
    if (!form) return;

    const save = $('#settings-save');
    const discard = $('#settings-discard');
    const dirtyNote = $('#settings-dirty');
    const cleanNote = $('#settings-clean');
    const fields = [$('#model-select'), $('#allow-staff-choice'), $('#threshold-input')].filter(Boolean);

    const valueOf = (field) => (field.type === 'checkbox' ? field.checked : field.value.trim());
    const baseline = new Map(fields.map((field) => [field, valueOf(field)]));

    const refresh = () => {
        const dirty = fields.some((field) => valueOf(field) !== baseline.get(field));
        save.disabled = !dirty;
        discard.disabled = !dirty;
        dirtyNote.classList.toggle('d-none', !dirty);
        cleanNote.classList.toggle('d-none', dirty);
    };

    fields.forEach((field) => {
        field.addEventListener('input', refresh);
        field.addEventListener('change', refresh);
    });

    discard.addEventListener('click', () => {
        fields.forEach((field) => {
            const original = baseline.get(field);
            if (field.type === 'checkbox') {
                field.checked = original;
            } else {
                field.value = original;
            }
        });
        refresh();
    });

    form.addEventListener('submit', () => {
        setButtonBusy(save, true, 'Saving…');
        discard.disabled = true;
    });

    refresh();
}

// ----------------------------------------------------------------- engine status

async function copyToClipboard(text) {
    if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(text);
        return;
    }

    const field = document.createElement('textarea');
    field.value = text;
    field.style.position = 'fixed';
    field.style.opacity = '0';
    document.body.appendChild(field);
    field.select();
    document.execCommand('copy');
    field.remove();
}

function initEngineStatus() {
    if (!$('#engine-card')) return;

    document.querySelectorAll('.js-copy-command').forEach((copy) => {
        copy.addEventListener('click', async () => {
            const label = $('span', copy);
            const target = document.getElementById(copy.dataset.copyTarget);
            if (!label || !target) return;

            const idle = label.textContent;

            try {
                await copyToClipboard(target.textContent.trim());
                label.textContent = 'Copied';
            } catch {
                label.textContent = 'Copy failed';
            }

            window.setTimeout(() => { label.textContent = idle; }, 1600);
        });
    });

    let lastReachable = config.engineReachable;
    const FAST_MS = 15_000;
    const SLOW_MS = 30_000;

    const loop = async () => {
        let next = lastReachable ? FAST_MS : SLOW_MS;

        try {
            const engine = await getJson(config.urls.engineStatus);
            $('#engine-dot').className = `ocr-status-dot ${engine.reachable ? 'is-online' : 'is-offline'}`;
            $('#engine-state').textContent = engine.reachable
                ? 'OCR service online'
                : 'OCR service offline';
            $('#engine-device').textContent = engine.device || 'not loaded';

            // A transition changes controls, model discovery, and recovery content;
            // reload once instead of leaving a partially patched page.
            if (engine.reachable !== lastReachable) {
                lastReachable = engine.reachable;
                window.location.reload();
                return;
            }

            next = engine.reachable ? FAST_MS : SLOW_MS;
        } catch {
            next = SLOW_MS;
        }

        window.setTimeout(loop, next);
    };

    window.setTimeout(loop, lastReachable ? FAST_MS : SLOW_MS);
}

// ---------------------------------------------------------------- model actions

function initModelActions() {
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

function initModelPerformance() {
    const data = readModelPerformance();
    const target = $('#ocr-model-radar');
    const selector = $('#ocr-performance-model');

    if (!data || !target || !selector) return;

    const metrics = Array.isArray(data.metrics) ? data.metrics : [];
    const profiles = new Map(
        (Array.isArray(data.models) ? data.models : []).map((profile) => [String(profile.key), profile]),
    );
    const empty = $('#ocr-performance-empty');
    const emptyCopy = $('#ocr-performance-empty-copy');
    const source = $('#ocr-performance-source');
    const modelName = $('#ocr-performance-model-name');
    const active = $('#ocr-performance-active');
    const evidence = $('#ocr-performance-evidence');
    let chart = null;

    const scoreNodes = new Map(
        Array.from(document.querySelectorAll('[data-performance-score]'))
            .map((node) => [node.dataset.performanceScore, node]),
    );
    const scoreBars = new Map(
        Array.from(document.querySelectorAll('[data-performance-bar]'))
            .map((node) => [node.dataset.performanceBar, node]),
    );

    const theme = () => {
        const styles = getComputedStyle(target.closest('.ocr-workspace') || document.documentElement);

        return {
            primary: styles.getPropertyValue('--bs-primary').trim() || '#0d6efd',
            character: styles.getPropertyValue('--ocr-metric-character').trim() || '#0d6efd',
            word: styles.getPropertyValue('--ocr-metric-word').trim() || '#00a7c4',
            exact: styles.getPropertyValue('--ocr-metric-exact').trim() || '#2aa876',
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

    const updateSummary = (profile) => {
        if (modelName) modelName.textContent = profile?.label || 'No model selected';
        if (active) active.classList.toggle('d-none', !profile?.is_active);
        if (evidence) evidence.textContent = profile?.evidence || 'No evidence available.';

        if (source) {
            const tone = profile?.source === 'evaluation'
                ? 'primary'
                : 'secondary';
            source.className = `badge bg-label-${tone}`;
            source.textContent = profile?.source_label || 'No data';
        }

        metrics.forEach((metric) => {
            const node = scoreNodes.get(metric.key);
            const bar = scoreBars.get(metric.key);
            const value = scoreValue(profile, metric.key);
            if (node) node.textContent = value === null ? '—' : `${value.toFixed(1)}%`;
            if (bar) bar.style.width = `${Math.max(0, Math.min(100, value ?? 0))}%`;
        });
    };

    const render = (profile) => {
        updateSummary(profile);

        const scores = metrics.map((metric) => scoreValue(profile, metric.key));
        const hasData = Boolean(profile?.has_data)
            && metrics.length >= 3
            && scores.every((score) => score !== null);

        chart?.destroy();
        chart = null;

        target.classList.toggle('d-none', !hasData);
        empty?.classList.toggle('d-none', hasData);

        if (!hasData) {
            target.setAttribute('aria-label', 'No model performance data available');
            if (emptyCopy) {
                emptyCopy.textContent = profile
                    ? `${profile.label} has no valid locked-test benchmark report.`
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
        target.setAttribute('aria-label', `Performance radar for ${profile.label}`);

        chart = new ApexCharts(target, {
            chart: {
                type: 'radar',
                height: 460,
                fontFamily: colors.font,
                foreColor: colors.text,
                parentHeightOffset: 0,
                toolbar: { show: false },
                animations: {
                    enabled: true,
                    easing: 'easeinout',
                    speed: 450,
                },
            },
            colors: [colors.primary],
            dataLabels: { enabled: false },
            fill: {
                type: 'gradient',
                gradient: {
                    type: 'horizontal',
                    shadeIntensity: 0,
                    inverseColors: false,
                    colorStops: [
                        { offset: 0, color: colors.exact, opacity: 0.2 },
                        { offset: 50, color: colors.character, opacity: 0.22 },
                        { offset: 100, color: colors.word, opacity: 0.2 },
                    ],
                },
            },
            grid: {
                padding: { top: 12, right: 24, bottom: 12, left: 24 },
            },
            legend: { show: false },
            markers: {
                size: 6,
                strokeColors: colors.card,
                strokeWidth: 3,
                discrete: metricColors.map((color, dataPointIndex) => ({
                    seriesIndex: 0,
                    dataPointIndex,
                    fillColor: color,
                    strokeColor: colors.card,
                    size: 7,
                })),
                hover: { size: 8 },
            },
            plotOptions: {
                radar: {
                    size: 205,
                    polygons: {
                        strokeColors: colors.border,
                        strokeWidth: 1,
                        connectorColors: colors.border,
                        fill: { colors: ['rgba(67, 89, 113, 0.035)', 'transparent'] },
                    },
                },
            },
            series: [{
                name: profile.label,
                data: scores,
            }],
            stroke: { width: 3.5 },
            tooltip: {
                marker: { show: false },
                y: { formatter: (value) => `${Number(value).toFixed(1)}%` },
            },
            xaxis: {
                categories: metrics.map((metric) => metric.axis || metric.label),
                labels: {
                    style: {
                        colors: metricColors,
                        fontSize: '14px',
                        fontWeight: 600,
                    },
                },
            },
            yaxis: {
                min: 0,
                max: 100,
                tickAmount: 4,
                labels: {
                    show: false,
                    formatter: (value) => `${Math.round(value)}`,
                    style: { colors: [colors.text], fontSize: '11px', fontWeight: 500 },
                },
            },
            responsive: [{
                breakpoint: 575,
                options: {
                    chart: { height: 350 },
                    grid: { padding: { top: 4, right: 4, bottom: 4, left: 4 } },
                    markers: { size: 5, strokeWidth: 2, hover: { size: 7 } },
                    plotOptions: { radar: { size: 120 } },
                    xaxis: {
                        labels: {
                            style: {
                                colors: metricColors,
                                fontSize: '12px',
                                fontWeight: 600,
                            },
                        },
                    },
                },
            }],
        });

        chart.render();
    };

    selector.addEventListener('change', () => render(profiles.get(selector.value)));

    const initial = profiles.get(String(data.selected ?? selector.value))
        ?? profiles.get(selector.value)
        ?? profiles.values().next().value;

    if (initial) selector.value = initial.key;
    render(initial);
}

// ----------------------------------------------------------------------- bootstrap

document.addEventListener('DOMContentLoaded', () => {
    initModelList();
    initModelUpload();
    initSettingsForm();
    initModelActions();
    initEngineStatus();
    initModelPerformance();
});
