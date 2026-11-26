{{-- Review & Export: columns removed from their header's ×, to show again (wired by attachAddColumn in review-table.js). Shown only once one is removed. --}}
<div class="dropdown d-none" data-review-add-column>
    <button type="button" class="btn btn-sm btn-outline-primary" id="reviewAddColumnBtn"
            data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
        <i class="icon-base bx bx-hide icon-sm me-1" aria-hidden="true"></i>Removed columns
    </button>
    <div class="dropdown-menu dropdown-menu-end p-3" style="min-width: 17rem;" aria-labelledby="reviewAddColumnBtn">
        <div data-hidden-columns-wrap>
            <ul class="list-unstyled small mb-0 d-grid gap-1" data-hidden-columns></ul>
        </div>
    </div>
</div>
