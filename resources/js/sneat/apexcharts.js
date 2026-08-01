/**
 * ApexCharts, harvested from sneat/resources/assets/vendor/libs/apex-charts/apexcharts.js.
 *
 * Its only job is to publish the constructor on `window` so a page can draw a
 * chart from an inline script without needing its own bundle entry. Kept out of
 * app.js because it is ~500 KB and only the analytics page uses it.
 */
import ApexCharts from 'apexcharts';

try {
  window.ApexCharts = ApexCharts;
} catch (e) {}

export { ApexCharts };
