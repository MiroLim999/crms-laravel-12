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
import './theme';

// Auto-dismiss session alerts after 4 seconds
document.addEventListener('DOMContentLoaded', () => {
    const successAlerts = document.querySelectorAll('.alert-dismissible.alert-success');
    successAlerts.forEach((alertEl) => {
        setTimeout(() => {
            if (window.bootstrap?.Alert) {
                const bsAlert = window.bootstrap.Alert.getOrCreateInstance(alertEl);
                bsAlert.close();
            } else {
                alertEl.classList.remove('show');
                setTimeout(() => alertEl.remove(), 150);
            }
        }, 4000);
    });
});
