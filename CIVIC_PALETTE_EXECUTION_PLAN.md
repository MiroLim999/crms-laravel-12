# CRMS Sneat UI & Brand System — Execution Plan

- Status: Ready for implementation / Active Reference
- Scope: Laravel/Blade, Bootstrap/Sneat, Tailwind, ApexCharts, OCR Workspace, Scanning, and Template-Builder
- Brand reference: `public/assets/img/crms-logo.png`

---

## 1. Guiding Principles & Design Objectives

1. **Retain Native Sneat System**:
   - Preserve the signature Sneat colors for primary actions, buttons, alerts, badges, and typography.
   - Maintain the light, clean, and modern public service civil registry feel without muddy or forced dark-mode overlays.
2. **Avoid AI-Slop & Unnecessary Borders**:
   - Strictly avoid arbitrary decorative side-borders (e.g. `border-left` colored stripes on cards or alerts).
   - Maintain consistent, subtle 1px neutral borders (`#d9dee3` / `#e4e6e8`) on standard Bootstrap/Sneat card surfaces.
3. **Legibility & Accessibility**:
   - Standard Sneat text hierarchy (`#566a7f` for body, `#435971` for headings, `#a1acb8` for muted hints).
   - High contrast on buttons and badges with standard Sneat light backgrounds (`bg-label-*`).
4. **Official CRMS Identity**:
   - Office of the City Civil Registrar — City of Maasin seal (`public/assets/img/crms-logo.png`) as the official brand anchor across sidebar navigation and guest authentication screens.

---

## 2. Palette & Color Token System

| Token | Value | Role / Usage |
|---|---:|---|
| **Primary** | `#0d6efd` | Primary buttons, active sidebar items, focus rings, link hover (avoiding purple) |
| **Secondary** | `#8592a3` | Secondary buttons, neutral actions, subtle icons |
| **Success** | `#71dd37` | Verified status, active accounts, completed scans |
| **Info** | `#03c3ec` | Document metadata, informational alerts, auxiliary stats |
| **Warning** | `#ffab00` | Pending approvals, temporary passwords, review warnings |
| **Danger** | `#ff3e1d` | Rejections, deactivated accounts, destructive actions |
| **Dark / Text Main** | `#435971` | Main headings and emphasized titles |
| **Body Text** | `#566a7f` | Standard body typography and table text |
| **Muted Text** | `#a1acb8` | Placeholders, timestamps, and secondary metadata |
| **Canvas / Background** | `#f5f5f9` | Application backdrop canvas |
| **Card / Surface** | `#ffffff` | Elevated cards, modals, and dropdowns |
| **Card Border** | `#d9dee3` | Standard neutral card boundary (no decorative side borders) |

---

## 3. Component Architecture & Rules

### 3.1 Sidebar & Navigation
- **Shell**: Clean white `#ffffff` background with standard Sneat light vertical divider.
- **Brand**: Displays `crms-logo.png` (36×36) with clear `CRMS` brand typography.
- **Active Navigation**: Standard Sneat active pill (`#0d6efd` blue background with white text and smooth border radius).
- **Icons**: Standard Boxicons (`bx-*`) inheriting thematic states.

### 3.2 Authentication & Guest Screens
- **Layout**: Centered card with clean spacing.
- **Header**: Official seal logo `crms-logo.png` + `CRMS` badge.
- **Heading**: Non-redundant action title (e.g., `Welcome back` or `Choose a new password`).
- **Primary CTA**: Full-width `#0d6efd` solid button.

### 3.3 Buttons, Alerts & Badges
- **Solid Buttons**: Standard Sneat flat buttons (`.btn-primary`, `.btn-secondary`, `.btn-success`, `.btn-danger`) with crisp hover transitions.
- **Soft Badges & Labels**: Sneat `.bg-label-*` utilities (e.g., `.bg-label-primary`, `.bg-label-success`) providing soft 16% tinted backgrounds with colored text.
- **Alerts**: Clean Bootstrap alerts without artificial left-accent borders.

### 3.4 OCR Performance & Workspace
- **Metric Tokens**:
  ```scss
  .ocr-workspace {
    --ocr-metric-character: #0d6efd; // Primary Character Accuracy
    --ocr-metric-word: #03c3ec;      // Info / Word Accuracy
    --ocr-metric-exact: #71dd37;     // Success / Exact Match
  }
  ```
- **Performance Radar Chart**: ApexCharts radar chart using Sneat primary gradient fill (`rgba(13, 110, 253, 0.25)`) and distinct metric marker points.
- **Field Marker & Template Builder**:
  - Unselected field bounds: Clean Sneat Primary translucent border (`rgba(13, 110, 253, 0.45)`).
  - Selected field bounds: Solid `#0d6efd` border with distinct 2px outer outline and corner drag handles.

---

## 4. File-by-File Implementation Strategy

1. **`resources/scss/app.scss` & `_crms.scss`**:
   - Keep CRMS-specific overrides thin and clean.
   - Set `$primary: #0d6efd;` in `_custom-variables/_bootstrap-extended.scss`.
2. **`resources/css/app.css`**:
   - Maintain Tailwind v4 theme tokens synchronized to Sneat:
     ```css
     @theme {
       --font-sans: 'Public Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
       --color-primary: #0d6efd;
       --color-body-bg: #f5f5f9;
       --radius-default: 0.375rem;
     }
     ```
3. **`resources/js/ocr-workspace.js` & `resources/js/dashboard-analytics.js`**:
   - Ensure charts (ApexCharts) consume the CSS custom properties or standard Sneat hex tokens directly.
4. **`resources/views/layouts/`**:
   - Sidebar: [sidebar.blade.php](file:///c:/xampp/htdocs/crms-laravel-12/resources/views/layouts/partials/sidebar.blade.php) referencing `crms-logo.png`.
   - Guest: [guest.blade.php](file:///c:/xampp/htdocs/crms-laravel-12/resources/views/layouts/guest.blade.php) with multi-resolution `favicon.ico`, `favicon-32.png`, and `apple-touch-icon.png`.

---

## 5. Verification & Quality Assurance

- [x] Full PHPUnit test suite execution: `php artisan test` (all 165 tests passing).
- [x] Asset compilation via Vite: `npm run build` succeeds without warnings.
- [x] Brand asset verification: `crms-logo.png`, `favicon.ico`, `favicon-32.png`, `apple-touch-icon.png` verified.
- [x] UI review: Clean Sneat aesthetic verified with no dark muddiness or artificial side borders.
