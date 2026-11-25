{{-- Review & Export: add an empty column to the table to type into (wired by attachAddColumn in review-table.js). --}}
<div class="dropdown" data-review-add-column>
    <button type="button" class="btn btn-sm btn-outline-primary" id="reviewAddColumnBtn"
            data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
        <i class="icon-base bx bx-plus icon-sm me-1" aria-hidden="true"></i>Add column
    </button>
    <div class="dropdown-menu dropdown-menu-end p-3" style="min-width: 19rem;" aria-labelledby="reviewAddColumnBtn">
        <form class="d-flex gap-2" data-add-column-form>
            <input type="text" class="form-control form-control-sm" maxlength="120" required autocomplete="off"
                   placeholder="Column name" aria-label="New column name">
            <button type="submit" class="btn btn-sm btn-primary">Add</button>
        </form>
        <p class="form-text small mb-0 mt-2">
            An empty column to type into. It is in the downloads, not saved with the record.
        </p>
        <ul class="list-unstyled small mb-0 mt-2 d-grid gap-1" data-added-columns></ul>
        {{-- Columns removed from their header's ×: shown again from here. --}}
        <div class="d-none mt-3 pt-2 border-top" data-hidden-columns-wrap>
            <div class="small fw-semibold mb-1">Removed columns</div>
            <ul class="list-unstyled small mb-0 d-grid gap-1" data-hidden-columns></ul>
        </div>
    </div>
</div>
