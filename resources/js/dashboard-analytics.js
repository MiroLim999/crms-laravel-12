import ApexCharts from 'apexcharts';

const palette = {
  primary: '#696cff',
  info: '#03c3ec',
  success: '#71dd37',
  warning: '#ffab00',
  danger: '#ff3e1d',
  secondary: '#8592a3',
  purple: '#8e5be8',
  teal: '#20c997',
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

function renderVolume(data) {
  const target = document.getElementById('dashboard-volume-chart');
  if (!target || !data) return;

  new ApexCharts(target, {
    chart: { type: 'area', height: 300, toolbar: { show: false }, zoom: { enabled: false } },
    colors: [palette.primary],
    dataLabels: { enabled: false },
    stroke: { curve: 'smooth', width: 2.5 },
    fill: {
      type: 'gradient',
      gradient: { shadeIntensity: 0.15, opacityFrom: 0.35, opacityTo: 0.04, stops: [0, 90, 100] },
    },
    series: [{ name: 'Submitted records', data: data.totals }],
    xaxis: {
      categories: data.labels,
      labels: { rotate: -35, hideOverlappingLabels: true },
      axisBorder: { show: false },
      axisTicks: { show: false },
    },
    yaxis: { min: 0, forceNiceScale: true, labels: { formatter: value => Math.round(value) } },
    grid: { borderColor: 'rgba(67, 89, 113, .10)', strokeDashArray: 4 },
    tooltip: { y: { formatter: value => `${value} record${value === 1 ? '' : 's'}` } },
  }).render();
}

function renderDocumentTypes(rows) {
  const target = document.getElementById('dashboard-type-chart');
  if (!target || !Array.isArray(rows)) return;

  const populated = rows.filter(row => Number(row.total) > 0);
  if (!populated.length) return;

  const visible = populated.slice(0, 6);
  const remaining = populated.slice(6).reduce((sum, row) => sum + Number(row.total), 0);
  if (remaining > 0) visible.push({ label: 'Other', total: remaining });

  new ApexCharts(target, {
    chart: { type: 'donut', height: 245 },
    colors: [palette.primary, palette.info, palette.success, palette.warning, palette.danger, palette.purple, palette.secondary],
    labels: visible.map(row => row.label),
    series: visible.map(row => Number(row.total)),
    dataLabels: { enabled: false },
    stroke: { width: 3, colors: ['#fff'] },
    legend: { position: 'bottom', fontSize: '12px', markers: { size: 5 } },
    plotOptions: {
      pie: {
        donut: {
          size: '68%',
          labels: {
            show: true,
            name: { show: true },
            value: { show: true, fontWeight: 600 },
            total: {
              show: true,
              label: 'Records',
              formatter: chart => chart.globals.seriesTotals.reduce((sum, value) => sum + value, 0),
            },
          },
        },
      },
    },
  }).render();
}

function renderQuality(data) {
  const target = document.getElementById('dashboard-quality-chart');
  if (!target || !data) return;

  const hasData = data.passRate !== null && data.passRate !== undefined;
  const value = hasData ? Number(data.passRate) : 0;
  const color = value >= 90 ? palette.success : value >= 75 ? palette.warning : palette.danger;

  new ApexCharts(target, {
    chart: { type: 'radialBar', height: 250, sparkline: { enabled: true } },
    colors: [hasData ? color : palette.secondary],
    series: [value],
    labels: [hasData ? `At or above ${data.threshold}%` : 'No scored fields'],
    plotOptions: {
      radialBar: {
        startAngle: -120,
        endAngle: 120,
        hollow: { size: '60%' },
        track: { background: 'rgba(133, 146, 163, .16)', strokeWidth: '100%' },
        dataLabels: {
          name: { offsetY: 64, fontSize: '12px', color: '#8592a3' },
          value: {
            offsetY: 4,
            fontSize: '30px',
            fontWeight: 600,
            formatter: score => hasData ? `${Number(score).toFixed(1)}%` : '—',
          },
        },
      },
    },
    stroke: { lineCap: 'round' },
  }).render();
}

function renderGovernance(data) {
  const target = document.getElementById('dashboard-governance-chart');
  if (!target || !data) return;

  if (!data.series?.length) {
    target.innerHTML = '<div class="dashboard-chart-empty">No audited actions in this period.</div>';
    return;
  }

  new ApexCharts(target, {
    chart: { type: 'area', height: 300, stacked: true, toolbar: { show: false }, zoom: { enabled: false } },
    colors: [palette.primary, palette.success, palette.warning, palette.info, palette.purple, palette.secondary, palette.teal, palette.danger],
    dataLabels: { enabled: false },
    stroke: { curve: 'smooth', width: 1.8 },
    fill: { type: 'solid', opacity: 0.2 },
    series: data.series,
    xaxis: {
      categories: data.labels,
      labels: { rotate: -35, hideOverlappingLabels: true },
      axisBorder: { show: false },
      axisTicks: { show: false },
    },
    yaxis: { min: 0, forceNiceScale: true, labels: { formatter: value => Math.round(value) } },
    grid: { borderColor: 'rgba(67, 89, 113, .10)', strokeDashArray: 4 },
    legend: { position: 'top', horizontalAlign: 'left', fontSize: '12px' },
  }).render();
}

function configureCustomDates() {
  const period = document.getElementById('dashboard-period');
  const from = document.getElementById('dashboard-from');
  const to = document.getElementById('dashboard-to');
  if (!period || !from || !to) return;

  const sync = () => {
    const custom = period.value === 'custom';
    from.disabled = !custom;
    to.disabled = !custom;
    from.required = custom;
    to.required = custom;
  };

  period.addEventListener('change', sync);
  sync();
}

async function loadOcrStatus() {
  const status = document.getElementById('dashboard-ocr-status');
  const detail = document.getElementById('dashboard-ocr-detail');
  const storageValue = document.getElementById('dashboard-storage-value');
  const storageDetail = document.getElementById('dashboard-storage-detail');
  const url = status?.dataset.statusUrl;
  if (!status || !detail || !url) return;

  try {
    const response = await fetch(url, {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
    });
    if (!response.ok) throw new Error(`HTTP ${response.status}`);

    const payload = await response.json();
    const engine = payload.engine;
    status.textContent = engine.reachable ? 'Online' : 'Offline';
    status.classList.add(engine.reachable ? 'text-success' : 'text-danger');
    detail.textContent = engine.reachable
      ? `${engine.device || 'Device unavailable'} · checked just now`
      : (engine.error || 'OCR service is unreachable');

    if (storageValue && storageDetail) {
      if (payload.scan_storage?.available) {
        storageValue.textContent = formatBytes(Number(payload.scan_storage.bytes || 0));
        storageDetail.textContent = `${Number(payload.scan_storage.files || 0).toLocaleString()} original scan file${Number(payload.scan_storage.files || 0) === 1 ? '' : 's'}`;
      } else {
        storageValue.textContent = 'Unavailable';
        storageDetail.textContent = 'The scan directory could not be measured';
      }
    }
  } catch (error) {
    status.textContent = 'Status unavailable';
    status.classList.add('text-warning');
    detail.textContent = 'Open OCR Workspace to investigate';
    if (storageValue && storageDetail) {
      storageValue.textContent = 'Status unavailable';
      storageDetail.textContent = 'Refresh to try again';
    }
  }
}

function formatBytes(bytes) {
  if (!Number.isFinite(bytes) || bytes <= 0) return '0 B';

  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  const unit = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
  const value = bytes / (1024 ** unit);

  return `${value.toLocaleString(undefined, { maximumFractionDigits: unit === 0 ? 0 : 1 })} ${units[unit]}`;
}

document.addEventListener('DOMContentLoaded', () => {
  const data = readChartData();
  configureCustomDates();
  loadOcrStatus();

  if (!data) return;
  renderVolume(data.volume);
  renderDocumentTypes(data.documentTypes);
  renderQuality(data.quality);
  renderGovernance(data.governance);
});
