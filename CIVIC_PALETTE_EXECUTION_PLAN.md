# CRMS Civic Registry Palette Migration — Execution Plan

- Status: ready for implementation
- Scope: the complete Laravel/Blade, Bootstrap/Sneat, Tailwind, ApexCharts, OCR, scanning, and template-builder interface
- Brand reference: `public/assets/img/logo.png`

## Instruction to the implementer

Execute this document in order. Treat the palette and role assignments below as locked decisions. Preserve application behavior, authorization, markup contracts, document rendering, responsive layouts, and accessibility fallbacks. This is a visual-system migration, not a feature redesign.

Do not stop after changing the global primary color. The migration is complete only when the application-owned SCSS, Tailwind tokens, JavaScript charts, inline Blade styles, semantic states, and all role-specific pages have been updated and verified.

## Desired outcome

Replace the inherited purple/cyan/lime Sneat appearance with a restrained civic identity based on the Office of the City Civil Registrar seal:

- Registry Ink establishes the institutional navigation shell.
- Civic Blue identifies actions, links, focus, and selection.
- Seal Gold is a sparse brand accent, primarily against Registry Ink.
- White and cool paper-gray surfaces keep records and data easy to scan.
- Green, amber, and red remain semantic rather than decorative.
- Dashboard cards use consistent surfaces and hierarchy instead of a different pastel identity for every card.
- Decorative gradients, colored glows, and arbitrary rainbow treatments are removed.

The intended character is modern public-service software: official, quiet, legible, and dependable.

## Non-goals

- Do not redraw or recolor the logo.
- Do not change Public Sans, page structure, copy, icons, routes, permissions, or business logic.
- Do not add dark mode. Both layouts explicitly use `data-bs-theme="light"`; the ink sidebar is the only dark surface in this scope.
- Do not install a new UI framework or browser-test dependency as part of this migration.
- Do not edit harvested Sneat vendor files under `resources/scss/_bootstrap-extended`, `resources/scss/_components`, or `resources/js/sneat`.
- Do not hand-edit generated files under `public/build`; regenerate them with Vite.

## Locked palette

| Token | Value | Role |
|---|---:|---|
| Registry Ink | `#0B2438` | Sidebar and darkest institutional surface |
| Civic Blue | `#155A8A` | Primary actions, links, selection, and focus |
| Civic Blue Hover | `#0D466F` | Primary hover and pressed states |
| Seal Gold | `#D39A1A` | Sparse brand accent on dark surfaces |
| Accessible Gold | `#9F6900` | Gold lines, chart marks, or text on light surfaces |
| Main Text | `#17212B` | Headings and body copy |
| Muted Text | `#52616F` | Metadata and secondary copy |
| Paper | `#F4F6F8` | Application canvas |
| Surface | `#FFFFFF` | Cards, tables, dropdowns, and modals |
| Passive Border | `#D7DDE2` | Dividers and non-interactive boundaries |
| Control Border | `#8896A2` | Inputs and controls whose boundary must be perceivable |
| Information | `#3B7187` | Informational states and a restrained chart color |
| Success | `#2E6F4E` | Verified, complete, active, and successful states |
| Warning Text | `#8A5D00` | Pending, caution, and warning states |
| Danger | `#B42318` | Errors, rejected states, and destructive actions |
| Primary Soft | `#E8F1F7` | Selected rows and primary label backgrounds |
| Success Soft | `#E8F3ED` | Success label backgrounds |
| Warning Soft | `#FFF4D6` | Warning label backgrounds |
| Danger Soft | `#FBEAE8` | Error label backgrounds |
| Chart Umber | `#7A5D3B` | Categorical charts only |
| Chart Deep Steel | `#2F4858` | Categorical charts only |

### Accessibility rules for these colors

- White on Civic Blue is `7.34:1`.
- White on Registry Ink is `15.86:1`.
- Muted Text on white is `6.37:1`.
- White on Success is `6.00:1`.
- Warning Text on white is `5.76:1`.
- White on Danger is `6.57:1`.
- Main Text on Seal Gold is `6.52:1`.
- Seal Gold on white is only `2.50:1`. Never use it as normal text, a focus ring, or the sole selected indicator on a light surface. Use Civic Blue there.
- Seal Gold on Registry Ink is `6.35:1`, so the gold sidebar indicator is valid.
- Accessible Gold on white is `4.67:1`; use it when the gold idea must appear on a light chart or surface.
- Every locked categorical chart color has at least `4.5:1` contrast against white.
- Passive Border on white is only `1.37:1`; use it for separators only. Use Control Border when a visible boundary communicates that an element is interactive.
- Never use white text on Seal Gold. If a filled gold element is unavoidable, use Main Text.
- Color must not be the only signal for status, differences, chart meaning, focus, or selection. Preserve labels, icons, legends, values, and the dashboard's chart-data table.

## Usage proportions

- 70–80% Paper and Surface
- 15–20% Registry Ink, Main Text, and supporting neutrals
- 5–8% Civic Blue
- 2–5% Seal Gold
- Semantic green, warning, and red only when the represented state requires them

## Source-of-truth architecture

Use the existing customization hooks and keep responsibilities explicit:

1. `resources/scss/_custom-variables/_bootstrap-extended.scss` is authoritative for Bootstrap/Sneat semantic colors, neutrals, contrast behavior, and form borders.
2. `resources/scss/_custom-variables/_components.scss` owns generic menu variables.
3. A small `:root` token block at the top of `resources/scss/_crms.scss` owns CRMS-only concepts that Bootstrap does not provide: Seal Gold, exact soft surfaces, sidebar text, and the ordered chart palette.
4. `resources/css/app.css` mirrors the locked values for Tailwind v4 utilities.
5. JavaScript reads computed CSS custom properties. Do not maintain an independent hard-coded chart palette in JavaScript.

Do not override only `:root --bs-*` after compilation. Bootstrap and Sneat compile colors into component-level button, badge, alert, form, and utility variables; the Sass variables must be set before the vendor defaults are imported.

## Phase 0 — Establish a baseline

Before editing application source:

- Run `git status --short` and preserve any unrelated user changes.
- Run the five repository checks listed under Verification so pre-existing failures are distinguished from migration regressions.
- Capture reference screenshots of login, both dashboard variants, expanded/collapsed sidebar, document workflow, template builder, OCR performance, a populated table/form, and the audit diff.
- Record at least one success, warning, danger, selected, disabled, and keyboard-focus state.
- Confirm the test connection uses `crms_test` before running PHPUnit.

## Phase 1 — Establish the global theme

### 1.1 Bootstrap/Sneat overrides

Populate `resources/scss/_custom-variables/_bootstrap-extended.scss`. The final implementation should express the following values through variables, not post-compile selector patches:

```scss
// Civic Registry palette — authoritative Bootstrap/Sneat source.
$white: #fff;
$black: #17212b;
$pure-black: #0b2438;
$paper-bg: #fff;

$gray-25: #fbfcfd;
$gray-60: #f4f6f8;
$gray-80: #eef1f3;
$gray-100: #e9edf0;
$gray-200: #d7dde2;
$gray-300: #b8c1c9;
$gray-400: #87939e;
$gray-500: #6b7884;
$gray-600: #52616f;
$gray-700: #3e4c58;
$gray-800: #283642;
$gray-900: #17212b;

$primary: #155a8a;
$secondary: #52616f;
$success: #2e6f4e;
$info: #3b7187;
$warning: #8a5d00;
$danger: #b42318;
$light: #d7dde2;
$dark: #0b2438;

$body-bg: #f4f6f8;
$body-color: #17212b;
$body-secondary-color: #52616f;
$headings-color: #17212b;
$border-color: #d7dde2;
$input-border-color: #8896a2;
$input-disabled-border-color: #d7dde2;
$input-focus-border-color: $primary;

$min-contrast-ratio: 4.5;
$color-contrast-dark: #17212b;
```

Confirm the Sass compiler accepts every override in this pre-default hook. If a variable is not part of the harvested theme, keep the equivalent behavior in `_crms.scss` rather than editing vendor code.

Expected global effects:

- `.btn-primary`, links, pagination, form focus, active controls, and `.text-primary` become Civic Blue.
- success, info, warning, and danger utilities use the restrained semantic colors.
- cards, tables, dropdowns, and modals remain white.
- the body canvas becomes Paper.
- body, heading, secondary text, borders, and form boundaries use the cool neutral scale.
- warning buttons and labels use readable deep amber; Seal Gold remains a separate brand accent.

### 1.2 Generic menu variables

Populate `resources/scss/_custom-variables/_components.scss` so generic menu output agrees with the ink sidebar:

- menu background: Registry Ink
- default menu text: `#D7DDE2`
- menu hover text: white
- menu hover background: a restrained white tint over Registry Ink
- menu active background: Civic Blue
- menu active text: white
- menu divider: a low-opacity white border
- menu shadow: none

Do not consider this sufficient by itself. The later `#layout-menu` block in `_crms.scss` currently forces a white background with `!important` and overrides the generated menu styles; Phase 2 must update it.

### 1.3 CRMS-only CSS tokens

Add a compact token block near the top of `resources/scss/_crms.scss`:

```scss
:root {
  --crms-ink: #0b2438;
  --crms-ink-rgb: 11, 36, 56;
  --crms-primary-hover: #0d466f;
  --crms-accent: #d39a1a;
  --crms-accent-rgb: 211, 154, 26;
  --crms-accent-on-light: #9f6900;
  --crms-control-border: #8896a2;
  --crms-primary-soft: #e8f1f7;
  --crms-success-soft: #e8f3ed;
  --crms-warning-soft: #fff4d6;
  --crms-danger-soft: #fbeae8;
  --crms-sidebar-text: #d7dde2;
  --crms-sidebar-muted: #a8b6c1;
  --crms-chart-1: #155a8a;
  --crms-chart-2: #9f6900;
  --crms-chart-3: #3b7187;
  --crms-chart-4: #52616f;
  --crms-chart-5: #7a5d3b;
  --crms-chart-6: #2f4858;
}
```

Use Bootstrap variables such as `--bs-primary`, `--bs-success`, and their `-rgb` companions for semantic UI wherever possible. The `--crms-*` variables are for brand concepts or exact surfaces not represented by Bootstrap.

### 1.4 Tailwind v4 mirror

Update `resources/css/app.css` so Tailwind utilities no longer emit the Sneat purple or old canvas:

```css
@theme {
    --font-sans: 'Public Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto,
        'Helvetica Neue', Arial, sans-serif;
    --color-primary: #155a8a;
    --color-primary-hover: #0d466f;
    --color-ink: #0b2438;
    --color-accent: #d39a1a;
    --color-accent-on-light: #9f6900;
    --color-body-bg: #f4f6f8;
    --color-surface: #ffffff;
    --color-text: #17212b;
    --color-muted: #52616f;
    --color-border: #d7dde2;
    --color-control-border: #8896a2;
    --color-success: #2e6f4e;
    --color-warning: #8a5d00;
    --color-danger: #b42318;
    --radius-default: 0.375rem;
}
```

The SCSS variables remain authoritative; this Tailwind block is an explicit mirror because the two Vite style entries compile independently.

## Phase 2 — Recompose the application shell

Update the `#layout-menu` section in `resources/scss/_crms.scss`.

Required sidebar states:

- Background: Registry Ink, with no colored shadow.
- Brand name: white; keep the existing logo unchanged.
- Divider: low-opacity white.
- Section label: Sidebar Muted, uppercase treatment retained.
- Default link and icon: Sidebar Text.
- Hover: white text and icon on a subtle white-tint background.
- Active/current link: white on Civic Blue with a 3px inset Seal Gold indicator. Use an inset box-shadow or pseudo-element so adding the indicator does not shift content.
- Keyboard focus: a 2px Seal Gold outline against the ink surface.
- Collapsed rail: preserve the existing icon centerline and tooltips.
- Mobile/off-canvas state: preserve overlay, scrolling, close behavior, and readable active/focus states.

The existing `.sidebar-nav__item.active::before { display: none; }` and hard-coded white `#layout-menu` rules must be deliberately replaced or removed. Do not fight them with another higher-specificity patch.

Set the sidebar's `--bs-menu-bg` and `--bs-menu-bg-rgb` values to Registry Ink as well; Sneat's `.menu-inner-shadow` consumes those variables and will otherwise retain a white scroll fade. If the logo's black outer ring merges into the ink background at 36px, add a small white circular plate and restrained padding behind `.sidebar-brand__mark`; do this only if visual QA confirms the problem.

Keep the navbar, footer, cards, menus, and modal surfaces white. The shell should have one dark anchor—the sidebar—not a dark header plus dark sidebar.

Verify the fixed `.global-sidebar-toggle` in expanded, collapsed, and mobile states. It should remain a white control with an ink icon, a visible Control Border, and a Civic Blue focus ring.

## Phase 3 — Replace application-owned legacy literals

Audit these files completely:

- `resources/scss/_crms.scss`
- `resources/scss/pages/ocr-workspace.scss`
- `resources/css/app.css`
- `resources/js/dashboard-analytics.js`
- `resources/js/ocr-workspace.js`
- `resources/js/template-builder.js`
- `resources/views/audit/index.blade.php`
- any other application-owned file returned by the legacy-palette gate in Verification

Use this mapping for translucent state colors:

| Legacy literal family | Replacement |
|---|---|
| `rgba(105, 108, 255, A)` | `rgba(var(--bs-primary-rgb), A)` |
| `rgba(3, 195, 236, A)` | `rgba(var(--bs-info-rgb), A)` |
| `rgba(40, 199, 111, A)` or `rgba(113, 221, 55, A)` | `rgba(var(--bs-success-rgb), A)` |
| `rgba(255, 171, 0, A)` | use `var(--crms-warning-soft)`, `rgba(var(--bs-warning-rgb), A)`, or `rgba(var(--crms-accent-rgb), A)` according to meaning |
| `rgba(255, 62, 29, A)` | `rgba(var(--bs-danger-rgb), A)` |
| `rgba(67, 89, 113, A)` or `rgba(34, 48, 62, A)` | `rgba(var(--crms-ink-rgb), A)` |
| `rgba(245, 245, 249, A)` | `rgba(var(--bs-body-bg-rgb), A)` |
| old soft-state hex values | the matching exact `--crms-*-soft` token |

Migration rules:

- Replace hard-coded primary borders, backgrounds, rings, and shadows with semantic variables.
- Apply the exact `#0D466F` hover/pressed token explicitly to primary buttons and links where Sneat's generated color mix does not produce it.
- Keep shadows neutral and shallow. Do not tint card shadows blue.
- Replace decorative primary radial/linear washes with a flat Surface or Primary Soft treatment.
- Preserve gradients that are functional textures or masks, such as the document-canvas checkerboard and layout fade masks.
- Preserve literal white used to render a scanned document page or canvas pixel. Do not blindly replace `#fff`/`#ffffff` in `field-marker.js`, `template-builder.js`, or the scan workspace.
- Replace `#d9dee3` used for the template-builder canvas border with the passive or control-border token according to whether it communicates interaction.
- Do not convert success, warning, and danger states to blue. Semantic meaning survives the brand migration.
- If a legacy-colored selector is demonstrably unused, remove it after checking all Blade and JavaScript references; otherwise retoken it. An unused rule is not a reason to leave a legacy literal in application-owned source.

## Phase 4 — Dashboard treatment

Update `resources/views/dashboard.blade.php`, the dashboard portion of `_crms.scss`, and `resources/js/dashboard-analytics.js` together.

### KPI cards

- Keep every KPI card on the same white Surface with the same passive border and neutral shadow.
- For the four administrative headline KPIs, use `bg-label-primary` for the icons rather than assigning unrelated primary/info/warning/success identities.
- Time-range badges such as “All time,” “12 months,” and “Current” are metadata, not statuses; use `bg-label-secondary`.
- Retain success/warning/danger only where the value is genuinely semantic, such as completed work, pending requests, positive/negative trend, or a problem state.
- Do not add gradients, decorative blobs, or colored card backgrounds.

### Charts

- Replace the hard-coded `palette` object with a computed-style reader for `--crms-chart-1` through `--crms-chart-6`, plus Bootstrap text, border, surface, and font variables.
- Use this non-semantic categorical order: Civic Blue, Accessible Gold, Information, Muted Text, Chart Umber, Chart Deep Steel.
- For status data, ignore categorical order and use the correct semantic color.
- Replace the volume chart's gradient fill with a restrained solid translucent fill.
- Keep chart legends, values, ARIA labels, and the “View chart data” table.
- Update ranked-list markers to consume the same six chart variables so the list and donut chart stay synchronized.
- Use Accessible Gold for the OCR correction comparison chart series/dot on white. “Human correction” is a measurement, not a warning state.
- Preserve empty chart states and confirm that no JavaScript fallback can restore the old palette.

## Phase 5 — OCR, scanning, validation, and template workspaces

### OCR performance

In `resources/scss/pages/ocr-workspace.scss`, map the metric tokens to:

```scss
.ocr-workspace {
  --ocr-metric-character: var(--bs-primary);
  --ocr-metric-word: var(--crms-accent-on-light);
  --ocr-metric-exact: var(--bs-success);
}
```

In `resources/js/ocr-workspace.js`:

- read the computed CSS values;
- change every fallback to the new palette;
- replace the three-color radar gradient with a single restrained translucent Civic Blue fill;
- retain the individually colored metric markers and labels;
- replace old neutral polygon fills with an ink-based neutral token;
- preserve metric values, labels, empty states, and ARIA output.

### Field marker and template builder

- Default field boxes use a restrained Civic Blue outline and Primary Soft.
- Selected field boxes use a stronger Civic Blue border plus a crisp outer ring, label, or icon. Do not reuse Information for selection.
- Verified fields use Success and Success Soft.
- Low-confidence/review-needed fields use Warning Text and Warning Soft.
- Invalid fields and destructive actions use Danger and Danger Soft.
- Selection, verification, and invalid states must retain border style, label, icon, or copy differences in addition to color.
- Preserve the existing functional distinction between a completed person/record row (gold) and an exact verified field (Success). If the scan workspace still describes that state as “Orange,” update the user-facing copy to “Gold.”
- Toolbars and side panels remain white; document viewports remain neutral.
- Preserve canvas paper white and checkerboard/document textures.
- Replace colored glows with crisp borders, inset indicators, or accessible focus outlines.
- In `resources/views/templates/index.blade.php`, replace `btn-info` used for ordinary expand/edit actions with primary, outline-primary, or neutral controls. Reserve Information for actual informational state.

## Phase 6 — Audit, tables, forms, badges, and auth pages

### Audit diff

Remove inline color declarations from `resources/views/audit/index.blade.php` and give them named classes in `_crms.scss`:

- diff container: Primary Soft with a passive border;
- “Before”: Danger;
- “After”: Success;
- row dividers: ink-based neutral at low opacity.

Keep the words “Before” and “After”; color is supplementary.

### Forms and controls

- Inputs/selects: white Surface and Control Border.
- Focus: Civic Blue border plus a visible, non-glowing focus ring.
- Disabled: Paper/passive border with readable Muted Text.
- Validation: Danger text, border, and icon/message.
- Placeholder text must remain distinguishable from entered text.
- Check checkboxes, radios, switches, range controls, file inputs, search/filter forms, and modal forms—not only text inputs.

### Badges and status classes

- Primary: identity, role, selection, or informational brand context.
- Secondary: neutral metadata, draft, base, or time range.
- Success: active, published, verified, approved, or completed.
- Warning: pending, awaiting action, low confidence, or caution.
- Danger: inactive only when it represents a problem, rejected, invalid, failed, or destructive.
- Information: use sparingly for non-semantic supporting information.

Review every existing `bg-label-*`, `text-*`, and `btn-*` usage for meaning. Global retheming handles most occurrences; change Blade classes only when their current semantic assignment is wrong.

Explicitly verify `bg-label-warning`, `.alert-warning`, `.text-warning`, `table-warning`, and any solid warning button. They must use readable Warning Text and Warning Soft rather than Seal Gold as text on white.

### Authentication

The login and forced-password-change pages should inherit Paper, Surface, Civic Blue, the neutral scale, and form borders from the global theme. Keep the current centered card, Public Sans, logo, and markup contract. Verify validation, password visibility, keyboard focus, autofill, and disabled/loading states.

## Phase 7 — Remove legacy palette paths

No application-owned runtime path may retain these old visible colors:

- `#696cff`
- `#03c3ec`
- `#71dd37`
- `#ffab00`
- `#ff3e1d`
- `#8e5be8`
- `#8592a3`
- `#28c76f`
- `#ea5455`
- `#2aa876`
- `#00a7c4`
- `#f5f5f9`
- `#d9dee3`

Old literals may remain inside untouched harvested vendor defaults because the custom-variable layer overrides them. They may not remain in `_crms.scss`, app page styles, application JavaScript, Tailwind tokens, or Blade inline styles.

Add `tests/JavaScript/palette-coverage.test.js` so `npm.cmd run test:js` fails if legacy literals return to application-owned source paths. The test must explicitly exclude harvested vendor files and generated `public/build` output.

## Verification and acceptance

Run from the repository root in PowerShell. `npm.cmd` is intentional because it avoids PowerShell execution-policy conflicts with `npm.ps1`.

```powershell
# One-time test database setup only. The test suite recreates its tables.
mysql -e "CREATE DATABASE IF NOT EXISTS crms_test"

# Required for every implementation.
git diff --check
npm.cmd run test:js
npm.cmd run check:icons
npm.cmd run build
php artisan test
```

`phpunit.xml` forces `DB_DATABASE=crms_test`, and the test base class rejects a database name that does not end in `_test`. Never point the test command at development or production data.

Current pre-migration baselines:

- JavaScript: 15 tests passing
- Icon subset: 59 icons passing
- PHPUnit: 165 data-expanded cases passing

Counts may increase but must not fall. `npm.cmd run build` writes ignored generated output under `public/build`.

### Legacy-palette gate

Run this after migrating application-owned sources:

```powershell
$legacyPalette = '(?i)#(?:696cff|03c3ec|71dd37|ffab00|ff3e1d|8e5be8|8592a3|28c76f|ea5455|2aa876|00a7c4|f5f5f9|d9dee3|e9ebf0|31812d|187846|b77900)\b|rgba?\(\s*(?:105\s*,\s*108\s*,\s*255|3\s*,\s*195\s*,\s*236|113\s*,\s*221\s*,\s*55|255\s*,\s*171\s*,\s*0|255\s*,\s*62\s*,\s*29|142\s*,\s*91\s*,\s*232|40\s*,\s*199\s*,\s*111|67\s*,\s*89\s*,\s*113|34\s*,\s*48\s*,\s*62|245\s*,\s*245\s*,\s*249)'
$palettePaths = @(
  'resources/css',
  'resources/js',
  'resources/scss/_crms.scss',
  'resources/scss/_custom-styles.scss',
  'resources/scss/_custom-variables',
  'resources/scss/pages',
  'resources/views'
)
$legacyHits = & rg -n $legacyPalette @palettePaths
if ($LASTEXITCODE -eq 0) { $legacyHits; throw 'Legacy palette literals remain in application-owned files.' }
if ($LASTEXITCODE -gt 1) { throw 'The palette scan itself failed.' }
```

The readable Warning Text token `#8A5D00` is intentionally not forbidden.

## Manual visual matrix

Use `php artisan serve --host=127.0.0.1 --port=8000` after a production build, or run `npm.cmd run dev` in a second terminal while implementing. Use only disposable local data when seeding demo accounts.

Review these pages and workflows:

- Guest: login, invalid login, forced password change.
- Every role: expanded/collapsed/mobile sidebar, navbar dropdown, settings, alerts, empty states, and dashboard.
- Staff: document picker; upload; field marker; OCR progress; recognition; verification; submission; records; record detail; change-request create/list/detail.
- Admin: populated and empty analytics; reports; users; audit log and expanded diff; change-request moderation.
- Super Admin: users; template index; template builder; template/document-type modals; OCR offline/online, upload, model list, settings, and performance states.

Test each relevant component in these states:

- default
- hover
- keyboard focus
- active/current
- selected
- disabled
- loading/progress
- empty
- success
- warning/pending
- validation error
- destructive/confirmation
- modal, dropdown, tooltip, and off-canvas

Review at minimum:

- `360 × 800`
- `768 × 1024`
- `1366 × 768`
- `1920 × 1080`
- 200% browser zoom

### WCAG 2.2 AA release gate

- Normal text contrast is at least `4.5:1`; large text is at least `3:1`.
- Meaningful icons, input boundaries, selection outlines, and focus indicators are at least `3:1` against adjacent colors.
- Focus is visible on white surfaces and the ink sidebar, is not clipped, and survives sidebar collapse, dropdowns, modals, field-marker tools, and template-builder tools.
- All pages are usable by keyboard without a mouse.
- Status and chart meaning remain understandable in grayscale and Windows High Contrast/forced-colors mode.
- At 200% zoom and 320–360 CSS pixels, no content or control is lost. Canvas workspaces may scroll, but their tools and primary actions remain operable.
- Run a browser DevTools/Lighthouse accessibility pass. The repository has no automated browser accessibility runner, so manual keyboard and contrast checks are release-blocking.

## Definition of done

The migration is complete only when all of the following are true:

- All five repository verification commands exit successfully.
- The legacy-palette gate returns no application-owned hits.
- The sidebar is ink, its active item has a restrained gold indicator, and all collapse/mobile states work.
- Global Bootstrap/Sneat, Tailwind, dashboard chart, OCR chart, and custom workspace colors agree.
- No decorative purple, cyan, lime, colored card shadow, or unnecessary gradient remains visible.
- Seal Gold is sparse and never used as normal text on white.
- Semantic green, warning, and red are used only for their meanings.
- Inputs, focus rings, selected states, and destructive actions are clearly perceivable and keyboard accessible.
- Dashboard and OCR data remain understandable without relying on color alone.
- Every role/page/state in the manual matrix is signed off.
- No behavior, authorization, responsive layout, canvas rendering, or document workflow has regressed.

## Release and rollback

This is an asset/source-only change with no database migration.

- Release it as one dedicated palette migration change after all gates pass.
- Build production Vite assets during deployment; do not commit or edit `public/build` unless the deployment process explicitly requires built assets.
- If a visual or functional regression is found, deploy the previous source revision and rebuild assets. No database rollback is needed.
