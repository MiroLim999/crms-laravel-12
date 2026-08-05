/**
 * CRMS front-end entry point.
 *
 * Load order matters: browser globals and Helpers must exist before the theme
 * config, menu, and main scripts evaluate.
 */

// Bootstrap, PerfectScrollbar, and the SNEAT runtime are global-oriented scripts.
// Keep these imports ordered by dependency rather than alphabetically.
import './sneat/runtime-globals';
import './sneat/helpers';
import './sneat/theme-config';
import './sneat/menu';
import './sneat/main';
