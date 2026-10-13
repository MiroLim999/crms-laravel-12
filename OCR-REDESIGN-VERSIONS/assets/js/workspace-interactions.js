/* ==========================================================================
   OCR Workspace Interactive Handlers & Toasts (Standalone Prototype)
   ========================================================================== */

(function () {
  'use strict';

  // State
  let activeModelKey = 'TrOcr-50k-broken-samples';
  let allowStaffChoice = false;

  // Toast helper
  function showToast(message, icon = 'bx-check-circle', color = '#71dd37') {
    let container = document.querySelector('.crms-toast-container');
    if (!container) {
      container = document.createElement('div');
      container.className = 'crms-toast-container';
      document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    toast.className = 'crms-toast';
    toast.style.borderLeftColor = color;
    toast.innerHTML = `
      <i class="icon-base bx ${icon}" style="color:${color}; font-size:1.25rem;"></i>
      <div>${message}</div>
    `;

    container.appendChild(toast);

    setTimeout(() => {
      toast.style.opacity = '0';
      toast.style.transform = 'translateY(10px)';
      toast.style.transition = 'all 0.25s ease';
      setTimeout(() => toast.remove(), 250);
    }, 3500);
  }

  // Model deployment promoter
  function deployModel(key, label) {
    activeModelKey = key;

    // Update V1 UI
    document.querySelectorAll('.v1-model-tile').forEach((tile) => {
      const isTarget = tile.dataset.modelKey === key;
      tile.classList.toggle('is-active-deployment', isTarget);
      const btn = tile.querySelector('.v1-deploy-btn');
      if (btn) {
        if (isTarget) {
          btn.className = 'btn btn-sm btn-label-success w-100 v1-deploy-btn';
          btn.innerHTML = '<i class="icon-base bx bx-check me-1"></i>Currently Active';
          btn.disabled = true;
        } else {
          btn.className = 'btn btn-sm btn-outline-primary w-100 v1-deploy-btn';
          btn.innerHTML = '<i class="icon-base bx bx-rocket me-1"></i>Deploy as Active';
          btn.disabled = false;
        }
      }
    });

    // Update V2 UI
    document.querySelectorAll('.v2-table tr[data-model-key]').forEach((row) => {
      const isTarget = row.dataset.modelKey === key;
      row.classList.toggle('is-active', isTarget);
      const statusBadge = row.querySelector('.v2-status-cell');
      const actionBtn = row.querySelector('.v2-action-btn');
      if (statusBadge) {
        statusBadge.innerHTML = isTarget
          ? '<span class="badge bg-label-success"><i class="icon-base bx bx-check me-1"></i>Active</span>'
          : '<span class="badge bg-label-secondary">Standby</span>';
      }
      if (actionBtn) {
        actionBtn.disabled = isTarget;
      }
    });

    const v2ActiveTitle = document.getElementById('v2-active-title');
    if (v2ActiveTitle) v2ActiveTitle.textContent = label || key;

    // Update V3 UI
    document.querySelectorAll('.v3-horizon-card[data-model-key]').forEach((card) => {
      const isTarget = card.dataset.modelKey === key;
      card.classList.toggle('is-active', isTarget);
      const actionArea = card.querySelector('.v3-action-area');
      if (actionArea) {
        actionArea.innerHTML = isTarget
          ? '<button class="btn btn-sm btn-label-success w-100" disabled><i class="icon-base bx bx-check me-1"></i>Active in Production</button>'
          : `<button class="btn btn-sm btn-outline-primary w-100 js-deploy-btn" data-key="${key}" data-label="${label}"><i class="icon-base bx bx-rocket me-1"></i>Deploy to Production</button>`;
      }
    });

    // Update V5 Fleet UI
    document.querySelectorAll('.v5-table tr[data-model-key]').forEach((row) => {
      const isTarget = row.dataset.modelKey === key;
      row.classList.toggle('is-active', isTarget);
      const statusBadge = row.querySelector('.v5-status-cell');
      const actionCell = row.querySelector('.v5-action-cell');
      if (statusBadge) {
        statusBadge.innerHTML = isTarget
          ? '<span class="badge bg-label-success"><i class="icon-base bx bx-check me-1"></i>Active Production</span>'
          : (row.dataset.tier === 'base' ? '<span class="badge bg-label-secondary">Standby Baseline</span>' : '<span class="badge bg-label-secondary">Standby</span>');
      }
      if (actionCell) {
        actionCell.innerHTML = isTarget
          ? '<button type="button" class="btn btn-sm btn-label-success" disabled>Current Active</button>'
          : `<button type="button" class="btn btn-sm btn-outline-primary js-deploy-btn" data-key="${row.dataset.modelKey}" data-label="${row.dataset.modelLabel || row.dataset.modelKey}">Deploy</button>`;
      }
    });

    const v5KpiActive = document.getElementById('v5-kpi-active-model');
    if (v5KpiActive) v5KpiActive.textContent = label || key;

    // Update Performance selector if available
    const perfSelect = document.getElementById('perf-model-selector');
    if (perfSelect) {
      perfSelect.value = key;
      perfSelect.dispatchEvent(new Event('change'));
    }

    showToast(`Model <strong>${label || key}</strong> is now the active scanning model!`, 'bx-check-circle', '#71dd37');
  }

  // Event Listeners
  document.addEventListener('DOMContentLoaded', () => {
    // Model deploy buttons
    document.addEventListener('click', (e) => {
      const deployBtn = e.target.closest('.js-deploy-btn');
      if (deployBtn) {
        const key = deployBtn.dataset.key;
        const label = deployBtn.dataset.label;
        if (key) deployModel(key, label);
      }
    });

    // Evaluate in Radar jump buttons
    document.addEventListener('click', (e) => {
      const evalBtn = e.target.closest('.js-evaluate-in-radar');
      if (evalBtn) {
        const key = evalBtn.dataset.key;
        const perfSelect = document.getElementById('perf-model-selector');
        if (perfSelect && key) {
          perfSelect.value = key;
          perfSelect.dispatchEvent(new Event('change'));
          document.querySelector('.ocr-performance-section')?.scrollIntoView({ behavior: 'smooth' });
          showToast(`Loaded <strong>${key}</strong> into performance radar preview.`, 'bx-radar', '#696cff');
        }
      }
    });

    // Live search input for models table
    document.querySelectorAll('.js-model-search-input').forEach((input) => {
      input.addEventListener('input', (e) => {
        const q = e.target.value.toLowerCase().trim();
        const rows = document.querySelectorAll('.v5-table tbody tr');
        let visibleCount = 0;
        rows.forEach((r) => {
          const text = r.textContent.toLowerCase();
          const matches = text.includes(q);
          r.style.display = matches ? '' : 'none';
          if (matches) visibleCount++;
        });
        const countSpan = document.getElementById('v5-showing-count');
        if (countSpan) countSpan.textContent = visibleCount;
      });
    });

    // Filter tier chips (All, Fine-Tuned, Baseline)
    document.querySelectorAll('.js-tier-filter-btn').forEach((btn) => {
      btn.addEventListener('click', () => {
        const tier = btn.dataset.tier;
        document.querySelectorAll('.js-tier-filter-btn').forEach((b) => {
          b.classList.remove('active', 'btn-primary');
          b.classList.add('btn-label-secondary');
        });
        btn.classList.add('active', 'btn-primary');
        btn.classList.remove('btn-label-secondary');

        const rows = document.querySelectorAll('.v5-table tbody tr');
        let visibleCount = 0;
        rows.forEach((r) => {
          if (tier === 'all' || r.dataset.tier === tier) {
            r.style.display = '';
            visibleCount++;
          } else {
            r.style.display = 'none';
          }
        });
        const countSpan = document.getElementById('v5-showing-count');
        if (countSpan) countSpan.textContent = visibleCount;
      });
    });

    // Rescan buttons
    document.querySelectorAll('.js-rescan-btn').forEach((btn) => {
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        const icon = btn.querySelector('.bx-refresh');
        if (icon) icon.classList.add('bx-spin');
        btn.disabled = true;

        setTimeout(() => {
          if (icon) icon.classList.remove('bx-spin');
          btn.disabled = false;
          showToast('Model registry synchronized. Found 5 valid models in ml/models/.', 'bx-refresh', '#696cff');
        }, 800);
      });
    });

    // Staff model choice switches
    document.querySelectorAll('.js-staff-choice-switch').forEach((sw) => {
      sw.addEventListener('change', (e) => {
        allowStaffChoice = e.target.checked;
        const msg = allowStaffChoice
          ? 'Scanning policy updated: <strong>Staff can now override</strong> approved model per document.'
          : 'Scanning policy updated: <strong>Enforced approved model only</strong> for all Staff.';
        showToast(msg, 'bx-shield-quarter', '#03c3ec');
      });
    });

    // Tab switching (V4 and V5)
    document.querySelectorAll('.v4-tab-btn').forEach((tabBtn) => {
      tabBtn.addEventListener('click', () => {
        const targetTab = tabBtn.dataset.tab;
        const container = tabBtn.closest('.v4-segmented-container');
        if (!container) return;

        container.querySelectorAll('.v4-tab-btn').forEach((b) => b.classList.remove('active'));
        container.querySelectorAll('.v4-tab-pane').forEach((p) => p.classList.remove('active'));

        tabBtn.classList.add('active');
        const pane = container.querySelector(`#v4-pane-${targetTab}`);
        if (pane) pane.classList.add('active');
      });
    });

    // Add model modal trigger simulation
    document.querySelectorAll('[data-bs-toggle="modal"]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const modal = document.getElementById('addModelModal');
        if (modal) {
          modal.style.display = 'block';
          modal.classList.add('show');
          document.body.style.overflow = 'hidden';
          let backdrop = document.querySelector('.modal-backdrop');
          if (!backdrop) {
            backdrop = document.createElement('div');
            backdrop.className = 'modal-backdrop fade show';
            document.body.appendChild(backdrop);
          }
        }
      });
    });

    // Close modal handlers
    document.querySelectorAll('[data-bs-dismiss="modal"]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const modal = document.getElementById('addModelModal');
        if (modal) {
          modal.style.display = 'none';
          modal.classList.remove('show');
          document.body.style.overflow = '';
          const backdrop = document.querySelector('.modal-backdrop');
          if (backdrop) backdrop.remove();
        }
      });
    });
  });

  window.crmsWorkspace = {
    deployModel,
    showToast
  };
})();
