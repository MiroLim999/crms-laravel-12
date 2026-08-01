/**
 * CRMS front-end entry point.
 *
 * Load order matters and mirrors SNEAT: the theme config and helpers must run
 * before the menu and main scripts, because they set up the `window.templateCustomizer`
 * / `window.Helpers` globals those scripts read.
 */

// Bootstrap's JS (dropdowns, modals, toasts, tooltips, offcanvas).
import * as bootstrap from 'bootstrap';
window.bootstrap = bootstrap;

// SNEAT theme runtime, harvested from the template.
import './sneat/theme-config';
import './sneat/helpers';
import './sneat/menu';

// SNEAT's menu/main scripts expect PerfectScrollbar as a global. Pull it straight
// from the npm package rather than the template's window-assigning shim.
import PerfectScrollbar from 'perfect-scrollbar';

window.PerfectScrollbar = PerfectScrollbar;

import './sneat/main';
