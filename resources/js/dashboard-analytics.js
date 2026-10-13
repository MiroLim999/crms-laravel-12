import ApexCharts from 'apexcharts';

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

// Keep this breakpoint aligned with the dashboard portrait rule in _crms.scss.
const portraitDesk = window.matchMedia('(min-width: 992px) and (orientation: portrait)').matches;
const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
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

function escapeHtml(value) {
  const entities = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
  return String(value).replace(/[&<>"']/g, character => entities[character]);
}

// Whole-record axes use a compact 1/2/5 scale.
function countAxis(peak) {
  const top = Math.max(peak, 1);
  let step;

  for (let magnitude = 1; !step; magnitude *= 10) {
    step = [1, 2, 5].map(multiple => multiple * magnitude).find(size => Math.ceil(top / size) <= 5);
  }

  const intervals = Math.ceil(top / step);
  return { min: 0, max: intervals * step, tickAmount: intervals };
}

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
        + `<span class="dashboard-tooltip__dot" style="background:${escapeHtml(row.color)}"></span>`
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
      height: portraitDesk ? 380 : 350,
      fontFamily: theme.font,
      foreColor: theme.text,
      parentHeightOffset: 0,
      toolbar: { show: false },
      zoom: { enabled: false },
      animations: { enabled: !reducedMotion, speed: 420 },
    },
    colors: series.map(item => item.color),
    dataLabels: { enabled: false },
    fill: {
      type: 'gradient',
      gradient: {
        type: 'vertical',
        shadeIntensity: 0,
        opacityFrom: 0.42,
        opacityTo: 0.03,
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
    legend: { show: false },
    markers: {
      size: labels.length < 3 ? 5 : 0,
      colors: series.map(() => theme.paper),
      strokeColors: series.map(item => item.color),
      strokeWidth: 3,
      hover: { size: 6 },
    },
    series: series.map(item => ({ name: item.name, data: item.data })),
    stroke: { curve: 'monotoneCubic', width: 3 },
    tooltip: {
      shared: true,
      intersect: false,
      custom: volumeTooltip(titles),
    },
    xaxis: {
      categories: labels,
      tickAmount: labels.length > 2
        ? Math.max(2, Math.min(labels.length - 1, Math.floor(target.clientWidth / 100)))
        : undefined,
      axisBorder: { show: false },
      axisTicks: { show: false },
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

function documentTypeTooltip(populated, total) {
  return ({ series, seriesIndex }) => {
    const slice = populated[seriesIndex] || {};
    const count = Number(series[seriesIndex] ?? 0);
    const share = total > 0 ? (count / total * 100).toFixed(1) : '0.0';

    return '<div class="dashboard-tooltip dashboard-tooltip--donut">'
      + '<div class="dashboard-tooltip__type">'
      + `<span class="dashboard-tooltip__dot" style="background:${escapeHtml(slice.color || '')}"></span>`
      + `<span>${escapeHtml(slice.label || '')}</span></div>`
      + '<div class="dashboard-tooltip__type-value">'
      + `<strong>${count.toLocaleString()}</strong>`
      + `<span>${count === 1 ? 'record' : 'records'}</span>`
      + `<span class="dashboard-tooltip__share">${share}%</span></div>`
      + '</div>';
  };
}

function renderDocumentTypes(slices) {
  const target = document.getElementById('dashboard-type-chart');
  if (!target || !Array.isArray(slices)) return;

  const populated = slices.filter(slice => Number(slice.total) > 0);
  if (!populated.length) return;

  const theme = themeOptions(target);
  const total = populated.reduce((sum, slice) => sum + Number(slice.total), 0);
  const size = Math.max(188, Math.min(target.parentElement?.clientWidth || 240, 260));

  draw(target, {
    chart: {
      type: 'donut',
      width: size,
      height: size,
      fontFamily: theme.font,
      foreColor: theme.text,
      parentHeightOffset: 0,
      animations: { enabled: !reducedMotion, speed: 420 },
    },
    colors: populated.map(slice => slice.color),
    dataLabels: { enabled: false },
    labels: populated.map(slice => slice.label),
    legend: { show: false },
    plotOptions: {
      pie: {
        expandOnClick: false,
        donut: {
          size: '76%',
          labels: { show: false },
        },
      },
    },
    series: populated.map(slice => Number(slice.total)),
    states: {
      hover: { filter: { type: 'lighten', value: 0.04 } },
      active: { filter: { type: 'none' } },
    },
    stroke: { width: populated.length > 1 ? 3 : 0, colors: [theme.paper] },
    tooltip: {
      enabled: true,
      custom: documentTypeTooltip(populated, total),
    },
  });
}

function renderQuality(data) {
  const target = document.getElementById('dashboard-quality-chart');
  if (!target || !data) return;

  const confidenceAvailable = data.averageConfidence !== null && data.averageConfidence !== undefined;
  const correctionAvailable = data.correctionRate !== null && data.correctionRate !== undefined;
  const theme = themeOptions(target);
  const confidence = confidenceAvailable ? Number(data.averageConfidence) : 0;
  const correction = correctionAvailable ? Number(data.correctionRate) : 0;
  const size = Math.min(target.clientWidth || 188, 188);

  draw(target, {
    chart: {
      type: 'radialBar',
      height: size,
      fontFamily: theme.font,
      foreColor: theme.text,
      parentHeightOffset: 0,
      sparkline: { enabled: true },
      animations: { enabled: !reducedMotion, speed: 420 },
    },
    colors: [theme.primary, theme.warning],
    labels: ['Confidence', 'Corrections'],
    plotOptions: {
      radialBar: {
        startAngle: -90,
        endAngle: 270,
        hollow: {
          size: '54%',
          background: 'transparent',
        },
        track: {
          background: theme.border,
          margin: 7,
          strokeWidth: '94%',
        },
        dataLabels: { show: false },
      },
    },
    series: [confidence, correction],
    states: {
      hover: { filter: { type: 'none' } },
      active: { filter: { type: 'none' } },
    },
    stroke: { lineCap: 'round' },
    tooltip: { enabled: false },
  });
}

function throughputAxis(peak, narrow) {
  const desired = Math.max(peak * 1.14, peak + 1);
  const targetIntervals = narrow ? 3 : 5;
  const rawStep = desired / targetIntervals;
  const magnitude = Math.max(1, 10 ** Math.floor(Math.log10(Math.max(rawStep, 1))));
  const step = [1, 2, 2.5, 5, 10]
    .map(multiplier => multiplier * magnitude)
    .find(candidate => candidate >= rawStep) || magnitude * 10;
  const max = Math.ceil(desired / step) * step;

  return { max, ticks: Math.max(1, Math.round(max / step)) };
}

function renderThroughput(data) {
  // Sparse periods intentionally have no chart target.
  const target = document.getElementById('dashboard-throughput-chart');
  if (!target || !data || !Array.isArray(data.totals) || !data.totals.length) return;

  const totals = data.totals.map(Number);
  const theme = themeOptions(target);
  const peak = Math.max(...totals);
  const axis = throughputAxis(peak, target.clientWidth < 420);
  const height = Math.max(196, totals.length * 54 + 34);

  draw(target, {
    chart: {
      type: 'bar',
      height,
      fontFamily: theme.font,
      foreColor: theme.text,
      parentHeightOffset: 0,
      toolbar: { show: false },
      animations: { enabled: !reducedMotion, speed: 420 },
    },
    colors: [theme.primary],
    dataLabels: {
      enabled: true,
      formatter: value => Number(value).toLocaleString(),
      offsetX: 16,
      style: { fontSize: '12px', fontWeight: 700, colors: [theme.heading] },
    },
    grid: {
      borderColor: theme.border,
      strokeDashArray: 4,
      padding: { left: 6, right: 30, top: -8, bottom: -6 },
    },
    plotOptions: {
      bar: {
        borderRadius: 8,
        borderRadiusApplication: 'end',
        barHeight: '58%',
        horizontal: true,
        dataLabels: { position: 'top' },
      },
    },
    series: [{ name: 'Submitted records', data: totals }],
    tooltip: {
      y: { formatter: value => `${Number(value).toLocaleString()} ${Number(value) === 1 ? 'record' : 'records'}` },
    },
    xaxis: {
      categories: Array.isArray(data.labels) ? data.labels : [],
      min: 0,
      max: axis.max,
      tickAmount: axis.ticks,
      axisBorder: { show: false },
      axisTicks: { show: false },
      labels: {
        formatter: value => Math.round(value).toLocaleString(),
        style: { fontSize: '11px' },
      },
    },
    yaxis: {
      labels: {
        maxWidth: Math.round(Math.min(150, Math.max(82, target.clientWidth * 0.27))),
        style: { fontSize: '12px', fontWeight: 500 },
      },
    },
  });
}

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

// Swap only this panel's fields so every other period remains independent.
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

function closeMenu(element) {
  const toggle = element?.closest('.dashboard-period')?.querySelector('[data-bs-toggle="dropdown"]');
  if (toggle && window.bootstrap?.Dropdown) window.bootstrap.Dropdown.getInstance(toggle)?.hide();
}

function linkDates(form) {
  const from = form?.querySelector('[data-dashboard-from]');
  const to = form?.querySelector('[data-dashboard-to]');
  if (!from || !to) return;

  to.min = from.value || from.min;
  from.max = to.value || to.max;
}

function announce(card) {
  const status = document.querySelector('[data-dashboard-status]');
  const title = card.querySelector('.card-title')?.textContent.trim();
  const range = card.querySelector('.dashboard-period__note strong')?.textContent.trim();
  if (status && title && range) status.textContent = `${title} now shows ${range}.`;
}

async function refreshPanel(panel, query, trigger) {
  const url = `${window.location.pathname}${query.toString() ? `?${query}` : ''}`;
  const card = document.getElementById(`panel-${panel}`);
  if (!card) {
    window.location.assign(url);
    return;
  }

  const hadFocus = card.contains(document.activeElement);
  const chartDataWasOpen = card.querySelector('details.dashboard-chart-data')?.open === true;
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

    if (chartDataWasOpen) {
      const freshDisclosure = freshCard.querySelector('details.dashboard-chart-data');
      if (freshDisclosure) freshDisclosure.open = true;
    }

    charts.get(panels[panel].chart)?.destroy();
    charts.delete(panels[panel].chart);
    card.replaceWith(freshCard);

    page.querySelectorAll('[data-dashboard-summary][id]').forEach(fresh => {
      document.getElementById(fresh.id)?.replaceWith(fresh);
    });

    const holder = document.getElementById('dashboard-chart-data');
    const data = JSON.parse(holder?.textContent || '{}');
    data[panels[panel].key] = freshData[panels[panel].key];
    if (holder) holder.textContent = JSON.stringify(data);

    linkDates(freshCard.querySelector('[data-dashboard-filter]'));
    panels[panel].render(data[panels[panel].key]);

    window.history.replaceState(null, '', url);
    announce(freshCard);
    if (hadFocus) document.getElementById(`${panel}-period-toggle`)?.focus({ preventScroll: true });
  } catch (error) {
    if (error.name === 'AbortError') return;
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

  document.addEventListener('submit', event => {
    const form = event.target.closest?.('[data-dashboard-filter]');
    const panel = form && panelOf(form);
    if (!panel) return;

    event.preventDefault();
    if (form.reportValidity()) refreshPanel(panel, queryFor(panel, new FormData(form)), form);
  });

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
