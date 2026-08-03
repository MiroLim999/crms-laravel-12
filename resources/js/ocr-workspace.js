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
 *   2. Drag and drop    - one .zip or a model folder, with click-to-browse fallbacks.
 *   3. Settings form    - Save stays disabled until something differs from what is
 *                         saved, so the button means "there is a change to apply".
 *   4. Status polling   - notice the service coming up or going away.
 *
 * Endpoints arrive via window.crmsOcr, set in the Blade view. No URLs are hardcoded.
 */

const config = window.crmsOcr ?? {};

/**
 * Slice size, from the server: it reads upload_max_filesize and post_max_size and
 * sends the largest slice it will accept. Hardcoding a value guessed wrong in both
 * directions - wasted requests where the limit is higher, a 413 on every slice where
 * it is lower. The 16 MB is only a fallback for a stale cached page.
 */
const CHUNK_SIZE = Number(config.chunkBytes) > 0 ? Number(config.chunkBytes) : 16 * 1024 * 1024;

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
    const response = await fetch(endpoint, { headers: { Accept: 'application/json' } });
    if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
    }
    return response.json();
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

/**
 * Send one file in slices. Progress is reported in bytes so a multi-file model
 * folder can show a single total.
 */
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

        // PHP silently returns 200 with an empty body when post_max_size is exceeded.
        // Treat any non-JSON or missing `complete` field as a failure.
        const result = await response.json().catch(() => null);
        if (!result || result.complete === undefined) {
            throw new Error(
                `Chunk ${index + 1}/${total} of ${name} was rejected by the server ` +
                `(body size limit). Try a smaller file or contact your administrator.`,
            );
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

// ---------------------------------------------------------------------- dropzone

/**
 * Wire a drag-and-drop area with click-to-browse fallbacks.
 *
 * @param {object} options
 * @param {HTMLElement} options.zone
 * @param {(files: File[]) => void} options.onFiles
 */
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
            ? (await Promise.all(entries.map((entry) => readEntry(entry)))).flat()
            : Array.from(e.dataTransfer?.files ?? []);

        onFiles(files);
    });
}

/** Recursively collect files and retain each path from the dropped root. */
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
                readBatch(); // readEntries returns at most 100 at a time
            }, () => resolve(collected.flat()));
        };

        readBatch();
    });
}

// -------------------------------------------------------------------- model upload

const isZip = (file) => /\.zip$/i.test(file.name || '');

/**
 * Decide whether a selection is a usable model, and say why when it is not.
 *
 * Checked here so an hour of uploading is not spent on a folder the service will
 * reject on arrival. A zip cannot be inspected in the browser, so it is accepted on
 * trust and validated server-side after extraction.
 *
 * @returns {{ok: boolean, message: string}}
 */
function describeSelection(files) {
    const zips = files.filter(isZip);
    const bytes = files.reduce((sum, file) => sum + file.size, 0);

    if (zips.length && files.length > 1) {
        return {
            ok: false,
            message: 'Choose either one .zip or the model folder, not both.',
        };
    }

    if (zips.length === 1) {
        return {
            ok: true,
            message:
                `${zips[0].name} · ${formatBytes(bytes)}. The server will extract it and ` +
                'check the files inside.',
        };
    }

    const names = files.map((file) => relativePathFor(file).split('/').pop());
    const hasConfig = names.includes('config.json');
    const hasWeights =
        names.includes('model.safetensors') || names.includes('pytorch_model.bin');

    if (!hasConfig || !hasWeights) {
        return {
            ok: false,
            message:
                `${files.length} file(s) selected, but ` +
                `${!hasConfig ? 'config.json' : 'the weights file'} is missing.`,
        };
    }

    return {
        ok: true,
        message: `${files.length} file(s), ${formatBytes(bytes)}. Looks like a valid model folder.`,
    };
}

function initModelUpload() {
    const zone = $('#model-dropzone');
    if (!zone) return;

    const form = $('#addModelForm');
    const submit = $('#addModelSubmit');
    const summary = $('#model-file-summary');
    const nameInput = $('#model-name');
    const bar = $('#model-progress');
    const barWrap = $('#model-progress-wrap');
    const uploadIdInput = $('#model-upload-id');

    let selected = [];

    wireDropzone({
        zone,
        onFiles: (files) => {
            if (!files.length) {
                selected = [];
                summary.classList.add('d-none');
                submit.disabled = true;
                return;
            }

            const verdict = describeSelection(files);

            selected = verdict.ok ? files : [];
            submit.disabled = !verdict.ok;

            summary.className = `alert mb-0 mt-3 ${verdict.ok ? 'alert-success' : 'alert-danger'}`;
            summary.textContent = verdict.message;
            summary.classList.remove('d-none');

            // Suggest a name from the archive or the wrapping folder, so the common
            // case needs no typing.
            if (verdict.ok && !nameInput.value) {
                const source = isZip(files[0])
                    ? files[0].name.replace(/\.zip$/i, '')
                    : relativePathFor(files[0]).split('/')[0] || '';
                nameInput.value = source.replace(/[^A-Za-z0-9._-]+/g, '-');
            }
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
            submit.textContent = 'Installing…';

            // The upload is done; hand off with a normal post so the result arrives
            // as a flash message and the page re-renders with the new model.
            form.submit();
        } catch (error) {
            discardUpload(uploadId);
            submit.disabled = false;
            submit.textContent = 'Upload & add';
            summary.className = 'alert alert-danger mb-0 mt-3';
            summary.textContent = error.message;
            summary.classList.remove('d-none');
        }
    });
}

// ----------------------------------------------------------------- settings form

/**
 * Save settings is only offered when there is something to save.
 *
 * The baseline is whatever the page rendered with, so "dirty" means "differs from
 * what is stored", not "has been touched". Discard puts the form back rather than
 * reloading, because a reload would lose a flash message.
 */
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
            field.dispatchEvent(new Event('change', { bubbles: true }));
        });
    });

    form.addEventListener('submit', () => {
        save.disabled = true;
        save.textContent = 'Saving…';
    });

    refresh();
}

// ----------------------------------------------------------------- engine polling

function initEnginePolling() {
    if (!$('#engine-card')) return;

    let lastReachable = config.engineReachable;

    // Poll less aggressively when the service is offline: there is nothing useful to
    // show, and each probe pays a full connection-refused wait (~500ms). Snap back to
    // the fast interval the moment it comes up.
    const FAST_MS = 15_000;
    const SLOW_MS = 30_000;

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

            // Reload on a transition so the whole page - the model list, the dropdown,
            // the disabled states - matches reality rather than being patched piecemeal.
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

// ------------------------------------------------------------------------- modals

/**
 * Inline rename / delete buttons in the installed-models table.
 *
 * Each row carries a .model-rename-trigger and .model-delete-trigger button whose
 * data-key attribute holds the model's folder name. Disabled state and tooltip are
 * server-rendered (@disabled + title), so JS only has to wire the modal content on
 * show: drop the right key into the form action and the display elements.
 */
function initModelActions() {
    // Rename: read key from the trigger button, not a dropdown.
    const renameModal = document.getElementById('renameModal');
    renameModal?.addEventListener('show.bs.modal', (event) => {
        const trigger = event.relatedTarget;
        const key = trigger?.dataset.key ?? '';
        const form = $('#renameForm');
        form.action = url(config.urls.modelRename, key);
        $('#renameOldKey').textContent = key;
        form.querySelector('[name="new_name"]').value = key;
    });

    // Delete: read key + human label from the trigger button.
    const deleteModal = document.getElementById('deleteModal');
    deleteModal?.addEventListener('show.bs.modal', (event) => {
        const trigger = event.relatedTarget;
        const key   = trigger?.dataset.key   ?? '';
        const label = trigger?.dataset.label ?? key;
        $('#deleteForm').action = url(config.urls.modelDestroy, key);
        $('#deleteKey').textContent   = key;
        $('#deleteLabel').textContent = label;
    });
}

// ----------------------------------------------------------------------- bootstrap

document.addEventListener('DOMContentLoaded', () => {
    initModelUpload();
    initSettingsForm();
    initModelActions();
    initEnginePolling();
});
