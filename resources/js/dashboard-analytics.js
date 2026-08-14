import ApexCharts from 'apexcharts';

const palette = {
  primary: '#0d6efd',
  info: '#03c3ec',
  success: '#71dd37',
  warning: '#ffab00',
  danger: '#ff3e1d',
  secondary: '#8592a3',
  teal: '#20c997',
};

const chartColors = [palette.primary, palette.info, palette.warning, palette.success, palette.teal, palette.danger];

function readChartData() {
  const node = document.getElementById('dashboard-chart-data');

  if (!node) return null;

  try {
    return JSON.parse(node.textContent || '{}');
  } catch (error) {
    return null;
  }
}

function themeOptions() {
  const styles = getComputedStyle(document.documentElement);
  const text = styles.getPropertyValue('--bs-secondary-color').trim() || palette.secondary;
  const border = styles.getPropertyValue('--bs-border-color').trim() || 'rgba(67, 89, 113, .12)';
  const font = styles.getPropertyValue('--bs-body-font-family').trim() || 'Public Sans, sans-serif';

  return { text, border, font };
}

function emptyState(target, message) {
  target.innerHTML = `<div class="dashboard-chart-empty">${message}</div>`;
}

function renderVolume(data) {
  const target = document.getElementById('dashboard-volume-chart');
  if (!target || !data) return;

  const series = Array.isArray(data.series) ? data.series : [];
  const hasData = series.some(item => item.data?.some(value => Number(value) > 0));
  if (!hasData) {
    emptyState(target, 'Monthly trends will appear after records are submitted.');
    return;
  }

  const theme = themeOptions();

  new ApexCharts(target, {
    chart: {
      type: 'area',
      height: 350,
      fontFamily: theme.font,
      foreColor: theme.text,
      parentHeightOffset: 0,
      toolbar: { show: false },
      zoom: { enabled: false },
    },
    colors: chartColors,
    dataLabels: { enabled: false },
    fill: {
      type: 'gradient',
      gradient: {
        shadeIntensity: 0.2,
        opacityFrom: 0.34,
        opacityTo: 0.04,
        stops: [0, 88, 100],
      },
    },
    grid: {
      borderColor: theme.border,
      strokeDashArray: 5,
      padding: { left: 4, right: 8, bottom: 0 },
    },
    legend: {
      position: 'top',
      horizontalAlign: 'left',
      fontSize: '13px',
      markers: { size: 5 },
      itemMargin: { horizontal: 10 },
    },
    markers: { size: 0, hover: { size: 5 } },
    series,
    stroke: { curve: 'smooth', width: 2.5 },
    tooltip: {
      shared: true,
      intersect: false,
      y: { formatter: value => `${Number(value).toLocaleString()} record${Number(value) === 1 ? '' : 's'}` },
    },
    xaxis: {
      categories: data.labels || [],
      axisBorder: { show: false },
      axisTicks: { show: false },
      labels: { rotate: 0, hideOverlappingLabels: true },
      tooltip: { enabled: false },
    },
    yaxis: {
      min: 0,
      forceNiceScale: true,
      labels: { formatter: value => Math.round(value).toLocaleString() },
    },
  }).render();
}

function renderDocumentTypes(rows) {
  const target = document.getElementById('dashboard-type-chart');
  if (!target || !Array.isArray(rows)) return;

  const populated = rows.filter(row => Number(row.total) > 0);
  if (!populated.length) return;

  const theme = themeOptions();

  new ApexCharts(target, {
    chart: {
      type: 'donut',
      height: 270,
      fontFamily: theme.font,
      foreColor: theme.text,
      parentHeightOffset: 0,
    },
    colors: chartColors,
    dataLabels: { enabled: false },
    labels: populated.map(row => row.label),
    legend: { show: false },
    plotOptions: {
      pie: {
        expandOnClick: false,
        donut: {
          size: '72%',
          labels: {
            show: true,
            name: { show: true, fontSize: '13px', offsetY: 18 },
            value: {
              show: true,
              fontSize: '24px',
              fontWeight: 600,
              offsetY: -10,
              formatter: value => Number(value).toLocaleString(),
            },
            total: {
              show: true,
              label: 'Records',
              fontSize: '13px',
              formatter: chart => chart.globals.seriesTotals.reduce((sum, value) => sum + value, 0).toLocaleString(),
            },
          },
        },
      },
    },
    series: populated.map(row => Number(row.total)),
    states: { hover: { filter: { type: 'none' } } },
    stroke: { width: 4, colors: ['var(--bs-card-bg, #fff)'] },
    tooltip: {
      y: { formatter: value => `${Number(value).toLocaleString()} records` },
    },
  }).render();
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

  const theme = themeOptions();
  const confidence = confidenceAvailable ? Number(data.averageConfidence) : 0;
  const correction = correctionAvailable ? Number(data.correctionRate) : 0;

  new ApexCharts(target, {
    chart: {
      type: 'radialBar',
      height: 310,
      fontFamily: theme.font,
      foreColor: theme.text,
      parentHeightOffset: 0,
      sparkline: { enabled: true },
    },
    colors: [palette.primary, palette.warning],
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
          total: {
            show: true,
            label: 'Threshold',
            fontSize: '12px',
            formatter: () => `${Number(data.threshold).toFixed(0)}%`,
          },
        },
      },
    },
    series: [confidence, correction],
    stroke: { lineCap: 'round' },
  }).render();
}

function renderThroughput(data) {
  const target = document.getElementById('dashboard-throughput-chart');
  if (!target || !data || !Array.isArray(data.totals) || !data.totals.length) return;

  const theme = themeOptions();

  new ApexCharts(target, {
    chart: {
      type: 'bar',
      height: Math.max(250, data.totals.length * 45),
      fontFamily: theme.font,
      foreColor: theme.text,
      parentHeightOffset: 0,
      toolbar: { show: false },
    },
    colors: [palette.primary],
    dataLabels: {
      enabled: true,
      formatter: value => Number(value).toLocaleString(),
      offsetX: 7,
      style: { fontSize: '12px', fontWeight: 600, colors: [theme.text] },
    },
    grid: {
      borderColor: theme.border,
      strokeDashArray: 5,
      padding: { left: 0, right: 26, top: -12, bottom: -8 },
    },
    plotOptions: {
      bar: {
        borderRadius: 6,
        borderRadiusApplication: 'end',
        barHeight: '46%',
        horizontal: true,
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
  }).render();
}

document.addEventListener('DOMContentLoaded', () => {
  const data = readChartData();
  if (!data) return;

  renderVolume(data.volume);
  renderDocumentTypes(data.documentTypes);
  renderQuality(data.quality);
  renderThroughput(data.throughput);
});
