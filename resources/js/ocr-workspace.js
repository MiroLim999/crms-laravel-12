/**
 * OCR Workspace front end (Super Admin only).
 *
 * This page owns chunked model uploads, model-action modals, dirty settings state,
 * and service-status polling. Application URLs and server limits come from
 * window.crmsOcr, rendered by the Blade view.
 */

const config = window.crmsOcr ?? {};
const CHUNK_SIZE = Number(config.chunkBytes) > 0
    ? Number(config.chunkBytes)
    : 16 * 1024 * 1024;

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

const randomId = () =>
    Array.from(crypto.getRandomValues(new Uint8Array(16)))
        .map((byte) => byte.toString(16).padStart(2, '0'))
        .join('');

const post = (endpoint, body, headers = {}) =>
    fetch(endpoint, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': config.csrf, Accept: 'application/json', ...headers },
        body,
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

// ------------------------------------------------------------------ chunked upload

/** Paths supplied by folder pickers and directory drag traversal. */
const relativePaths = new WeakMap();

const relativePathFor = (file) => {
    const path = relativePaths.get(file) || file.webkitRelativePath;
    return path ? path.replace(/\\/g, '/') : (file.name || 'file').split(/[\\/]/).pop();
};

const discardUpload = (uploadId) => {
    const body = new FormData();
    body.append('upload_id', uploadId);
    return post(config.urls.discardUpload, body).catch(() => null);
};

/** Send one file sequentially in slices. */
async function uploadFile(uploadId, file, onProgress) {
    const total = Math.max(Math.ceil(file.size / CHUNK_SIZE), 1);
    const fileKey = randomId();
    const name = (file.name || 'file').split(/[\\/]/).pop();
    const relativePath = relativePathFor(file);

    for (let index = 0; index < total; index += 1) {
        const start = index * CHUNK_SIZE;
        const slice = file.slice(start, start + CHUNK_SIZE);
        const body = new FormData();

        body.append('upload_id', uploadId);
        body.append('file_key', fileKey);
        body.append('filename', name);
        body.append('relative_path', relativePath);
        body.append('index', String(index));
        body.append('total', String(total));
        body.append('chunk', slice, `${name}.part${index}`);

        const response = await post(config.urls.chunk, body);

        if (!response.ok) {
            const payload = await response.json().catch(() => ({}));
            const validation = payload.errors ? Object.values(payload.errors).flat()[0] : null;
            throw new Error(
                validation ?? payload.message ?? `Upload of ${name} failed (HTTP ${response.status}).`,
            );
        }

        // PHP can return an empty 200 after discarding a body over post_max_size.
        const result = await response.json().catch(() => null);
        if (!result || result.complete === undefined) {
            throw new Error(
                `Part ${index + 1} of ${total} for ${name} was rejected by the server. ` +
                'Check the PHP request-size limits.',
            );
        }

        onProgress(slice.size);
    }
}

async function uploadAll(uploadId, files, onProgress) {
    const totalBytes = files.reduce((sum, file) => sum + file.size, 0);
    let sent = 0;

    for (const file of files) {
        // Sequential by design: it avoids competing writes and makes retry/progress
        // semantics deterministic for very large model weights.
        await uploadFile(uploadId, file, (bytes) => {
            sent += bytes;
            onProgress(totalBytes === 0 ? 100 : Math.round((sent / totalBytes) * 100));
        });
    }
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
    const uploadIdInput = $('#model-upload-id');
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

    const refreshSubmit = () => {
        submit.disabled = uploading || selected.length === 0 || nameInput.value.trim() === '';
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
        selected = [];
        fileInputs.forEach((input) => { input.value = ''; });
        summary.classList.add('d-none');
        summary.classList.remove('is-valid', 'is-invalid');
        zone.classList.remove('d-none', 'is-dragging');
        clearProgress();
        refreshSubmit();
    };

    const setUploading = (busy) => {
        uploading = busy;
        form.setAttribute('aria-busy', busy ? 'true' : 'false');
        nameInput.disabled = busy;
        clearSelection.disabled = busy;
        cancel.disabled = busy;
        close.disabled = busy;
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
            nameInput.value = source.replace(/[^A-Za-z0-9._-]+/g, '-');
        }

        refreshSubmit();
    };

    wireDropzone({ zone, onFiles: acceptFiles });
    nameInput.addEventListener('input', refreshSubmit);
    clearSelection.addEventListener('click', resetSelection);

    modal.addEventListener('hide.bs.modal', (event) => {
        if (uploading) event.preventDefault();
    });

    modal.addEventListener('hidden.bs.modal', () => {
        if (uploading) return;
        form.reset();
        uploadIdInput.value = '';
        resetSelection();
    });

    window.addEventListener('beforeunload', (event) => {
        if (!uploading) return;
        event.preventDefault();
        event.returnValue = '';
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (!selected.length || !form.checkValidity()) {
            form.reportValidity();
            return;
        }

        const uploadId = randomId();
        setUploading(true);
        progressWrap.classList.remove('d-none');
        progressStatus.textContent = 'Uploading model';
        progressDetail.textContent = `Sending ${selected.length === 1 ? 'the selected file' : `${selected.length} files`} in ${formatBytes(CHUNK_SIZE)} parts.`;

        try {
            await uploadAll(uploadId, selected, (percent) => {
                progress.style.width = `${percent}%`;
                progress.setAttribute('aria-valuenow', String(percent));
                progressPercent.textContent = `${percent}%`;
            });

            uploadIdInput.value = uploadId;
            progress.style.width = '100%';
            progress.setAttribute('aria-valuenow', '100');
            progressPercent.textContent = '100%';
            progressStatus.textContent = 'Installing model';
            progressDetail.textContent = 'Validating and extracting files on the OCR service…';
            setButtonBusy(submit, true, 'Installing…');

            // Native post returns the install result as a flash message. Clear the
            // beforeunload guard immediately before navigation begins.
            uploading = false;
            form.submit();
        } catch (error) {
            await discardUpload(uploadId);
            setUploading(false);
            progressWrap.classList.add('d-none');
            renderSummary({
                ok: false,
                title: 'Upload failed',
                detail: error.message,
            });
            zone.classList.add('d-none');
            refreshSubmit();
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

    const copy = $('#copy-engine-command');
    copy?.addEventListener('click', async () => {
        const label = $('span', copy);
        const idle = label.textContent;

        try {
            await copyToClipboard($('#engine-command').textContent.trim());
            label.textContent = 'Copied';
        } catch {
            label.textContent = 'Copy failed';
        }

        window.setTimeout(() => { label.textContent = idle; }, 1600);
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

// ----------------------------------------------------------------------- bootstrap

document.addEventListener('DOMContentLoaded', () => {
    initModelUpload();
    initSettingsForm();
    initModelActions();
    initEngineStatus();
});
