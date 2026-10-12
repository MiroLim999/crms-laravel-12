import ApexCharts from 'apexcharts';

// Fallbacks only; the live values come from the theme, so a ring and the dot
// beside it are always the same colour.
const fallback = {
  primary: '#2563eb',
  warning: '#ffab00',
  secondary: '#8592a3',
  heading: '#384551',
};

function readChartData() {
  const node = document.getElementById('dashboard-chart-data');

  if (!node) return null;

  try {
    return JSON.parse(node.textContent || '{}');
  } catch (error) {
    return null;
  }
}

function themeOptions(target) {
  const root = getComputedStyle(document.documentElement);
  const card = getComputedStyle(target?.closest('.card') || document.documentElement);
  const read = (styles, name, value) => {
    const found = styles.getPropertyValue(name).trim();
    return found && found !== 'inherit' ? found : value;
  };

  return {
    text: read(root, '--bs-secondary-color', fallback.secondary),
    heading: read(root, '--bs-heading-color', fallback.heading),
    border: read(root, '--bs-border-color', 'rgba(67, 89, 113, .12)'),
    paper: read(card, '--bs-card-bg', '#fff'),
    font: read(root, '--bs-body-font-family', 'Public Sans, sans-serif'),
    primary: read(root, '--bs-primary', fallback.primary),
    warning: read(root, '--bs-warning', fallback.warning),
  };
}

// An upright monitor (1080 x 1920) is narrow but tall, so its charts can be
// taller. Keep this in step with the portrait rule in _crms.scss.
const portraitDesk = window.matchMedia('(min-width: 992px) and (orientation: portrait)').matches;

// The live charts by element id, so one is destroyed before it is drawn again.
const charts = new Map();

function draw(target, options) {
  charts.get(target.id)?.destroy();

  const chart = new ApexCharts(target, options);
  charts.set(target.id, chart);
  chart.render();
}

function emptyState(target, message) {
  target.innerHTML = `<div class="dashboard-chart-empty">${message}</div>`;
}

// Type names come from the type list, so they are escaped before they are
// written into a tooltip.
function escapeHtml(value) {
  const entities = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
  return String(value).replace(/[&<>"']/g, character => entities[character]);
}

// A long type name would spill out of the donut's centre; the key under the
// chart has it in full.
function shorten(text, length = 20) {
  const value = String(text);
  return value.length > length ? `${value.slice(0, length - 1).trimEnd()}…` : value;
}

// Records are counted in whole numbers, so the axis steps by 1, 2 or 5 times
// a power of ten: the smallest step that needs at most five intervals to
// reach the peak. No tick is a fraction, and the top is the first tick at or
// above the peak, so the chart never runs far past its data.
function countAxis(peak) {
  const top = Math.max(peak, 1);
  let step;

  for (let magnitude = 1; !step; magnitude *= 10) {
    step = [1, 2, 5].map(multiple => multiple * magnitude).find(size => Math.ceil(top / size) <= 5);
  }

  const intervals = Math.ceil(top / step);
  return { min: 0, max: intervals * step, tickAmount: intervals };
}

// The trend's tooltip, a small card: the count first, as the eye reads it,
// then the day or month in full, then each type when there are several.
function volumeTooltip(titles) {
  return ({ series: values, dataPointIndex: index, w }) => {
    const rows = w.globals.seriesNames.map((name, position) => ({
      name,
      color: w.globals.colors[position],
      value: Number(values[position]?.[index] ?? 0),
    }));
    const total = rows.reduce((sum, row) => sum + row.value, 0);
    const breakdown = rows.length > 1
      ? `<ul class="dashboard-tooltip__rows">${rows.map(row => '<li>'
        + `<span class="dashboard-tooltip__dot" style="background:${row.color}"></span>`
        + `<span>${escapeHtml(row.name)}</span>`
        + `<strong>${row.value.toLocaleString()}</strong></li>`).join('')}</ul>`
      : '';

    return '<div class="dashboard-tooltip">'
      + `<div class="dashboard-tooltip__value"><strong>${total.toLocaleString()}</strong> ${total === 1 ? 'record' : 'records'}</div>`
      + `<div class="dashboard-tooltip__date">${escapeHtml(titles[index] ?? '')}</div>`
      + `${breakdown}</div>`;
  };
}

function renderVolume(data) {
  const target = document.getElementById('dashboard-volume-chart');
  if (!target || !data) return;

  // Each series carries its document type's colour, so a type looks the same
  // here as in the donut, whatever the order of either chart.
  const series = Array.isArray(data.series) ? data.series : [];
  const labels = Array.isArray(data.labels) ? data.labels : [];
  const titles = Array.isArray(data.titles) ? data.titles : labels;
  const peak = Math.max(0, ...series.flatMap(item => (item.data || []).map(Number)));
  if (peak === 0) {
    emptyState(target, 'No submissions in this period.');
    return;
  }

  const theme = themeOptions(target);

  draw(target, {
    chart: {
      type: 'area',
      height: portraitDesk ? 420 : 350,
      fontFamily: theme.font,
      foreColor: theme.text,
      parentHeightOffset: 0,
      toolbar: { show: false },
      zoom: { enabled: false },
    },
    colors: series.map(item => item.color),
    dataLabels: { enabled: false },
    // Each line's own colour under it, fading out towards the baseline.
    fill: {
      type: 'gradient',
      gradient: {
        type: 'vertical',
        shadeIntensity: 0,
        opacityFrom: 0.5,
        opacityTo: 0.04,
        stops: [0, 100],
      },
    },
    grid: {
      borderColor: theme.border,
      strokeDashArray: 0,
      xaxis: { lines: { show: false } },
      yaxis: { lines: { show: true } },
      padding: { top: -10, left: 6, right: 10, bottom: 0 },
    },
    // The key is in the panel's header.
    legend: { show: false },
    // A line needs two points; with fewer, show the point itself. The point
    // under the pointer is a ring in its line's colour.
    markers: {
      size: labels.length < 3 ? 5 : 0,
      colors: series.map(() => theme.paper),
      strokeColors: series.map(item => item.color),
      strokeWidth: 3,
      hover: { size: 6 },
    },
    series: series.map(item => ({ name: item.name, data: item.data })),
    // Smooth, but never above a peak or below zero between two counts. Square
    // ends: the hidden hover markers take the line's cap, and a round one
    // draws them as dots in the corner even at size 0.
    stroke: { curve: 'monotoneCubic', width: 3 },
    tooltip: {
      shared: true,
      intersect: false,
      custom: volumeTooltip(titles),
    },
    xaxis: {
      categories: labels,
      axisBorder: { show: false },
      axisTicks: { show: false },
      // A hairline from the pointed-at day down to the axis.
      crosshairs: {
        show: true,
        width: 1,
        position: 'back',
        opacity: 0.35,
        stroke: { width: 0 },
        fill: { type: 'solid', color: theme.text },
      },
      labels: { rotate: 0, hideOverlappingLabels: true, style: { fontSize: '12px' } },
      tooltip: { enabled: false },
    },
    yaxis: {
      ...countAxis(peak),
      labels: {
        formatter: value => Math.round(value).toLocaleString(),
        style: { fontSize: '12px' },
      },
    },
  });
}

function renderDocumentTypes(slices) {
  const target = document.getElementById('dashboard-type-chart');
  if (!target || !Array.isArray(slices)) return;

  const populated = slices.filter(slice => Number(slice.total) > 0);
  if (!populated.length) return;

  const theme = themeOptions(target);
  const total = populated.reduce((sum, slice) => sum + Number(slice.total), 0);

  draw(target, {
    chart: {
      type: 'donut',
      height: portraitDesk ? 300 : 270,
      fontFamily: theme.font,
      foreColor: theme.text,
      parentHeightOffset: 0,
    },
    // The slices are largest first, but each keeps its own type's colour.
    colors: populated.map(slice => slice.color),
    dataLabels: { enabled: false },
    labels: populated.map(slice => slice.label),
    // The key, with each type's numbers, is listed under the chart.
    legend: { show: false },
    plotOptions: {
      pie: {
        expandOnClick: false,
        donut: {
          size: '70%',
          labels: {
            show: true,
            // The centre shows the total; pointing at a slice shows that type.
            name: {
              show: true,
              fontSize: '13px',
              fontWeight: 500,
              color: theme.text,
              offsetY: -12,
              formatter: name => shorten(name),
            },
            value: {
              show: true,
              fontSize: '28px',
              fontWeight: 700,
              color: theme.heading,
              offsetY: 14,
              formatter: value => Number(value).toLocaleString(),
            },
            total: {
              show: true,
              label: 'Total',
              fontSize: '13px',
              fontWeight: 500,
              color: theme.text,
              formatter: () => total.toLocaleString(),
            },
          },
        },
      },
    },
    series: populated.map(slice => Number(slice.total)),
    states: {
      hover: { filter: { type: 'none' } },
      active: { filter: { type: 'none' } },
    },
    // Thin gaps in the card's colour between slices. One slice is a whole
    // ring, with no gap in it.
    stroke: { width: populated.length > 1 ? 3 : 0, colors: [theme.paper] },
    // The centre already names the slice under the pointer.
    tooltip: { enabled: false },
  });
}

function renderQuality(data) {
  const target = document.getElementById('dashboard-quality-chart');
  if (!target || !data) return;

  const confidenceAvailable = data.averageConfidence !== null && data.averageConfidence !== undefined;
  const correctionAvailable = data.correctionRate !== null && data.correctionRate !== undefined;
  if (!confidenceAvailable && !correctionAvailable) {
    emptyState(target, 'OCR quality signals will appear after scored fields are verified.');
    return;
  }

  const theme = themeOptions(target);
  const confidence = confidenceAvailable ? Number(data.averageConfidence) : 0;
  const correction = correctionAvailable ? Number(data.correctionRate) : 0;

  draw(target, {
    chart: {
      type: 'radialBar',
      height: portraitDesk ? 340 : 310,
      fontFamily: theme.font,
      foreColor: theme.text,
      parentHeightOffset: 0,
      sparkline: { enabled: true },
    },
    colors: [theme.primary, theme.warning],
    labels: ['OCR confidence', 'Human correction'],
    plotOptions: {
      radialBar: {
        endAngle: 270,
        hollow: { size: '38%' },
        startAngle: -90,
        track: {
          background: theme.border,
          margin: 7,
          strokeWidth: '96%',
        },
        dataLabels: {
          name: { fontSize: '12px', offsetY: -1 },
          value: {
            fontSize: '22px',
            fontWeight: 600,
            offsetY: 4,
            formatter: value => `${Number(value).toFixed(1)}%`,
          },
          // The centre shows a result, not a setting: the review threshold
          // is written out below the chart instead.
          total: {
            show: true,
            label: 'Avg confidence',
            fontSize: '12px',
            formatter: () => (confidenceAvailable ? `${confidence.toFixed(1)}%` : '—'),
          },
        },
      },
    },
    series: [confidence, correction],
    stroke: { lineCap: 'round' },
  });
}

function renderThroughput(data) {
  const target = document.getElementById('dashboard-throughput-chart');
  if (!target || !data || !Array.isArray(data.totals) || !data.totals.length) return;

  const theme = themeOptions(target);

  draw(target, {
    chart: {
      type: 'bar',
      height: Math.max(250, data.totals.length * 45),
      fontFamily: theme.font,
      foreColor: theme.text,
      parentHeightOffset: 0,
      toolbar: { show: false },
    },
    colors: [theme.primary],
    // Each count sits just past its bar's end in text colour, readable however
    // short the bar is.
    dataLabels: {
      enabled: true,
      formatter: value => Number(value).toLocaleString(),
      offsetX: 24,
      style: { fontSize: '12px', fontWeight: 600, colors: [theme.text] },
    },
    grid: {
      borderColor: theme.border,
      strokeDashArray: 5,
      padding: { left: 8, right: 30, top: -12, bottom: -8 },
    },
    plotOptions: {
      bar: {
        borderRadius: 6,
        borderRadiusApplication: 'end',
        barHeight: '46%',
        horizontal: true,
        dataLabels: { position: 'top' },
      },
    },
    series: [{ name: 'Submitted records', data: data.totals.map(Number) }],
    tooltip: {
      y: { formatter: value => `${Number(value).toLocaleString()} records` },
    },
    xaxis: {
      categories: data.labels || [],
      min: 0,
      axisBorder: { show: false },
      axisTicks: { show: false },
      labels: { formatter: value => Math.round(value).toLocaleString() },
    },
    yaxis: {
      labels: {
        maxWidth: 105,
        style: { fontSize: '12px', fontWeight: 500 },
      },
    },
  });
}

// Each panel and the chart it draws.
const panels = {
  trend: { chart: 'dashboard-volume-chart', key: 'volume', render: renderVolume },
  types: { chart: 'dashboard-type-chart', key: 'documentTypes', render: renderDocumentTypes },
  ocr: { chart: 'dashboard-quality-chart', key: 'quality', render: renderQuality },
  staff: { chart: 'dashboard-throughput-chart', key: 'throughput', render: renderThroughput },
};

const pending = new Map();

function panelOf(element) {
  const card = element.closest('[id^="panel-"]');
  const name = card?.id.replace('panel-', '');

  return name in panels ? name : null;
}

// The page's own query string with this panel's fields swapped for the new
// ones, so every other panel keeps the period it already shows.
function queryFor(panel, fields) {
  const query = new URLSearchParams(window.location.search);

  [...query.keys()].forEach(key => {
    if (key.startsWith(`${panel}_`)) query.delete(key);
  });
  fields.forEach((value, key) => {
    if (key.startsWith(`${panel}_`) && value !== '') query.set(key, value);
  });

  return query;
}

// The menu stays open while its dates are typed in, so it is closed here,
// before its panel is replaced.
function closeMenu(element) {
  const toggle = element?.closest('.dashboard-period')?.querySelector('[data-bs-toggle="dropdown"]');
  if (toggle && window.bootstrap?.Dropdown) window.bootstrap.Dropdown.getInstance(toggle)?.hide();
}

// "To" cannot be before "From", nor "From" after "To", so the browser stops a
// reversed range before it is sent.
function linkDates(form) {
  const from = form?.querySelector('[data-dashboard-from]');
  const to = form?.querySelector('[data-dashboard-to]');
  if (!from || !to) return;

  to.min = from.value || from.min;
  from.max = to.value || to.max;
}

// A panel's new period, for screen readers; the panel itself changed silently.
function announce(card) {
  const status = document.querySelector('[data-dashboard-status]');
  const title = card.querySelector('.card-title')?.textContent.trim();
  const range = card.querySelector('.dashboard-period__note strong')?.textContent.trim();
  if (status && title && range) status.textContent = `${title} now shows ${range}.`;
}

// Fetches the dashboard for the new query and swaps in only the panel that
// changed, plus the summary cards, which read from the trend and OCR panels.
// Anything unexpected falls back to an ordinary page load.
async function refreshPanel(panel, query, trigger) {
  const url = `${window.location.pathname}${query.toString() ? `?${query}` : ''}`;
  const card = document.getElementById(`panel-${panel}`);
  if (!card) {
    window.location.assign(url);
    return;
  }

  const hadFocus = card.contains(document.activeElement);
  closeMenu(trigger);

  pending.get(panel)?.abort();
  const request = new AbortController();
  pending.set(panel, request);

  card.setAttribute('aria-busy', 'true');
  card.classList.add('dashboard-panel--loading');

  try {
    const response = await fetch(url, {
      headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' },
      credentials: 'same-origin',
      signal: request.signal,
    });
    if (!response.ok || response.redirected) throw new Error('Unexpected response');

    const page = new DOMParser().parseFromString(await response.text(), 'text/html');
    const freshCard = page.getElementById(`panel-${panel}`);
    const freshData = JSON.parse(page.getElementById('dashboard-chart-data')?.textContent || 'null');
    if (!freshCard || !freshData) throw new Error('Unexpected page');

    charts.get(panels[panel].chart)?.destroy();
    charts.delete(panels[panel].chart);
    card.replaceWith(freshCard);

    const summary = page.querySelector('[data-dashboard-summary]');
    const currentSummary = document.querySelector('[data-dashboard-summary]');
    if (summary && currentSummary) currentSummary.replaceWith(summary);

    // Keep the chart data current for the panel that was swapped.
    const holder = document.getElementById('dashboard-chart-data');
    const data = JSON.parse(holder?.textContent || '{}');
    data[panels[panel].key] = freshData[panels[panel].key];
    if (holder) holder.textContent = JSON.stringify(data);

    linkDates(freshCard.querySelector('[data-dashboard-filter]'));
    panels[panel].render(data[panels[panel].key]);

    window.history.replaceState(null, '', url);
    announce(freshCard);
    // Back to the period button, so the keyboard picks up where it was.
    if (hadFocus) document.getElementById(`${panel}-period-toggle`)?.focus({ preventScroll: true });
  } catch (error) {
    if (error.name === 'AbortError') return;
    // Could not update in place: load the page the ordinary way.
    window.location.assign(url);
  } finally {
    if (pending.get(panel) === request) pending.delete(panel);
    card.removeAttribute('aria-busy');
    card.classList.remove('dashboard-panel--loading');
  }
}

function setupPeriodFilters() {
  document.querySelectorAll('[data-dashboard-filter]').forEach(linkDates);

  document.addEventListener('input', event => {
    if (event.target.matches?.('[data-dashboard-from], [data-dashboard-to]')) linkDates(event.target.form);
  });

  // Custom dates.
  document.addEventListener('submit', event => {
    const form = event.target.closest?.('[data-dashboard-filter]');
    const panel = form && panelOf(form);
    if (!panel) return;

    event.preventDefault();
    if (form.reportValidity()) refreshPanel(panel, queryFor(panel, new FormData(form)), form);
  });

  // A preset. A modified click still opens the link the ordinary way.
  document.addEventListener('click', event => {
    const link = event.target.closest?.('[data-dashboard-period-link]');
    const panel = link && panelOf(link);
    if (!panel || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

    event.preventDefault();
    refreshPanel(panel, queryFor(panel, new URL(link.href).searchParams), link);
  });
}

document.addEventListener('DOMContentLoaded', () => {
  setupPeriodFilters();

  const data = readChartData();
  if (!data) return;

  renderVolume(data.volume);
  renderDocumentTypes(data.documentTypes);
  renderQuality(data.quality);
  renderThroughput(data.throughput);
});
