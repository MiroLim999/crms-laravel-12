/* ==========================================================================
   OCR Model Performance Chart & Audit Logic (Intact / Faithful to Image 5)
   Features:
   - ApexCharts Radar with concentric triangle web
   - Anchored series ensures triangle web remains retained when models are unchecked
   - Side-by-Side (Bar) toggle
   - Dynamic model switching
   ========================================================================== */

(function () {
  'use strict';

  // Model Evaluation Profiles (matching CRMS evaluation-report.json)
  const PROFILES = {
    'TrOcr-50k-broken-samples': {
      key: 'TrOcr-50k-broken-samples',
      label: 'TrOcr-50k-broken-samples',
      display_label: 'TrOcr-50k-broken-samples',
      is_base: false,
      scores: {
        character: 92.0,
        word: 76.0,
        exact: 48.0,
      },
      audit: {
        wer: 24.0,
        wer_delta: -53.4,
        cer: 8.0,
        cer_delta: -33.3,
        exact: 48.0,
        exact_delta: +14.8,
        verdict: 'Word errors cut by 53.4% vs baseline, reaching 48.0% exact lines.',
        dataset: 'synthetic-broken-handwritten-samples50k (test)',
        volume: '5,000 samples · Evaluated Oct 13, 2026',
      }
    },
    'trocr-filipino-cursive-v2': {
      key: 'trocr-filipino-cursive-v2',
      label: 'trocr-filipino-cursive-v2',
      display_label: 'trocr-filipino-cursive-v2',
      is_base: false,
      scores: {
        character: 92.8,
        word: 78.5,
        exact: 52.4,
      },
      audit: {
        wer: 21.5,
        wer_delta: -58.2,
        cer: 7.2,
        cer_delta: -40.0,
        exact: 52.4,
        exact_delta: +19.2,
        verdict: 'Superior cursive ligature recognition cutting word errors by 58.2%.',
        dataset: 'ph-vital-cursive-records-75k (test)',
        volume: '6,200 samples · Evaluated Oct 10, 2026',
      }
    },
    'trocr-damaged-birth-certs-100k': {
      key: 'trocr-damaged-birth-certs-100k',
      label: 'trocr-damaged-birth-certs-100k',
      display_label: 'trocr-damaged-birth-certs-100k',
      is_base: false,
      scores: {
        character: 90.9,
        word: 73.2,
        exact: 44.5,
      },
      audit: {
        wer: 26.8,
        wer_delta: -47.9,
        cer: 9.1,
        cer_delta: -24.2,
        exact: 44.5,
        exact_delta: +11.3,
        verdict: 'Robust noise suppression on faded and water-damaged document parchment.',
        dataset: 'severely-damaged-certificates-100k (test)',
        volume: '8,000 samples · Evaluated Sep 28, 2026',
      }
    },
    'trocr-spanish-colonial-records': {
      key: 'trocr-spanish-colonial-records',
      label: 'trocr-spanish-colonial-records',
      display_label: 'trocr-spanish-colonial-records',
      is_base: false,
      scores: {
        character: 89.5,
        word: 70.6,
        exact: 39.0,
      },
      audit: {
        wer: 29.4,
        wer_delta: -42.9,
        cer: 10.5,
        cer_delta: -12.5,
        exact: 39.0,
        exact_delta: +5.8,
        verdict: 'Optimized for 19th century colonial quill script and archaic ink bleed.',
        dataset: 'spanish-colonial-parish-records (test)',
        volume: '4,500 samples · Evaluated Aug 15, 2026',
      }
    },
    'base': {
      key: 'base',
      label: 'base',
      display_label: 'TrOCR Base (Baseline)',
      is_base: true,
      scores: {
        character: 88.0,
        word: 48.5,
        exact: 33.2,
      },
      audit: {
        wer: 51.5,
        wer_delta: 0.0,
        cer: 12.0,
        cer_delta: 0.0,
        exact: 33.2,
        exact_delta: 0.0,
        verdict: 'Pre-trained baseline model without fine-tuning weights.',
        dataset: 'synthetic-broken-handwritten-samples50k (test)',
        volume: '5,000 samples · Evaluated Oct 13, 2026',
      }
    }
  };

  const METRICS = [
    { key: 'exact', label: 'Exact match', axis: 'Exact match', color: '#696cff' },
    { key: 'character', label: 'Characters', axis: 'Characters', color: '#03c3ec' },
    { key: 'word', label: 'Words', axis: 'Words', color: '#71dd37' },
  ];

  let currentView = 'radar';
  let overlayBase = true;
  let activeProfileKey = 'TrOcr-50k-broken-samples';
  let chart = null;

  function initPerformanceChart() {
    const target = document.getElementById('ocr-model-radar');
    if (!target) return;

    const select = document.getElementById('perf-model-selector');
    const overlayToggle = document.getElementById('perf-overlay-toggle');
    const btnRadar = document.getElementById('perf-btn-radar');
    const btnBars = document.getElementById('perf-btn-bars');

    if (select) {
      select.addEventListener('change', (e) => {
        activeProfileKey = e.target.value;
        render();
      });
    }

    if (overlayToggle) {
      overlayToggle.addEventListener('change', (e) => {
        overlayBase = e.target.checked;
        render();
      });
    }

    if (btnRadar && btnBars) {
      btnRadar.addEventListener('click', () => {
        if (currentView === 'radar') return;
        currentView = 'radar';
        btnRadar.classList.add('active');
        btnBars.classList.remove('active');
        render();
      });

      btnBars.addEventListener('click', () => {
        if (currentView === 'bars') return;
        currentView = 'bars';
        btnBars.classList.add('active');
        btnRadar.classList.remove('active');
        render();
      });
    }

    render();
  }

  function updateAuditPanel(profile) {
    const p = PROFILES[profile] || PROFILES['TrOcr-50k-broken-samples'];
    const isBase = p.is_base;

    // Update top 3 metric cards
    const valChar = document.getElementById('perf-val-char');
    const valWord = document.getElementById('perf-val-word');
    const valExact = document.getElementById('perf-val-exact');
    if (valChar) valChar.textContent = p.scores.character.toFixed(1) + '%';
    if (valWord) valWord.textContent = p.scores.word.toFixed(1) + '%';
    if (valExact) valExact.textContent = p.scores.exact.toFixed(1) + '%';

    // Update Audit panel right side
    const auditModelName = document.getElementById('audit-model-name');
    if (auditModelName) auditModelName.textContent = p.display_label;

    const auditWer = document.getElementById('audit-wer-val');
    const auditCer = document.getElementById('audit-cer-val');
    const auditExact = document.getElementById('audit-exact-val');
    if (auditWer) auditWer.textContent = p.audit.wer.toFixed(1) + '%';
    if (auditCer) auditCer.textContent = p.audit.cer.toFixed(1) + '%';
    if (auditExact) auditExact.textContent = p.audit.exact.toFixed(1) + '%';

    const auditVerdict = document.getElementById('audit-verdict-text');
    if (auditVerdict) auditVerdict.textContent = p.audit.verdict;
  }

  function makeTooltipHtml(profile, baseProfile, scores, baseScores, showBaseComparison, dataPointIndex, w) {
    const collapsed = w?.globals?.collapsedSeriesIndices || [];
    const isModelVisible = !collapsed.includes(0);
    const isBaseVisible = showBaseComparison && !collapsed.includes(1);

    if (!isModelVisible && !isBaseVisible) {
      return '';
    }

    const metric = METRICS[dataPointIndex];
    const val = scores?.[dataPointIndex];
    const bVal = baseScores ? baseScores[dataPointIndex] : null;
    const diff = (val !== null && bVal !== null && val !== undefined && bVal !== undefined)
      ? val - bVal
      : null;
    const sign = diff !== null && diff >= 0 ? '+' : '';
    const relGain = (diff !== null && bVal > 0) ? ((diff / bVal) * 100).toFixed(1) : null;
    const profileLabel = profile?.display_label || profile?.label || 'Selected Model';

    return `<div class="ocr-apex-tooltip" style="padding: 0.75rem 0.9rem; background:#fff; border:1px solid rgba(67,89,113,0.15); border-radius:8px; box-shadow:0 8px 24px rgba(67,89,113,0.18); min-width:13rem;">
        <div style="font-size:0.8125rem; font-weight:700; color:#566a7f; margin-bottom:0.45rem; padding-bottom:0.35rem; border-bottom:1px solid #eceef1;">${metric.label}</div>
        ${isModelVisible ? `
        <div style="display:flex; justify-content:space-between; align-items:center; font-size:0.8125rem; margin-bottom:0.25rem;">
            <div><span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:#696cff; margin-right:6px;"></span><strong>${profileLabel}</strong></div>
            <span style="font-weight:700; color:#696cff;">${val !== null && val !== undefined ? val.toFixed(1) + '%' : '—'}</span>
        </div>` : ''}
        ${isBaseVisible && bVal !== null && bVal !== undefined ? `
        <div style="display:flex; justify-content:space-between; align-items:center; font-size:0.8125rem; margin-bottom:0.25rem;">
            <div><span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:#ff9f43; margin-right:6px;"></span><span>TrOCR Base</span></div>
            <span style="color:#8592a3;">${bVal.toFixed(1)}%</span>
        </div>` : ''}
        ${isModelVisible && isBaseVisible && diff !== null ? `
        <div style="margin-top:0.45rem; padding-top:0.35rem; border-top:1px dashed #eceef1; font-size:0.75rem; font-weight:600; display:flex; align-items:center; gap:4px; color:${diff >= 0 ? '#71dd37' : '#ff3e1d'};">
            <span>${diff >= 0 ? '▲' : '▼'} ${sign}${diff.toFixed(1)}% absolute gain ${relGain ? `(${sign}${relGain}% rel.)` : ''}</span>
        </div>` : ''}
    </div>`;
  }

  function render() {
    const target = document.getElementById('ocr-model-radar');
    if (!target) return;

    if (chart) {
      chart.destroy();
      chart = null;
    }

    const profile = PROFILES[activeProfileKey] || PROFILES['TrOcr-50k-broken-samples'];
    const baseProfile = PROFILES['base'];
    const isBase = profile.is_base;
    const showBaseComparison = overlayBase && !isBase;

    updateAuditPanel(activeProfileKey);

    const scores = [profile.scores.exact, profile.scores.character, profile.scores.word];
    const baseScores = [baseProfile.scores.exact, baseProfile.scores.character, baseProfile.scores.word];
    const categories = ['Exact match', 'Characters', 'Words'];

    const series = [{
      name: `${profile.display_label} (Fine-tuned)`,
      data: scores,
    }];

    if (showBaseComparison) {
      series.push({
        name: 'TrOCR Base (Baseline)',
        data: baseScores,
      });
    }

    const comparisonColors = ['#696cff', '#ff9f43'];

    if (currentView === 'radar') {
      // Add persistent anchor series so unchecking models keeps triangle web intact!
      const radarSeries = [
        ...series,
        {
          name: 'ocr_radar_grid_anchor',
          data: [0, 0, 0],
        }
      ];

      const options = {
        chart: {
          type: 'radar',
          height: 420,
          fontFamily: 'Public Sans, sans-serif',
          foreColor: '#566a7f',
          parentHeightOffset: 0,
          toolbar: { show: false },
          animations: {
            enabled: true,
            easing: 'easeinout',
            speed: 350,
          },
        },
        colors: showBaseComparison
          ? [...comparisonColors, 'transparent']
          : ['#696cff', 'transparent'],
        dataLabels: { enabled: false },
        fill: showBaseComparison
          ? {
              type: ['gradient', 'solid', 'solid'],
              opacity: [0.30, 0.12, 0],
              gradient: {
                type: 'horizontal',
                shadeIntensity: 0,
                inverseColors: false,
                colorStops: [
                  { offset: 0, color: '#696cff', opacity: 0.30 },
                  { offset: 50, color: '#03c3ec', opacity: 0.32 },
                  { offset: 100, color: '#71dd37', opacity: 0.30 },
                ],
              },
            }
          : {
              type: ['gradient', 'solid'],
              opacity: [0.28, 0],
              gradient: {
                type: 'horizontal',
                shadeIntensity: 0,
                inverseColors: false,
                colorStops: [
                  { offset: 0, color: '#696cff', opacity: 0.28 },
                  { offset: 50, color: '#03c3ec', opacity: 0.28 },
                  { offset: 100, color: '#71dd37', opacity: 0.25 },
                ],
              },
            },
        grid: {
          padding: { top: 12, right: 24, bottom: 8, left: 24 },
        },
        legend: {
          show: showBaseComparison,
          position: 'bottom',
          horizontalAlign: 'center',
          fontSize: '13px',
          fontWeight: 600,
          fontFamily: 'Public Sans, sans-serif',
          markers: { radius: 6, width: 10, height: 10 },
          itemMargin: { horizontal: 16, vertical: 6 },
        },
        markers: showBaseComparison
          ? {
              size: [7.5, 5.5, 0],
              strokeColors: ['#fff', '#ff9f43', 'transparent'],
              strokeWidth: [2.5, 2, 0],
              hover: { size: 9 },
            }
          : {
              size: [7, 0],
              strokeColors: ['#fff', 'transparent'],
              strokeWidth: [2.5, 0],
              hover: { size: 9 },
            },
        plotOptions: {
          radar: {
            size: 145,
            polygons: {
              strokeColors: 'rgba(67, 89, 113, 0.12)',
              strokeWidth: 1,
              connectorColors: 'rgba(67, 89, 113, 0.12)',
              fill: { colors: ['rgba(67, 89, 113, 0.03)', 'transparent'] },
            },
          },
        },
        series: radarSeries,
        stroke: showBaseComparison
          ? { width: [3.5, 2.5, 0], dashArray: [0, 5, 0] }
          : { width: [3.5, 0], dashArray: [0, 0] },
        tooltip: {
          custom: ({ dataPointIndex, w }) =>
            makeTooltipHtml(profile, baseProfile, scores, baseScores, showBaseComparison, dataPointIndex, w),
        },
        xaxis: {
          categories,
          labels: {
            style: {
              colors: ['#696cff', '#03c3ec', '#71dd37'],
              fontSize: '12px',
              fontWeight: 600,
            },
          },
        },
        yaxis: {
          show: false,
          min: 0,
          max: 100,
          tickAmount: 4,
        },
        responsive: [{
          breakpoint: 575,
          options: {
            chart: { height: 320 },
            grid: { padding: { top: 8, right: 8, bottom: 8, left: 8 } },
            markers: {
              size: showBaseComparison ? [5, 4, 0] : [5, 0],
              strokeWidth: showBaseComparison ? [2, 1.5, 0] : [2, 0],
              hover: { size: 7 },
            },
            plotOptions: { radar: { size: 105 } },
          },
        }],
      };

      chart = new ApexCharts(target, options);
    } else {
      // Grouped horizontal comparison bars
      const options = {
        chart: {
          type: 'bar',
          height: 380,
          fontFamily: 'Public Sans, sans-serif',
          foreColor: '#566a7f',
          toolbar: { show: false },
          animations: {
            enabled: true,
            easing: 'easeinout',
            speed: 350,
          },
        },
        plotOptions: {
          bar: {
            horizontal: true,
            barHeight: '52%',
            borderRadius: 6,
            dataLabels: { position: 'center' },
          },
        },
        colors: showBaseComparison ? comparisonColors : ['#696cff'],
        dataLabels: {
          enabled: true,
          style: {
            fontSize: '12px',
            fontWeight: 700,
            colors: ['#fff'],
          },
          formatter: (v) => `${Number(v).toFixed(1)}%`,
        },
        stroke: {
          show: true,
          width: 1,
          colors: ['transparent'],
        },
        grid: {
          borderColor: 'rgba(67, 89, 113, 0.12)',
          strokeDashArray: 4,
          padding: { top: 8, right: 20, bottom: 12, left: 16 },
        },
        legend: {
          show: showBaseComparison,
          position: 'bottom',
          horizontalAlign: 'center',
          fontSize: '13px',
          fontWeight: 600,
          markers: { radius: 6, width: 10, height: 10 },
          itemMargin: { horizontal: 16, vertical: 6 },
        },
        series,
        tooltip: {
          custom: ({ dataPointIndex, w }) =>
            makeTooltipHtml(profile, baseProfile, scores, baseScores, showBaseComparison, dataPointIndex, w),
        },
        xaxis: {
          categories,
          min: 0,
          max: 100,
          labels: {
            formatter: (v) => `${Math.round(v)}%`,
            style: { colors: '#566a7f', fontSize: '11px' },
          },
        },
        yaxis: {
          labels: {
            style: {
              colors: ['#696cff', '#03c3ec', '#71dd37'],
              fontSize: '13px',
              fontWeight: 600,
            },
          },
        },
      };

      chart = new ApexCharts(target, options);
    }

    chart.render();
  }

  // Auto-init on DOMContentLoaded
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initPerformanceChart);
  } else {
    initPerformanceChart();
  }

  window.crmsPerfChart = {
    render,
    PROFILES
  };
})();
