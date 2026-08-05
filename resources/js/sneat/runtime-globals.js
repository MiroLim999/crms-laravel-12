/**
 * Browser globals required by the harvested SNEAT scripts and inline pages.
 *
 * Keep this as app.js's first side-effect import. Static ES module imports run
 * before app.js's own statements, so assigning these values directly in app.js
 * made them unavailable when an earlier SNEAT module was being evaluated.
 */

import * as bootstrap from 'bootstrap';
import PerfectScrollbar from 'perfect-scrollbar';

window.bootstrap = bootstrap;
window.PerfectScrollbar = PerfectScrollbar;
