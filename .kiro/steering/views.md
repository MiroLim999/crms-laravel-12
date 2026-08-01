# View layer conventions

Rules for anything under `resources/views/`. These exist because each one was either
decided deliberately or learned from a bug.

## File naming: folder is the noun, file is the action

| File | Means | Controller method |
|------|-------|-------------------|
| `index.blade.php` | the list | `index()` |
| `show.blade.php` | one item | `show()` |
| `create.blade.php` | form for a new one | `create()` |
| `edit.blade.php` | form for an existing one | `edit()` |

`records/show.blade.php` reads as "show a record". Follow this so the location of a
view is guessable without searching.

## One vocabulary per feature

**Routes, controller, and view folder must use the same word.** Three names for one
concept is the most expensive small mistake in this codebase.

The existing violation, kept only because renaming touches many references:

```
routes:      documents.create, documents.workspace
controller:  DocumentScanController
views:       scan/
```

Do not add new mismatches. When building a feature, pick the noun once — preferably
the one already in the route name, since that is what appears in URLs.

## Partial or component?

Both exist and they are not interchangeable.

| | Partial | Component |
|---|---|---|
| Location | `layouts/partials/` or `<feature>/partials/` | `components/` |
| Used via | `@include('layouts.partials.sidebar')` | `<x-card title="...">` |
| Variables | inherits the parent's scope | only what is passed in |
| For | one fixed region of a page | reusable building block |

Decision rule: **if it takes inputs and could appear anywhere, make it a component.
If it is a fixed slice of one page or the shell, make it a partial.**

Existing components — reuse these rather than hand-rolling markup:
`<x-card>`, `<x-page-header>`, `<x-empty-state>`, `<x-alerts>`.

A partial that grows past roughly 100 lines inside a feature folder is fine
(`ocr/partials/modals.blade.php`); splitting it out is the right call.

## Layouts

- Authenticated pages: `@extends('layouts.app')`
- Sign-in and forced password change: `@extends('layouts.guest')`

Every page sets `@section('title', ...)` and puts body markup in `@section('content')`.

`layouts/guest.blade.php` depends on SNEAT's `authentication-wrapper` /
`authentication-basic` / `authentication-inner` nesting. Renaming those classes
flattens the card to full width, because `pages/page-auth.scss` targets them.

## Deliberate deviations — do not "fix" these

- **`templates/edit.blade.php` serves both create and edit.** `create()` passes
  `$template = null` and the view branches. There is intentionally no
  `templates/create.blade.php`; one form beats two near-identical files.
- **`settings/edit.blade.php` has no `index`.** Account settings is a singleton
  resource — there is only ever the current user's. A list would be meaningless.
- **`analytics/index` and `reports/index` are not lists.** They are single-purpose
  pages. `index` is a mild stretch of the convention, accepted for consistency.
- **`dashboard.blade.php` sits at the views root**, not in a folder. It is the one
  standalone page.

## Front-end gotchas that have already cost time

**Bootstrap collapse does not work inside `<table>`.** It animates by measuring
height, and tables force a layout pass mid-animation, so Bootstrap measures 0 and
snaps the panel shut instantly. For expandable table rows, animate `max-height`
from `0` to `scrollHeight` with plain JS instead. See `audit/index.blade.php`.

**Any JS or CSS file referenced by `Vite::asset()` or `@vite()` must be listed as
an input in `vite.config.js`.** If it is not, the page throws
`Unable to locate file in Vite manifest` — a 500, not a silent failure. This caught
`field-marker.js` and `pages/page-auth.scss`.

**Page-specific assets get their own Vite entry** and are loaded in that page's
`@push('scripts')` or the layout's `@vite([...])`, never bundled into `app.js`.
Current examples: `field-marker.js` (scan pages), `apexcharts.js` (analytics),
`pages/page-auth.scss` (auth pages).

**New icons need the subset regenerated.** `iconify.css` is trimmed to only the
icons in use. Add an icon, then run `npm run subset-icons` and rebuild, or it
renders as a blank box.

## Tables

- Use `table-hover` only when rows are genuinely clickable. It highlights every
  `<tr>`, including expand rows, which looks like a bug.
- Always provide an `<x-empty-state>` branch for an empty result set.
- Paginated lists call `{{ $items->links() }}` and the controller uses
  `->withQueryString()` so filters survive page changes.
