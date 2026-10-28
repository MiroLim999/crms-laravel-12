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
    inkRgb: read(root, '--crms-ink-rgb', '67, 89, 113'),
    font: read(root, '--bs-body-font-family', 'Public Sans, sans-serif'),
    primary: read(root, '--bs-primary', fallback.primary),
    warning: read(root, '--bs-warning', fallback.warning),
  };
}

// Keep this breakpoint aligned with the dashboard portrait rule in _crms.scss.
const portraitDesk = window.matchMedia('(min-width: 992px) and (orientation: portrait)').matches;
const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const charts = new Map();

// Motion. Cards rise in one after another (.dashboard-reveal in _crms.scss,
// 40ms apart); each chart plays its entrance once its card has landed and
// is on screen, and only once: redraws for a resize appear in place.
const REVEAL_STEP = 40; // Keep equal to $dashboard-reveal-step.
const pageStart = performance.now();
const animated = new Set();
const waiting = new Map();
const entranceSpeed = { area: 550, bar: 450, radar: 500, radialBar: 600 };

// Milliseconds until the card holding `node` has finished arriving.
function revealDelay(node) {
  const holder = node.closest('.dashboard-reveal');
  const index = Number.parseFloat(holder?.style.getPropertyValue('--dashboard-reveal')) || 0;
  const arrival = index * REVEAL_STEP + 160;

  return Math.max(80, arrival - (performance.now() - pageStart));
}

function draw(target, options) {
  waiting.get(target.id)?.();
  waiting.delete(target.id);
  charts.get(target.id)?.destroy();
  charts.delete(target.id);

  const entrance = !reducedMotion && !animated.has(target.id);
  options.chart = {
    ...options.chart,
    animations: entrance
      ? {
          enabled: true,
          speed: entranceSpeed[options.chart?.type] || 450,
          animateGradually: { enabled: true, delay: 50 },
          dynamicAnimation: { enabled: true, speed: 250 },
        }
      : { enabled: false },
  };

  const render = () => {
    waiting.delete(target.id);
    animated.add(target.id);
    const chart = new ApexCharts(target, options);
    charts.set(target.id, chart);
    chart.render();
  };

  if (!entrance || typeof IntersectionObserver === 'undefined') {
    render();
    return;
  }

  // Hold the chart until its card is in view, so charts further down the
  // page still animate when the user scrolls to them.
  let timer;
  const observer = new IntersectionObserver(entries => {
    if (!entries.some(entry => entry.isIntersecting)) return;
    observer.disconnect();
    timer = setTimeout(render, revealDelay(target));
  }, { threshold: 0.1 });

  observer.observe(target.closest('.card') || target);
  waiting.set(target.id, () => {
    observer.disconnect();
    clearTimeout(timer);
  });
}

// Headline numbers count up from zero to the value the server rendered,
// keeping its format ("1,204", "91.1%"). The final text is restored exactly.
function countUp(root = document) {
  if (reducedMotion) return;

  root.querySelectorAll('[data-dashboard-count]').forEach(node => {
    const final = node.textContent.trim();
    const match = final.match(/^(\D*?)(\d[\d,]*(?:\.\d+)?)(.*)$/);
    if (!match) return;

    const [, prefix, digits, suffix] = match;
    const value = Number(digits.replace(/,/g, ''));
    if (!Number.isFinite(value) || value === 0) return;

    const decimals = (digits.split('.')[1] || '').length;
    const format = new Intl.NumberFormat('en-US', {
      minimumFractionDigits: decimals,
      maximumFractionDigits: decimals,
    });
    const duration = 550;

    node.textContent = `${prefix}${format.format(0)}${suffix}`;
    setTimeout(() => {
      const start = performance.now();
      const step = now => {
        const progress = Math.min(1, (now - start) / duration);
        const eased = 1 - (1 - progress) ** 3;
        node.textContent = progress < 1 ? `${prefix}${format.format(value * eased)}${suffix}` : final;
        if (progress < 1) requestAnimationFrame(step);
      };
      requestAnimationFrame(step);
    }, Math.max(0, revealDelay(node) - 120));
  });
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

// A pressed legend button is the one document type being inspected. With no
// pressed button the complete chart is shown.
function focusedVolumeSeries() {
  const button = document.querySelector('#panel-trend [data-dashboard-series][aria-pressed="true"]');
  if (!button) return null;

  const index = Number(button.dataset.dashboardSeries);
  return Number.isInteger(index) ? index : null;
}

function renderVolume(data) {
  const target = document.getElementById('dashboard-volume-chart');
  if (!target || !data) return;

  const focused = focusedVolumeSeries();
  const allSeries = Array.isArray(data.series) ? data.series : [];
  const series = focused === null ? allSeries : allSeries.filter((item, index) => index === focused);
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
      height: portraitDesk ? 340 : 300,
      fontFamily: theme.font,
      foreColor: theme.text,
      parentHeightOffset: 0,
      toolbar: { show: false },
      zoom: { enabled: false },
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
      // Left keeps a small gap between the y-axis numbers and the plot. None
      // on the right: ApexCharts already leaves room there for the last label.
      padding: { top: -10, left: 6, right: 0, bottom: 0 },
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

// How each model is drawn: the model in use solid and filled, TrOCR Base
// dashed and lighter. The legend chips in the view use the same colours.
const performanceLook = {
  model: { color: '#ff6384', opacity: 0.32, marker: 4, width: 2, dash: 0 },
  base: { color: '#696cff', opacity: 0.18, marker: 3, width: 1.5, dash: 4 },
};

// The view sends only models with measured test scores, the model in use
// first; with none it shows a note in place of the chart. Nothing is filled
// in here, so the chart never shows a score nobody measured.
function renderOcrPerformance(data) {
  const target = document.getElementById('dashboard-ocr-radar-chart');
  const series = Array.isArray(data?.series) ? data.series : [];
  if (!target || !series.length) return;

  const theme = themeOptions(target);
  const pct = value => `${Math.round(value)}%`;
  // Scores ride on the axis labels (see xaxis.labels.formatter).
  const keys = ['character_accuracy', 'word_accuracy', 'exact_match'];
  const categories = ['Chars', 'Words', 'Exact'];
  const categoryNames = ['Character accuracy', 'Word accuracy', 'Exact match'];
  const values = series.map(item => keys.map(key => Number(item.scores[key])));
  const looks = series.map(item => performanceLook[item.role] || performanceLook.base);

  // Largest radius that fits: the stacked side labels need ~50px each
  // across, and the triangle plus labels need ~1.5r + 60px down.
  const width = target.clientWidth || 280;
  const height = 240;
  const radius = Math.max(60, Math.min(
    Math.floor((width / 2 - 50) / 0.866),
    Math.floor((height - 60) / 1.5),
  ));

  draw(target, {
    chart: {
      type: 'radar',
      height,
      fontFamily: theme.font,
      foreColor: theme.text,
      parentHeightOffset: 0,
      toolbar: { show: false },
    },
    colors: looks.map(look => look.color),
    dataLabels: { enabled: false },
    fill: {
      type: 'solid',
      opacity: looks.map(look => look.opacity),
    },
    legend: { show: false },
    markers: {
      size: looks.map(look => look.marker),
      colors: looks.map(() => theme.paper),
      strokeColors: looks.map(look => look.color),
      strokeWidth: looks.map(look => look.width),
      hover: { size: 6 },
    },
    plotOptions: {
      radar: {
        size: radius,
        // A triangle sits high in its circle; nudge it down to centre it.
        offsetY: Math.round(radius * 0.22),
        polygons: {
          strokeColors: theme.border,
          strokeWidth: 1,
          connectorColors: theme.border,
          fill: { colors: [`rgba(${theme.inkRgb}, 0.025)`, 'transparent'] },
        },
      },
    },
    series: series.map((item, index) => ({ name: item.name, data: values[index] })),
    stroke: {
      width: looks.map(look => look.width),
      dashArray: looks.map(look => look.dash),
    },
    tooltip: {
      custom: ({ dataPointIndex: point }) => {
        const rows = series.map((item, index) => {
          const { color } = looks[index];
          const value = `${values[index][point].toFixed(1)}%`;

          return '<div class="d-flex justify-content-between align-items-center small mb-1">'
            + `<span><span class="badge-dot me-1" style="display:inline-block;width:7px;height:7px;border-radius:50%;background:${color};"></span>${escapeHtml(item.name)}</span>`
            + (index === 0 ? `<strong style="color: ${color};">${value}</strong>` : `<span class="text-muted">${value}</span>`)
            + '</div>';
        }).join('');
        // A gain needs both the model in use and Base on the chart.
        const gain = series.length === 2 ? values[0][point] - values[1][point] : null;
        const gainRow = gain === null ? ''
          : `<div class="small fw-semibold mt-1 pt-1 border-top" style="color: ${gain >= 0 ? '#71dd37' : '#ff3e1d'}; font-size: 0.6875rem;">`
            + `${gain >= 0 ? '▲ +' : '▼ '}${gain.toFixed(1)}% absolute gain</div>`;

        return '<div class="dashboard-tooltip" style="min-width: 10.5rem; padding: 0.5rem 0.65rem; background: var(--bs-paper-bg); border: 1px solid var(--bs-border-color); border-radius: 8px; box-shadow: var(--bs-box-shadow-lg);">'
          + `<div class="fw-bold text-heading mb-1 pb-1 border-bottom small">${escapeHtml(categoryNames[point])}</div>`
          + `${rows}${gainRow}</div>`;
      },
    },
    xaxis: {
      categories,
      labels: {
        // Top label stays on one line (a second would run into the vertex);
        // the side labels stack name over score to stay narrow. The scores
        // are the first model's: the one in use, or Base when it has none.
        formatter: (label, opts) => {
          const index = opts?.dataPointIndex ?? categories.indexOf(label);
          const score = pct(values[0][index] ?? 0);
          return index === 0 ? `${label} ${score}` : [label, score];
        },
        style: {
          colors: [theme.heading, theme.heading, theme.heading],
          fontSize: '11px',
          fontWeight: 600,
          fontFamily: theme.font,
        },
      },
    },
    yaxis: {
      show: false,
      min: 0,
      max: 100,
      tickAmount: 4,
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
  // Draw an explicit square so the ring stays centred under the label.
  const size = Math.min(target.clientWidth || 208, 208);

  draw(target, {
    chart: {
      type: 'radialBar',
      width: size,
      height: size,
      fontFamily: theme.font,
      foreColor: theme.text,
      parentHeightOffset: 0,
      sparkline: { enabled: true },
    },
    colors: [theme.primary, theme.warning],
    labels: ['Confidence', 'Corrections'],
    plotOptions: {
      radialBar: {
        startAngle: -90,
        endAngle: 270,
        hollow: {
          size: '52%',
          background: 'transparent',
        },
        track: {
          background: theme.border,
          margin: 4,
          strokeWidth: '92%',
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

const panels = {
  trend: { chart: 'dashboard-volume-chart', key: 'volume', render: renderVolume },
  ocr: { chart: 'dashboard-quality-chart', key: 'quality', render: renderQuality },
  // No chart: a swapped card is all there is to redraw.
  requests: { chart: '', key: 'requests', render: () => {} },
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
  const focusedVolumeSeriesName = card
    .querySelector('[data-dashboard-series][aria-pressed="true"]')
    ?.dataset.dashboardSeriesName;
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

    if (focusedVolumeSeriesName) {
      const matchingButton = [...freshCard.querySelectorAll('[data-dashboard-series]')]
        .find(button => button.dataset.dashboardSeriesName === focusedVolumeSeriesName);
      if (matchingButton) matchingButton.setAttribute('aria-pressed', 'true');
    }
    syncVolumeLegend(freshCard);

    const chartId = panels[panel].chart;
    waiting.get(chartId)?.();
    waiting.delete(chartId);
    charts.get(chartId)?.destroy();
    charts.delete(chartId);
    // New data gets a fresh entrance, played as soon as the card is in.
    animated.delete(chartId);
    freshCard.classList.add('dashboard-panel--refreshed');
    card.replaceWith(freshCard);

    const holder = document.getElementById('dashboard-chart-data');
    const data = JSON.parse(holder?.textContent || '{}');
    data[panels[panel].key] = freshData[panels[panel].key];
    if (holder) holder.textContent = JSON.stringify(data);

    linkDates(freshCard.querySelector('[data-dashboard-filter]'));
    panels[panel].render(data[panels[panel].key]);
    countUp(freshCard);

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

// The chart-data table floats over the content below its card, so it behaves
// like a popover: Escape, a click elsewhere or tabbing away closes it, and on
// opening it is scrolled into view if it would drop past the bottom of the
// window. Delegated, so it survives a panel swap.
function setupChartData() {
  const selector = 'details.dashboard-chart-data[open]';

  document.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;

    document.querySelectorAll(selector).forEach(disclosure => {
      const hadFocus = disclosure.contains(document.activeElement);
      disclosure.open = false;
      if (hadFocus) disclosure.querySelector('summary')?.focus();
    });
  });

  document.addEventListener('click', event => {
    document.querySelectorAll(selector).forEach(disclosure => {
      if (!disclosure.contains(event.target)) disclosure.open = false;
    });
  });

  // Keyboard focus moving out closes it. A null relatedTarget (switching
  // window or tab, a click on nothing focusable) is left to the click handler.
  document.addEventListener('focusout', event => {
    const disclosure = event.target.closest?.(selector);
    const next = event.relatedTarget;
    if (disclosure && next && !disclosure.contains(next)) disclosure.open = false;
  });

  // toggle does not bubble, so listen in the capture phase.
  document.addEventListener('toggle', event => {
    const disclosure = event.target;
    if (!disclosure.matches?.(selector)) return;

    const surface = disclosure.querySelector('.dashboard-chart-data__surface');
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    surface?.scrollIntoView({ block: 'nearest', behavior: reduced ? 'auto' : 'smooth' });
  }, true);
}

// Keep the toggle text accurate for pointer and keyboard users. This also
// works on a freshly fetched trend panel before it replaces the old one.
function syncVolumeLegend(root = document) {
  const panel = root.matches?.('#panel-trend') ? root : root.querySelector?.('#panel-trend');
  const buttons = [...(panel?.querySelectorAll('[data-dashboard-series]') || [])];
  const focused = buttons.find(button => button.getAttribute('aria-pressed') === 'true');

  buttons.forEach(button => {
    const name = button.dataset.dashboardSeriesName || button.textContent.trim();
    const isFocused = button === focused;
    button.title = isFocused ? 'Show all document types' : `Focus on ${name}`;
    button.setAttribute('aria-label', isFocused
      ? `${name} is focused. Show all document types`
      : `Focus chart on ${name}`);
  });
}

// Selecting a document type isolates it and rescales the graph. Selecting the
// focused type again restores all series. Delegation survives a panel swap.
function setupVolumeLegend() {
  syncVolumeLegend();

  document.addEventListener('click', event => {
    const button = event.target.closest?.('#panel-trend [data-dashboard-series]');
    if (!button) return;

    const wasFocused = button.getAttribute('aria-pressed') === 'true';
    const buttons = [...document.querySelectorAll('#panel-trend [data-dashboard-series]')];
    buttons.forEach(item => item.setAttribute('aria-pressed', 'false'));
    if (!wasFocused) button.setAttribute('aria-pressed', 'true');

    syncVolumeLegend();
    renderVolume(readChartData()?.volume);

    const status = document.querySelector('[data-dashboard-status]');
    if (status) {
      const name = button.dataset.dashboardSeriesName || button.textContent.trim();
      status.textContent = wasFocused
        ? 'The digitization chart now shows all document types.'
        : `The digitization chart is focused on ${name}. Select it again to show all document types.`;
    }
  });
}

document.addEventListener('DOMContentLoaded', () => {
  setupPeriodFilters();
  setupChartData();
  setupVolumeLegend();
  countUp();

  const data = readChartData();
  if (!data) return;

  renderVolume(data.volume);
  renderQuality(data.quality);
  renderOcrPerformance(data.ocrPerformance);

  // Colours are read when a chart is drawn, so a colour-mode switch redraws them all.
  window.addEventListener('crms:themechange', () => {
    const latest = readChartData() || data;
    renderVolume(latest.volume);
    renderQuality(latest.quality);
    renderOcrPerformance(latest.ocrPerformance);
  });

  // The radar radius follows its card's width, so redraw it (without
  // replaying the entrance) once resizing settles.
  let resizeTimer;
  let lastWidth = window.innerWidth;
  window.addEventListener('resize', () => {
    // Mobile browsers fire resize as the address bar slides; width is what matters.
    if (window.innerWidth === lastWidth) return;
    lastWidth = window.innerWidth;
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(() => renderOcrPerformance((readChartData() || data).ocrPerformance), 200);
  });
});
