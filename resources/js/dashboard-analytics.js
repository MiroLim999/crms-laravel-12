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

// Split a label into lines of about `width` characters (at most two), so an
// axis can show a long name without cutting it off.
function wrapLabel(label, width) {
  const words = String(label ?? '').trim().split(/\s+/).filter(Boolean);
  const lines = [];

  for (const word of words) {
    const last = lines[lines.length - 1];
    if (last !== undefined && `${last} ${word}`.length <= width) {
      lines[lines.length - 1] = `${last} ${word}`;
    } else {
      lines.push(word);
    }
  }

  if (lines.length <= 1) return lines[0] ?? '';
  if (lines.length === 2) return lines;

  const rest = lines.slice(1).join(' ');
  return [lines[0], rest.length > width ? `${rest.slice(0, width - 1)}…` : rest];
}

function documentTypeTooltip(populated, total) {
  return ({ series, dataPointIndex }) => {
    const slice = populated[dataPointIndex] || {};
    const count = Number(series[0]?.[dataPointIndex] ?? slice.total ?? 0);
    const share = total > 0 ? (count / total * 100).toFixed(1) : '0.0';

    return '<div class="dashboard-tooltip dashboard-tooltip--bars">'
      + '<div class="dashboard-tooltip__type">'
      + `<span class="dashboard-tooltip__dot" style="display:inline-block;width:8px;height:8px;border-radius:50%;background:${escapeHtml(slice.color || '')}"></span>`
      + `<span>${escapeHtml(slice.label || '')}</span></div>`
      + '<div class="dashboard-tooltip__type-value">'
      + `<strong>${count.toLocaleString()}</strong> `
      + `<span>${count === 1 ? 'record' : 'records'}</span>`
      + `<span class="dashboard-tooltip__share">${share}%</span></div>`
      + '</div>';
  };
}

function renderDocumentTypes(slices) {
  const target = document.getElementById('dashboard-type-chart');
  if (!target || !Array.isArray(slices)) return;

  const populated = slices.filter(slice => Number(slice.total) > 0);
  if (!populated.length) {
    emptyState(target, 'No submissions in this period.');
    return;
  }

  const theme = themeOptions(target);
  const total = populated.reduce((sum, slice) => sum + Number(slice.total), 0);
  // Every type gets the same row height and a thin, fixed-thickness bar, so
  // two types or ten read the same way; the chart grows a row per type.
  const rowHeight = 52;
  const axisHeight = 40;
  const chartHeight = populated.length * rowHeight + axisHeight;
  const barPx = 16;
  const peak = Math.max(...populated.map(slice => Number(slice.total)));
  const axis = throughputAxis(peak, target.clientWidth < 320);

  draw(target, {
    chart: {
      type: 'bar',
      height: chartHeight,
      fontFamily: theme.font,
      foreColor: theme.text,
      parentHeightOffset: 0,
      toolbar: { show: false },
    },
    plotOptions: {
      bar: {
        horizontal: true,
        distributed: true,
        borderRadius: barPx / 2,
        borderRadiusApplication: 'end',
        barHeight: `${Math.round((barPx / rowHeight) * 100)}%`,
      },
    },
    colors: populated.map(slice => slice.color),
    dataLabels: { enabled: false },
    // Light lines between rows, as in the Sneat horizontal bar card.
    grid: {
      borderColor: theme.border,
      strokeDashArray: 0,
      xaxis: { lines: { show: false } },
      yaxis: { lines: { show: true } },
      padding: { top: -16, right: 16, bottom: 0, left: 4 },
    },
    legend: { show: false },
    series: [{
      name: 'Submissions',
      data: populated.map(slice => Number(slice.total)),
    }],
    states: {
      hover: { filter: { type: 'lighten', value: 0.05 } },
      active: { filter: { type: 'none' } },
    },
    stroke: { show: false },
    tooltip: {
      custom: documentTypeTooltip(populated, total),
    },
    xaxis: {
      // Long names wrap onto two lines instead of being cut off.
      categories: populated.map(slice => wrapLabel(slice.label, 18)),
      min: 0,
      max: axis.max,
      tickAmount: axis.ticks,
      axisBorder: { show: false },
      axisTicks: { show: false },
      labels: {
        formatter: value => Math.round(value).toLocaleString(),
        style: { fontSize: '12px', fontFamily: theme.font, colors: theme.text },
      },
    },
    yaxis: {
      // Horizontal bars take their value-axis ticks from here.
      tickAmount: axis.ticks,
      labels: {
        maxWidth: 130,
        style: { fontSize: '12px', fontWeight: 500, fontFamily: theme.font, colors: theme.text },
      },
    },
  });
}

function renderOcrPerformance(data) {
  const target = document.getElementById('dashboard-ocr-radar-chart');
  if (!target) return;

  const models = Array.isArray(data?.models) ? data.models : [];
  const active = models.find(m => m.is_active && m.has_data)
    || models.find(m => m.has_data)
    || models[0]
    || { display_label: 'TrOcr-50k-broken-samples', label: 'TrOcr-50k-broken-samples' };

  const base = models.find(m => m.key === 'base') || {};

  const charVal = Number(active?.scores?.character_accuracy ?? 92.0);
  const wordVal = Number(active?.scores?.word_accuracy ?? 76.0);
  const exactVal = Number(active?.scores?.exact_match ?? 48.0);

  const baseCharVal = Number(base?.scores?.character_accuracy ?? 88.0);
  const baseWordVal = Number(base?.scores?.word_accuracy ?? 48.5);
  const baseExactVal = Number(base?.scores?.exact_match ?? 33.2);

  const labelEl = document.getElementById('dashboard-radar-model-label');
  if (labelEl) labelEl.textContent = active.display_label || active.label || 'Fine-tuned';

  const theme = themeOptions(target);
  const pct = value => `${Math.round(value)}%`;
  // Scores ride on the axis labels (see xaxis.labels.formatter).
  const categories = ['Chars', 'Words', 'Exact'];
  const categoryNames = ['Character accuracy', 'Word accuracy', 'Exact match'];
  const modelSeries = [charVal, wordVal, exactVal];
  const baseSeries = [baseCharVal, baseWordVal, baseExactVal];

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
    colors: ['#ff6384', '#696cff'],
    dataLabels: { enabled: false },
    fill: {
      type: 'solid',
      opacity: [0.32, 0.18],
    },
    legend: { show: false },
    markers: {
      size: [4, 3],
      colors: ['#fff', '#fff'],
      strokeColors: ['#ff6384', '#696cff'],
      strokeWidth: [2, 1.5],
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
          fill: { colors: ['rgba(67, 89, 113, 0.025)', 'transparent'] },
        },
      },
    },
    series: [
      { name: active.display_label || active.label || 'Fine-tuned', data: modelSeries },
      { name: 'TrOCR Base', data: baseSeries },
    ],
    stroke: {
      width: [2, 1.5],
      dashArray: [0, 4],
    },
    tooltip: {
      custom: ({ seriesIndex, dataPointIndex }) => {
        const cat = categoryNames[dataPointIndex];
        const val = modelSeries[dataPointIndex];
        const bVal = baseSeries[dataPointIndex];
        const gain = (val - bVal).toFixed(1);
        const sign = Number(gain) >= 0 ? '+' : '';
        const activeName = active.display_label || active.label || 'Fine-tuned';

        return `<div class="dashboard-tooltip" style="min-width: 10.5rem; padding: 0.5rem 0.65rem; background: #fff; border: 1px solid rgba(67, 89, 113, 0.15); border-radius: 8px; box-shadow: 0 4px 16px rgba(34, 48, 62, 0.12);">
          <div class="fw-bold text-heading mb-1 pb-1 border-bottom small">${escapeHtml(cat)}</div>
          <div class="d-flex justify-content-between align-items-center small mb-1">
            <span><span class="badge-dot me-1" style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#ff6384;"></span>${escapeHtml(activeName)}</span>
            <strong style="color: #ff6384;">${val.toFixed(1)}%</strong>
          </div>
          <div class="d-flex justify-content-between align-items-center small mb-1">
            <span><span class="badge-dot me-1" style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#696cff;"></span>TrOCR Base</span>
            <span class="text-muted">${bVal.toFixed(1)}%</span>
          </div>
          <div class="small fw-semibold mt-1 pt-1 border-top" style="color: ${Number(gain) >= 0 ? '#71dd37' : '#ff3e1d'}; font-size: 0.6875rem;">
            ${Number(gain) >= 0 ? '▲' : '▼'} ${sign}${gain}% absolute gain
          </div>
        </div>`;
      },
    },
    xaxis: {
      categories,
      labels: {
        // Top label stays on one line (a second would run into the vertex);
        // the side labels stack name over score to stay narrow.
        formatter: (label, opts) => {
          const index = opts?.dataPointIndex ?? categories.indexOf(label);
          const score = pct(modelSeries[index] ?? 0);
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

    const chartId = panels[panel].chart;
    waiting.get(chartId)?.();
    waiting.delete(chartId);
    charts.get(chartId)?.destroy();
    charts.delete(chartId);
    // New data gets a fresh entrance, played as soon as the card is in.
    animated.delete(chartId);
    freshCard.classList.add('dashboard-panel--refreshed');
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

document.addEventListener('DOMContentLoaded', () => {
  setupPeriodFilters();
  countUp();

  const data = readChartData();
  if (!data) return;

  renderVolume(data.volume);
  renderDocumentTypes(data.documentTypes);
  renderQuality(data.quality);
  renderThroughput(data.throughput);
  renderOcrPerformance(data.ocrPerformance);

  // The radar radius and the type bars' scale follow their card's width, so
  // redraw them (without replaying the entrance) once resizing settles.
  // Re-read the data: a period change may have replaced it since load.
  let resizeTimer;
  let lastWidth = window.innerWidth;
  window.addEventListener('resize', () => {
    // Mobile browsers fire resize as the address bar slides; width is what matters.
    if (window.innerWidth === lastWidth) return;
    lastWidth = window.innerWidth;
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(() => {
      const latest = readChartData() || data;
      renderOcrPerformance(latest.ocrPerformance);
      renderDocumentTypes(latest.documentTypes);
    }, 200);
  });
});
