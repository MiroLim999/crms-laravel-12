/**
 * Review & Export for a saved record (records/{record}/review): the record's
 * scan on the left (the record page's own scan card, zoom and locate), its
 * people as a table on the right. Save sends only the changed cells; the
 * server logs each old and new value.
 */
import { initRecordDetail } from './record-detail.js';
import {
    attachSplit,
    buildReviewModel,
    downloadCsv,
    downloadXlsx,
    exportFileName,
    ReviewTable,
} from './review-table.js';

const root = document.querySelector('[data-record-review]');
const source = document.getElementById('recordReviewData');

if (root instanceof HTMLElement && source) {
    const data = JSON.parse(source.textContent);
    const original = new Map(Object.entries(data.values).map(([id, value]) => [Number(id), String(value ?? '')]));
    const current = new Map(original);
    const located = new Set((data.located ?? []).map(Number));
    // Values typed where the record has no field: "person-5|1|sex" -> "M".
    const additions = new Map();
    const element = (id) => document.getElementById(id);
    let table = null;

    const model = buildReviewModel(data);
    const extraKey = (cell) => `${cell.row.id}|${cell.column.key}`;

    const valueOf = (cell) => (cell.refs.length === 0
        ? (cell.column.detail ? '' : additions.get(extraKey(cell)) ?? '')
        : cell.refs.map((ref) => current.get(Number(ref)) ?? '').filter((value) => value.trim() !== '').join(' '));

    const changedIds = () => [...current].filter(([id, value]) => value.trim() !== (original.get(id) ?? '').trim()).map(([id]) => id);
    const dirty = () => changedIds().length > 0 || additions.size > 0;

    function showState() {
        const changes = changedIds().length + additions.size;
        element('reviewSaveBtn').disabled = changes === 0;
        element('reviewChangeCount').textContent = changes === 0
            ? 'No changes yet'
            : `${changes} ${changes === 1 ? 'change' : 'changes'} not saved`;
    }

    const scan = initRecordDetail(root, {
        // A box clicked on the scan: its cell in the table.
        onActivate: (fieldId) => table?.selectRef(Number(fieldId)),
    });

    table = new ReviewTable({
        container: element('reviewTable'),
        valueOf,
        onChange: (cell, value) => {
            const text = value.trim();
            if (cell.refs.length === 0) {
                if (text === '') additions.delete(extraKey(cell));
                else additions.set(extraKey(cell), text);
            } else {
                // Two lines of one cell: the whole value goes in the first.
                cell.refs.forEach((ref, position) => current.set(Number(ref), position === 0 ? text : ''));
            }
            showState();
        },
        onLocate: (cell) => {
            const ref = cell.refs.find((candidate) => located.has(Number(candidate)));
            if (ref !== undefined) scan.activateField(String(ref));
        },
    });
    // Ctrl+Z / Ctrl+Y after a click on the scan or elsewhere on the page.
    table.listenForUndoKeys();
    table.render(model);
    if (model.rows.length > 0 && model.columns.length > 0) table.select(0, 0, { locate: false });
    showState();

    attachSplit({ split: root.querySelector('[data-review-split]'), splitter: element('reviewSplitter') });

    element('reviewExportCsv').addEventListener('click', () => {
        table.commit();
        downloadCsv(table.exportTable(), exportFileName(data.fileName, new Date().toISOString().slice(0, 10)));
    });
    element('reviewExportXlsx').addEventListener('click', async () => {
        table.commit();
        // The spinner shows on the Download button (the menu closes on the click).
        const button = element('reviewDownloadBtn');
        const spinner = button.querySelector('.spinner-border');
        button.disabled = true;
        spinner?.classList.remove('d-none');
        try {
            await downloadXlsx(table.exportTable(), exportFileName(data.fileName, new Date().toISOString().slice(0, 10)));
        } catch (error) {
            console.error('Excel export failed:', error);
            showError('The Excel file could not be made. Try CSV instead.');
        } finally {
            button.disabled = false;
            spinner?.classList.add('d-none');
        }
    });

    function showError(message) {
        element('reviewErrorMessage').textContent = message;
        element('reviewError').classList.remove('d-none');
    }

    let saving = false;
    element('reviewSaveBtn').addEventListener('click', async () => {
        table.commit();
        if (saving || !dirty()) return;
        saving = true;
        const button = element('reviewSaveBtn');
        const idle = button.innerHTML;
        button.disabled = true;
        button.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Saving...';
        element('reviewError').classList.add('d-none');

        const rows = new Map(model.rows.map((row) => [row.id, row]));
        const columns = new Map(model.columns.map((column) => [column.key, column]));
        const people = new Map((data.people ?? []).map((person) => [person.id, person.person]));
        const body = {
            changes: changedIds().map((id) => ({ id, value: current.get(id) ?? '' })),
            additions: [...additions].map(([key, value]) => {
                const [rowId, ...rest] = key.split('|');
                const column = columns.get(rest.join('|'));
                return {
                    page_no: data.pages[column.side]?.number ?? 1,
                    person_group: people.get(rows.get(rowId)?.id) ?? 1,
                    column: column.name,
                    value,
                };
            }),
        };

        try {
            const response = await fetch(data.updateUrl, {
                method: 'PATCH',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
                },
                credentials: 'same-origin',
                body: JSON.stringify(body),
            });
            const payload = (response.headers.get('content-type') || '').includes('application/json') ? await response.json() : null;
            if (!response.ok) {
                const first = payload?.errors ? Object.values(payload.errors).flat()[0] : null;
                throw new Error(first || payload?.message || 'The changes could not be saved. Nothing was changed.');
            }
            saving = 'done';
            window.location.assign(payload.redirect);
        } catch (error) {
            showError(error instanceof TypeError ? 'The server could not be reached. Check your connection and try again.' : error.message);
            button.innerHTML = idle;
            button.disabled = false;
            saving = false;
        }
    });

    // Leaving with changes not saved asks first.
    window.addEventListener('beforeunload', (event) => {
        if (saving === 'done' || !dirty()) return;
        event.preventDefault();
        event.returnValue = '';
    });
}
