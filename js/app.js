console.log('✅ app.js loaded');
document.addEventListener('DOMContentLoaded', () => {
  console.log('Toggle button:', document.getElementById('sidebarSlideToggle'));
  console.log('Sidebar:', document.querySelector('.sidebar'));
});

// js/app.js
import * as api from './api.js';
// modal delete
 const appConfirmModal = document.getElementById('appConfirmModal');
const appConfirmTitle = document.getElementById('appConfirmTitle');
const appConfirmMessage = document.getElementById('appConfirmMessage');
const appConfirmOk = document.getElementById('appConfirmOk');
const appConfirmCancel = document.getElementById('appConfirmCancel');

let confirmResolver = null;

function appConfirm(message, title = 'Confirm') {
  appConfirmTitle.textContent = title;
  appConfirmMessage.textContent = message;
  appConfirmModal.classList.remove('hidden');

  return new Promise((resolve) => {
    confirmResolver = resolve;
  });
}

function closeConfirm(result) {
  appConfirmModal.classList.add('hidden');
  if (confirmResolver) {
    confirmResolver(result);
    confirmResolver = null;
  }
}

appConfirmOk.addEventListener('click', () => closeConfirm(true));
appConfirmCancel.addEventListener('click', () => closeConfirm(false));

appConfirmModal.addEventListener('click', (e) => {
  if (e.target === appConfirmModal) closeConfirm(false);
});


//modal alert
const appAlertModal = document.getElementById('appAlertModal');
const appAlertTitle = document.getElementById('appAlertTitle');
const appAlertMessage = document.getElementById('appAlertMessage');
const appAlertOk = document.getElementById('appAlertOk');

function appAlert(message, title = 'Notice') {
  appAlertTitle.textContent = title;
  appAlertMessage.textContent = message;
  appAlertModal.classList.remove('hidden');
}

function closeAppAlert() {
  appAlertModal.classList.add('hidden');
}
appAlertOk.addEventListener('click', closeAppAlert);

// click outside
appAlertModal.addEventListener('click', (e) => {
  if (e.target === appAlertModal) closeAppAlert();
});


// --------- Simple helpers (kept inside app.js for simplicity) ----------
function formatMoney(amount) {
  if (amount == null || isNaN(Number(amount))) return '$0.00';
  return '$' + Number(amount).toFixed(2);
}
function ensurePegHistoryChartVisible() {
  if (pegHistoryChart) {
    pegHistoryChart.resize();
    pegHistoryChart.update();
  }
}
function computePegFromPoints(points = []) {
  if (!points || points.length === 0) {
    return { labels: [], prices: [], weightsPercent: [], suggested: 0, rawAvg: 0 };
  }

  let weightedSum = 0;
  let totalWeight = 0;
  let rawSum = 0;

  // determine if weights are provided
  let noWeights = true;
  for (const p of points) {
    if (Number(p.weight) > 0) { noWeights = false; break; }
  }

  for (const p of points) {
    const price = Number(p.price) || 0;
    const weight = noWeights ? 1 : (Number(p.weight) || 0);
    weightedSum += price * weight;
    totalWeight += weight;
    rawSum += price;
  }

  const suggested = totalWeight > 0 ? weightedSum / totalWeight : 0;
  const rawAvg = points.length > 0 ? rawSum / points.length : 0;

  const labels = points.map((p, i) => p.label || `Point ${i + 1}`);
  const prices = points.map(p => Number(p.price) || 0);
  const weightsPercent = points.map(p => {
    const w = noWeights ? 1 : (Number(p.weight) || 0);
    return totalWeight === 0 ? 0 : (w / totalWeight) * 100;
  });

  return { labels, prices, weightsPercent, suggested, rawAvg };
}

function computeBandPrices(adjustedSalePrice, inventoryMode) {
  let lowMultiplier = 0.65;
  let highMultiplier = 0.75;
  switch (inventoryMode) {
    case 'overstocked':
      lowMultiplier = 0.55; highMultiplier = 0.65; break;
    case 'low':
      lowMultiplier = 0.70; highMultiplier = 0.80; break;
    case 'critical':
      lowMultiplier = 0.75; highMultiplier = 0.85; break;
    default:
      break;
  }
  return { low: adjustedSalePrice * lowMultiplier, high: adjustedSalePrice * highMultiplier };
}

// --------- DOM refs ----------
const capacityListEl = document.getElementById('capacityList');
const salesChartEl = document.getElementById('salesChart');
const pegChartEl = document.getElementById('pegChart');
const pegHistoryChartEl = document.getElementById('pegHistoryChart');

const salesTableBody = document.getElementById('salesTableBody');
const pegTableBody = document.getElementById('pegTableBody');
const modifierTableBody = document.getElementById('modifierTableBody');
const pegHistoryTableBody = document.getElementById('pegHistoryTableBody');

const interfaceSelect = document.getElementById('interfaceSelect');
const conditionSelect = document.getElementById('conditionSelect');
const inventoryModeSelect = document.getElementById('inventoryMode');

const addRowBtn = document.getElementById('addRowBtn');
const addModifierBtn = document.getElementById('addModifierBtn');
const addNewCapacityBtn = document.getElementById('addNewCapacityBtn');
const newCapacityInput = document.getElementById('newCapacityInput');

const savePegBtn = document.getElementById('savePegBtn');

const historyRangeSelect = document.getElementById('historyRangeSelect');

const salesChartTitle = document.getElementById('salesChartTitle');
const pegChartTitle = document.getElementById('pegChartTitle');
const pegHistoryTitle = document.getElementById('pegHistoryTitle');

const pegHistoryLabelEl = document.getElementById('pegHistoryLabel');
const pegHistoryChannelEl = document.getElementById('pegHistoryChannel');
const pegHistoryLinkEl = document.getElementById('pegHistoryLink');

const summaryBasePeg = document.getElementById('summaryBasePeg');
const summarySuggested = document.getElementById('summarySuggested');
const summaryRawAvg = document.getElementById('summaryRawAvg');
const summaryModifiers = document.getElementById('summaryModifiers');
const summaryLow = document.getElementById('summaryLow');
const summaryHigh = document.getElementById('summaryHigh');

const pegDataHistoryCard = document.getElementById('pegDataHistoryCard');
const mainEditorLayout = document.getElementById('mainEditorLayout');
const historyCardSubtitle = document.getElementById('historyCardSubtitle');
const pegNameContainer = document.getElementById('pegNameContainer');
const ALL_INTERFACES = ["sata", "sas"];
const ALL_CONDITIONS = ["new", "used", "recertified"];

// --------- State ----------
let capacities = [];
let currentCapacity = null;
let currentInterfaceKey = interfaceSelect.value || 'sata';
let currentConditionKey = conditionSelect.value || 'new';
let isCreatingNewConfig = false;
let salesChart = null;
let pegChart = null;
let pegHistoryChart = null;
let activePegPointIndex = null;

let pegDataState = {}; // structure: pegDataState[capacity] = { points:[], modifiers:[], sales:[], inventoryMode: 'balanced', config_id }
let pegHistoryByCapacity = {}; // will be populated by server-side history endpoint if implemented

function allInterfaceConditionCombosExist(capacity) {
  const history = pegHistoryByCapacity[capacity] || [];

  // Build a set like: "sata|new", "sas|used"
  const existingCombos = new Set(
    history.map(h =>
      `${String(h.interface).toLowerCase()}|${String(h.condition_type).toLowerCase()}`
    )
  );

  // Check every possible combination
  for (const iface of ALL_INTERFACES) {
    for (const cond of ALL_CONDITIONS) {
      const key = `${iface}|${cond}`;
      if (!existingCombos.has(key)) {
        return false; // ❗ at least one slot still available
      }
    }
  }

  return true; // 🔒 all 6 exist
}




function getCurrentPegBlock() {
  if (!currentCapacity) return null;

  if (!pegDataState[currentCapacity]) {
    pegDataState[currentCapacity] = {
      points: [],
      modifiers: [],
      sales: defaultSalesData(),
      inventoryMode: 'balanced',
      config_id: window.currentConfigId ?? null
    };
  }

  return pegDataState[currentCapacity];
}
// --------- Chart creation ----------
function createSalesChart(initialData) {
  const ctx = salesChartEl.getContext('2d');
  return new Chart(ctx, {
    type: 'bar',
    data: {
      labels: initialData.labels,
      datasets: [
        {
          type: 'bar',
          label: 'Units sold',
          data: initialData.volume,
          backgroundColor: '#6b728080',
          yAxisID: 'yVolume'
        },
        {
          type: 'line',
          label: 'Your sale price',
          data: initialData.salePrice,
          borderColor: '#2563eb',
          borderWidth: 2,
          pointRadius: 3,
          tension: 0.25,
          yAxisID: 'yPrice'
        },
        {
          type: 'line',
          label: 'Online average',
          data: initialData.marketPrice,
          borderColor: '#f97316',
          borderWidth: 2,
          pointRadius: 3,
          borderDash: [4, 3],
          tension: 0.25,
          yAxisID: 'yPrice'
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          mode: 'index',
          intersect: false,
          callbacks: {
            label: (ctx) => {
              if (ctx.dataset.label === 'Units sold') return `${ctx.dataset.label}: ${ctx.formattedValue} pcs`;
              return `${ctx.dataset.label}: $${ctx.formattedValue}`;
            }
          }
        }
      },
      scales: {
        yPrice: {
          type: 'linear',
          position: 'left',
          title: { display: true, text: 'Price (USD)' },
          suggestedMin: 0,
          suggestedMax: 100
        },
        yVolume: {
          type: 'linear',
          position: 'right',
          title: { display: true, text: 'Units sold' },
          beginAtZero: true
        }
      }
    }
  });
}

function createPegChart(initialPeg) {
  const ctx = pegChartEl.getContext('2d');

  return new Chart(ctx, {
    type: 'bar',
    data: {
      labels: initialPeg.labels,
      datasets: [
        {
          type: 'bar',
          label: 'Weight (%)',
          data: initialPeg.weightsPercent,
          backgroundColor: '#6b728080',
          yAxisID: 'yWeight'
        },
        {
          type: 'line',
          label: 'Point price',
          data: initialPeg.prices,
          borderColor: '#2563eb',
          borderWidth: 2,
          pointRadius: 6,
          pointHoverRadius: 8,
          tension: 0.25,
          yAxisID: 'yPrice'
        },
        {
          type: 'line',
          label: 'Base peg',
          data: initialPeg.labels.map(() => initialPeg.suggested || 0),
          borderColor: '#f97316',
          borderWidth: 2,
          pointRadius: 0,
          borderDash: [4, 3],
          tension: 0.25,
          yAxisID: 'yPrice'
        }
      ]
    },

    options: {
      responsive: true,
      maintainAspectRatio: false,

      plugins: {
        legend: { display: false },
        tooltip: {
          mode: 'index',
          intersect: false,
          callbacks: {
            label: (ctx) => {
              if (ctx.dataset.label === 'Weight (%)') {
                return `${ctx.dataset.label}: ${ctx.formattedValue}%`;
              }
              return `${ctx.dataset.label}: $${ctx.formattedValue}`;
            }
          }
        }
      },

      onClick: (evt) => {
  if (!currentCapacity) return;

  const elements = pegChart.getElementsAtEventForMode(
    evt,
    'nearest',
    { intersect: false },
    true
  );

  if (!elements.length) return;

  const el = elements.find(e => e.datasetIndex === 1) || elements[0];
  const idx = el.index;

  const block = getCurrentPegBlock();
  if (!block || !block.points || !block.points[idx]) {
    console.warn('Clicked index has no matching peg point:', idx);
    return;
  }

  //SET ACTIVE INDEX
  activePegPointIndex = idx;

  console.log('Clicked point object:', block.points[idx]);

  showPegHistoryFromDatabase(idx);

  // highlight table row
  document.querySelectorAll('#pegTableBody tr').forEach(r => r.classList.remove('active'));
  const row = document.querySelector(`#pegTableBody tr[data-index="${idx}"]`);
  if (row) row.classList.add('active');
},

      scales: {
        yPrice: {
          type: 'linear',
          position: 'left',
          title: { display: true, text: 'Price (USD)' },
          suggestedMin: 0,
          suggestedMax: 100
        },
        yWeight: {
          type: 'linear',
          position: 'right',
          title: { display: true, text: 'Weight (%)' },
          beginAtZero: true,
          suggestedMax: 100
        }
      }
    }
  });
}


function createPegHistoryChart() {
  const ctx = pegHistoryChartEl.getContext('2d');
  return new Chart(ctx, {
    type: 'line',
    data: { labels: [], datasets: [{ label: 'Peg price', data: [], borderColor: '#2563eb', borderWidth: 2, pointRadius: 3, tension: 0.25 }] },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false }, tooltip: { callbacks: { label: (ctx) => `$${ctx.formattedValue}` } } },
      scales: { y: { title: { display: true, text: 'Price (USD)' } }, x: { title: { display: true, text: 'Day' } } }
    }
  });
}
function capacityToNumber(cap) {
  if (!cap) return 0;

  const s = String(cap).toUpperCase();
  const num = parseFloat(s.replace(/[^0-9.]/g, '')) || 0;

  // Normalize everything to GB for proper sorting
  if (s.includes('TB')) return num * 1024;
  if (s.includes('GB')) return num;

  // Fallback (assume GB)
  return num;
}

// --------- Rendering UI ----------
function renderCapacityButtons() {
  capacityListEl.innerHTML = '';

  if (!capacities || capacities.length === 0) {
    capacityListEl.innerHTML =
      `<span style="color: #9ca3af; font-size: 13px;">No capacities found.</span>`;
    return;
  }

  // ✅ SORT LOW → HIGH (GB-normalized)
  [...capacities]
    .sort((a, b) => capacityToNumber(a) - capacityToNumber(b))
    .forEach(cap => {
      const btn = document.createElement('button');
      btn.className = 'capacity-btn';
      btn.id = `cap-btn-${cap}`;
      btn.dataset.capacity = cap;

      // calculate status using current in-memory peg (if any)
      let status = 'N/A';
      try {
        const peg = pegDataState[cap]?.points
          ? computePegFromPoints(pegDataState[cap].points)
          : { suggested: 0, rawAvg: 0 };

        const modifierTotal =
          (pegDataState[cap]?.modifiers || [])
            .reduce((s, m) => s + (Number(m.amount) || 0), 0);

        const adj = Number(peg.suggested || 0) + modifierTotal;
        status = formatMoney(adj);

        if (peg.suggested > peg.rawAvg) status += ' (High)';
        else if (peg.suggested < peg.rawAvg) status += ' (Low)';
        else status += ' (Avg)';
      } catch (e) {
        console.warn('Status calc failed for', cap, e);
      }

      btn.innerHTML =
        `<span class="label">${cap}</span><span class="meta">${status}</span>`;

      btn.addEventListener('click', () => fetchAndSelectPeg(cap));
      capacityListEl.appendChild(btn);
    });
}


function renderSalesTable(cap) {
  salesTableBody.innerHTML = '';
  const data = (pegDataState[cap] && pegDataState[cap].sales && pegDataState[cap].sales.length)
    ? pegDataState[cap].sales
    : defaultSalesData();
  data.forEach((row, index) => {
    const tr = document.createElement('tr');
    tr.dataset.index = index;
    tr.innerHTML = `
      <td>${row.day_label ?? (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'][index] || `Day ${index+1}`)}</td>
      <td><input type="number" step="0.01" data-field="salePrice" value="${row.sale_price ?? ''}"></td>
      <td><input type="number" step="0.01" data-field="marketPrice" value="${row.market_price ?? ''}"></td>
      <td><input type="number" step="1" data-field="volume" value="${row.volume ?? ''}"></td>
    `;
    salesTableBody.appendChild(tr);
  });
}

function defaultSalesData() {
  const labels = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
  return labels.map(l => ({ day_label: l, sale_price: 0, market_price: 0, volume: 0 }));
}

function renderPegTable(cap, iface, cond) {
  pegTableBody.innerHTML = '';
  const points = (pegDataState[cap] && pegDataState[cap].points) ? pegDataState[cap].points : [];
  points.forEach((p, idx) => {
    const tr = document.createElement('tr');
    tr.dataset.index = idx;
    tr.className = 'clickable-peg-row';
    tr.innerHTML = `
      <td><input type="text" data-field="label" value="${escapeHtml(p.label ?? '')}"></td>
      <td><input type="text" data-field="channel" value="${escapeHtml(p.channel ?? '')}"></td>
      <td><input type="url" data-field="url" value="${escapeHtml(p.url ?? '')}"></td>
      <td><input type="number" step="0.01" data-field="price" value="${p.price == 0 ? '' : (p.price ?? '')}"></td>
      <td><input type="number" step="0.01" min="0" max="1" data-field="weight" value="${p.weight == 0 ? '' : (p.weight ?? '')}"></td>
      <td class="row-actions" style="text-align:center;"><button data-action="deleteRow" title="Delete peg">X</button></td>
    `;
    pegTableBody.appendChild(tr);

    // row click shows history for that point
tr.addEventListener('click', (e) => {
  if (e.target.closest('button')) return;

  const block = getCurrentPegBlock();
  if (!block || !block.points || !block.points[idx]) {
    console.warn('Row click but no peg point:', idx);
    return;
  }

  activePegPointIndex = idx;

  console.log('Clicked point object:', block.points[idx]);

  showPegHistoryFromDatabase(idx);

  document.querySelectorAll('#pegTableBody tr').forEach(r => r.classList.remove('active'));
  tr.classList.add('active');
});
  });
}

function renderModifierTable(cap) {
  modifierTableBody.innerHTML = '';
  const modifiers = (pegDataState[cap] && pegDataState[cap].modifiers) ? pegDataState[cap].modifiers : [];
  modifiers.forEach((m, idx) => {
    const tr = document.createElement('tr');
    tr.dataset.index = idx;
    tr.innerHTML = `
      <td><input type="text" data-field="label" value="${escapeHtml(m.label ?? '')}"></td>
      <td><input type="number" step="0.01" data-field="amount" value="${m.amount == 0 ? '' : (m.amount ?? '')}"></td>
      <td class="row-actions" style="text-align:center;"><button data-action="deleteModifier" title="Delete modifier">X</button></td>
    `;
    modifierTableBody.appendChild(tr);
  });
}

function renderPegHistoryTable(cap) {
    pegHistoryTableBody.innerHTML = '';
    const history = pegHistoryByCapacity[cap] || [];

    if (!history.length) {
        pegHistoryTableBody.innerHTML =
            `<tr><td colspan="5" style="text-align:center; color: var(--text-muted);">No history available.</td></tr>`;
        return;
    }

    history.forEach((h, idx) => {
        const tr = document.createElement('tr');

        tr.innerHTML = `
        <td style="text-align:center; justify-items: center;">
                <button class="peg-add-row" data-action="viewHistory" data-index="${idx}">View</button>
            </td>
            <td>${h.saved_at || '-'}</td>

            <td>${h.peg_name ? escapeHtml(h.peg_name) : '<span style="color:#9ca3af;">(No name)</span>'}</td>

            <td>${formatMoney(h.base_price)}
                (${(h.interface?.toUpperCase?.() ?? '')} / ${capitalize(h.condition_type ?? '')})
            </td>

            <td>${formatMoney(h.adjusted_price)}</td>

            <td style="text-align:center; justify-items: center;">
                <button class="peg-delete-row" data-action="deleteHistory" data-index="${idx}">Delete</button>
            </td>
        `;

        pegHistoryTableBody.appendChild(tr);
    });
}


function updateSummary(cap) {
  if (!cap) {
    summaryBasePeg.textContent = '$0.00';
    summarySuggested.textContent = '$0.00';
    summaryRawAvg.textContent = '$0.00';
    summaryModifiers.textContent = '$0.00';
    summaryLow.textContent = '$0.00';
    summaryHigh.textContent = '$0.00';
    return;
  }
  const points = pegDataState[cap]?.points || [];
  const { suggested, rawAvg } = computePegFromPoints(points);
  const modifierTotal = (pegDataState[cap]?.modifiers || []).reduce((s, m) => s + (Number(m.amount) || 0), 0);
  const adjustedSalePrice = suggested + modifierTotal;
  const mode = pegDataState[cap]?.inventoryMode || 'balanced';
  const band = computeBandPrices(adjustedSalePrice, mode);

  summaryBasePeg.textContent = formatMoney(suggested);
  summarySuggested.textContent = formatMoney(adjustedSalePrice);
  summaryRawAvg.textContent = formatMoney(rawAvg);
  summaryModifiers.textContent = formatMoney(modifierTotal);
  summaryLow.textContent = formatMoney(band.low);
  summaryHigh.textContent = formatMoney(band.high);
}

// --------- Utilities ----------
function escapeHtml(s) {
  if (!s) return '';
  return String(s).replace(/[&<>"'\/]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;', '/': '&#47;' }[c]));
}
function capitalize(s) { if (!s) return ''; return String(s).charAt(0).toUpperCase() + String(s).slice(1); }

// --------- Peg history / point history display ---------- //


function showPegHistory(index) {
  if (!currentCapacity) return;
  const point = (pegDataState[currentCapacity]?.points || [])[index];
  updatePegHistoryChartWithPoint(point);
}

// --------- Data loading & sync ----------
async function loadCapacities() {
  try {
    capacities = await api.fetchCapacities();
    // ensure array of unique strings
    capacities = Array.from(new Set(capacities || []));
    renderCapacityButtons();
  } catch (err) {
    console.error(err);
    capacityListEl.innerHTML = `<span style="color: #f87171; font-size: 13px;">Error: ${err.message}</span>`;
  }
}

/**
 * Fetch peg data from the server for specific capacity/interface/condition
 * Stores into pegDataState[capacity] and refreshes UI
 */
async function fetchPegDataFor(cap, iface, cond) {
  if (!cap) return;
  try {
    const data = await api.fetchPegData(cap, iface, cond);
    // If server says not_found, initialize empty structure
    if (!data || data.status === 'not_found') {
      pegDataState[cap] = { points: [], modifiers: [], sales: defaultSalesData(), inventoryMode: 'balanced', config_id: null };
    } else if (data.status === 'success') {
      // Normalize data into state shape
      const peg = data.peg || {};
     const points = (peg.points || []).map(p => ({
  id: p.id ?? null,   // VERY IMPORTANT
  label: p.label ?? '',
  channel: p.channel ?? '',
  url: p.url ?? '',
  price: Number(p.price) || 0,
  weight: Number(p.weight) || 0
}));

      const modifiers = (peg.modifiers || []).map(m => ({ id: m.id ?? null, label: m.label ?? '', amount: Number(m.amount) || 0 }));
      const sales = (peg.sales && peg.sales.length) ? peg.sales.map(s => ({
        day_label: s.day_label ?? s.dayLabel ?? '',
        sale_price: Number(s.sale_price ?? s.salePrice ?? 0) || 0,
        market_price: Number(s.market_price ?? s.marketPrice ?? 0) || 0,
        volume: Number(s.volume ?? 0) || 0
      })) : defaultSalesData();

      pegDataState[cap] = {
  points,
  modifiers,
  sales,
  inventoryMode: data.inventoryMode || 'balanced',
  config_id: window.currentConfigId ?? data.config_id ?? null
};
      pegNameContainer.style.display = 'flex';
      document.getElementById("pegNameInput").value = data.peg_name || "";
    } else {
      // unexpected response
      pegDataState[cap] = { points: [], modifiers: [], sales: defaultSalesData(), inventoryMode: 'balanced', config_id: null };
    }

    // refresh UI for selected capacity
    if (cap === currentCapacity) {
      refreshUI(cap, iface, cond);
    } else {
      renderCapacityButtons();
    }
  } catch (err) {
    console.error('Error fetching peg data:', err);
    // fallback to empty
    pegDataState[cap] = { points: [], modifiers: [], sales: defaultSalesData(), inventoryMode: 'balanced', config_id: null };
    if (cap === currentCapacity) refreshUI(cap, iface, cond);
  }
}

// --------- Save
async function saveCurrentPegData() {
  if (!currentCapacity) {
    appAlert("Select a capacity first.");
    return;
  }

  const state = pegDataState[currentCapacity];
  if (!state) return;

  // ALWAYS resolve by capacity + interface + condition
  const resolvedConfigId = findConfigIdByCombo(
    currentCapacity,
    currentInterfaceKey,
    currentConditionKey
  );

  const payload = {
    config_id: resolvedConfigId, // ✅ null = create, number = update
    capacity: currentCapacity,
    interface: currentInterfaceKey,
    condition: currentConditionKey,
    peg_name: document.getElementById("pegNameInput").value || null,
    peg: {
      points: state.points,
      modifiers: state.modifiers,
      sales: state.sales
    },
    inventoryMode: state.inventoryMode
  };

  try {
    savePegBtn.disabled = true;

    const res = await api.savePeg(payload);

    if (res.status === "success") {
      appAlert(resolvedConfigId ? "Configuration updated." : "New configuration created.");
    await fetchPegDataFor(
    currentCapacity,
    currentInterfaceKey,
    currentConditionKey
  );

      // reload history
      const result = await api.loadHistory(currentCapacity);
  pegHistoryByCapacity[currentCapacity] = result.history || [];

  refreshUI(currentCapacity, currentInterfaceKey, currentConditionKey);
    } else {
      throw new Error(res.message);
    }
  } catch (err) {
   appAlert("Save failed: " + err.message);
  } finally {
    savePegBtn.disabled = false;
  }
}



// --------- UI interactions and listeners ----------
async function fetchAndSelectPeg(capacityKey) {
    hideEditorOnMobile();
    showChartsState();
  document.querySelectorAll('.capacity-btn').forEach(b => b.classList.remove('active'));

  const btn = document.getElementById(`cap-btn-${capacityKey}`);
  if (btn) btn.classList.add('active');

  currentCapacity = capacityKey;
// Auto-collapse sidebar after selection (mobile + desktop)
const sidebar = document.querySelector('.sidebar');

// Auto-collapse ONLY on mobile
if (sidebar && window.innerWidth <= 768) {
  sidebar.classList.add('collapsed');
}

  mainEditorLayout.style.display = 'none';
  pegDataHistoryCard.style.display = 'block';
  savePegBtn.style.display = 'none';

  salesChartTitle.textContent = `${capacityKey} Selected`;
  historyCardSubtitle.textContent = `Past configurations for ${capacityKey}. Select one to load the editor.`;

  // load history from API
  const result = await api.loadHistory(capacityKey);
pegHistoryByCapacity[capacityKey] = result.history || [];
pegNameInput.value = "";
pegNameContainer.style.display = 'none';
  renderPegHistoryTable(capacityKey);
}


async function loadSelectedHistory(capacityKey, historyIndex) {
    showEditor();
    const history = pegHistoryByCapacity[capacityKey] || [];
    const selected = history[historyIndex];
    if (!selected) return;

    currentCapacity = capacityKey;
    currentInterfaceKey = selected.interface;
    currentConditionKey = selected.condition_type;

    // ✅ THIS IS THE KEY
    window.currentConfigId = Number(selected.config_id);
    window.originalInterface = selected.interface;
    window.originalCondition = selected.condition_type;

    document.getElementById("pegNameInput").value = selected.peg_name || "";

    await fetchPegDataFor(
        capacityKey,
        currentInterfaceKey,
        currentConditionKey
    );

    mainEditorLayout.style.display = 'grid';
    pegDataHistoryCard.style.display = 'none';
    savePegBtn.style.display = 'inline-block';

    refreshUI(capacityKey, currentInterfaceKey, currentConditionKey);
ensurePegHistoryChartVisible();
}




// Event handlers for table input change
pegTableBody.addEventListener('input', (e) => {
  if (!currentCapacity) return;
  const input = e.target;
  const field = input.dataset.field;
  if (!field) return;
  const row = input.closest('tr');
  if (!row) return;
  const idx = Number(row.dataset.index);
  const points = pegDataState[currentCapacity]?.points || [];
  if (!points[idx]) return;

  let val = input.value;
  if (field === 'price' || field === 'weight') val = val === '' ? 0 : Number(val);
  points[idx][field] = val;

  if (field === 'price') {
    // ensure a history placeholder exists for front-end charting if server didn't provide it
    points[idx].history = points[idx].history && points[idx].history.length ? points[idx].history : generateSimpleHistory(Number(points[idx].price || 0));
    const activeIdx = document.querySelector('#pegTableBody tr.active')?.dataset.index;
    if (activeIdx !== undefined && Number(activeIdx) === idx) showPegHistoryFromDatabase(idx);
  }

  updatePegChart(currentCapacity, currentInterfaceKey, currentConditionKey);
  updateSummaryUI(currentCapacity);
  renderCapacityButtons();
});

pegTableBody.addEventListener('click', (e) => {
  if (e.target.dataset.action === 'deleteRow') {
    if (!currentCapacity) return;
    const row = e.target.closest('tr');
    const idx = Number(row.dataset.index);
    const arr = pegDataState[currentCapacity]?.points;
    if (!arr) return;
    arr.splice(idx, 1);
    refreshUI(currentCapacity, currentInterfaceKey, currentConditionKey);
  }
});

modifierTableBody.addEventListener('input', (e) => {
  if (!currentCapacity) return;
  const input = e.target;
  const field = input.dataset.field;
  if (!field) return;
  const row = input.closest('tr');
  const idx = Number(row.dataset.index);
  const arr = pegDataState[currentCapacity]?.modifiers || [];
  if (!arr[idx]) return;
  let val = input.value;
  if (field === 'amount') val = val === '' ? 0 : Number(val);
  arr[idx][field] = val;
  updateSummaryUI(currentCapacity);
  renderCapacityButtons();
});

modifierTableBody.addEventListener('click', (e) => {
  if (e.target.dataset.action === 'deleteModifier') {
    if (!currentCapacity) return;
    const row = e.target.closest('tr');
    const idx = Number(row.dataset.index);
    const arr = pegDataState[currentCapacity]?.modifiers || [];
    arr.splice(idx, 1);
    refreshUI(currentCapacity, currentInterfaceKey, currentConditionKey);
  }
});

salesTableBody.addEventListener('input', (e) => {
  if (!currentCapacity) return;
  const input = e.target;
  const field = input.dataset.field;
  if (!field) return;
  const row = input.closest('tr');
  if (!row) return;
  const idx = Number(row.dataset.index);
  if (!pegDataState[currentCapacity]) pegDataState[currentCapacity] = { points: [], modifiers: [], sales: defaultSalesData(), inventoryMode: 'balanced', config_id: null };
  if (!pegDataState[currentCapacity].sales[idx]) pegDataState[currentCapacity].sales[idx] = { day_label: row.children[0].textContent, sale_price: 0, market_price: 0, volume: 0 };
  let val = input.value === '' ? 0 : Number(input.value);
  pegDataState[currentCapacity].sales[idx][field === 'salePrice' ? 'sale_price' : field === 'marketPrice' ? 'market_price' : 'volume'] = val;
  updateSalesChart(currentCapacity);
});

// add row & modifier buttons
addRowBtn.addEventListener('click', () => {
  const block = getCurrentPegBlock();
  const base = block.points.length ? Number(block.points[0].price) : 100;

  block.points.push({
    id: null,                 // 👈 important
    label: `Point ${block.points.length + 1}`,
    channel: '',
    url: '',
    price: base,
    weight: 0.1,
    history: generateSimpleHistory(base)
  });

  refreshUI(currentCapacity);
});

addModifierBtn.addEventListener('click', () => {
  if (!currentCapacity) return appAlert('Select a capacity or load a history first.');
  const s = pegDataState[currentCapacity] = pegDataState[currentCapacity] || { points: [], modifiers: [], sales: defaultSalesData(), inventoryMode: 'balanced', config_id: null };
  s.modifiers.push({ label: `Modifier ${s.modifiers.length + 1}`, amount: 0 });
  refreshUI(currentCapacity, currentInterfaceKey, currentConditionKey);
});

// capacity add
addNewCapacityBtn.addEventListener('click', async () => {

  const newCap = (newCapacityInput.value || '').trim();
  if (!newCap) return appAlert('Please enter a capacity label (e.g., 30TB).');
  // Save to database
  const result = await api.saveCapacity(newCap);

  if (result.status === "success") {
      appAlert("Capacity added!");
      await loadCapacities(); // refresh list
      newCapacityInput.value = "";
  }
  else if (result.status === "exists") {
      appAlert("Capacity already exists in database.");
  }
  else {
      appAlert("Error: " + result.message);
  }
});

// history view button
document.getElementById('pegHistoryTableBody').addEventListener('click', async (e) => {

  // VIEW
  if (e.target.dataset.action === 'viewHistory') {
      const idx = Number(e.target.dataset.index);
      if (!currentCapacity) return;
      loadSelectedHistory(currentCapacity, idx);
      return;
  }

  // DELETE
  if (e.target.dataset.action === 'deleteHistory') {
      const idx = Number(e.target.dataset.index);
      if (!currentCapacity) return;

      const historyList = pegHistoryByCapacity[currentCapacity] || [];
      const item = historyList[idx];
      if (!item) return;

      if (!(await appConfirm(`Delete this history entry saved on ${item.saved_at}?`,"Delete History"))) return;

      const result = await api.deleteHistory(item.id);

      if (result.status === "success") {
  appAlert("History deleted.");

  // Reload history
  const res = await api.loadHistory(currentCapacity);
  pegHistoryByCapacity[currentCapacity] = res.history || [];

  // 🔑 RESET STATE AFTER DELETE
  window.currentConfigId = null;
  isCreatingNewConfig = false;

  // Optional safety reset
  window.originalInterface = null;
  window.originalCondition = null;

  renderPegHistoryTable(currentCapacity);
} else {
          appAlert("Delete failed: " + result.message);
      }
  }
});


// interface/condition change to reload data



// inventory change
inventoryModeSelect.addEventListener('change', () => {
  if (!currentCapacity) return;
  pegDataState[currentCapacity] = pegDataState[currentCapacity] || { points: [], modifiers: [], sales: defaultSalesData(), inventoryMode: 'balanced', config_id: null };
  pegDataState[currentCapacity].inventoryMode = inventoryModeSelect.value;
  updateSummaryUI(currentCapacity);
});

// history range change
historyRangeSelect.addEventListener('change', async () => {
  if (activePegPointIndex === null) return;
    showPegHistoryFromDatabase(activePegPointIndex);
});
// save
savePegBtn.addEventListener('click', saveCurrentPegData);

// peg table delete event handled earlier via pegTableBody click

// --------- Chart update helpers ----------
function updateSalesChart(cap) {
  const data = pegDataState[cap]?.sales || defaultSalesData();
  const labels = data.map(r => r.day_label || '');
  const salePrice = data.map(r => Number(r.sale_price || 0));
  const marketPrice = data.map(r => Number(r.market_price || 0));
  const volume = data.map(r => Number(r.volume || 0));

  if (!salesChart) salesChart = createSalesChart({ labels, salePrice, marketPrice, volume });
  else {
    salesChart.data.labels = labels;
    salesChart.data.datasets[0].data = volume;
    salesChart.data.datasets[1].data = salePrice;
    salesChart.data.datasets[2].data = marketPrice;
    const maxPrice = Math.max(...salePrice, ...marketPrice, 1);
    salesChart.options.scales.yPrice.suggestedMax = maxPrice * 1.2;
    salesChart.update();
  }
  salesChartTitle.textContent = `${cap || 'Select a Capacity'} Sales Data`;
}

function updatePegChart(cap, iface, cond) {
  const points = pegDataState[cap]?.points || [];
  const peg = computePegFromPoints(points);
  const maxPrice = Math.max(...peg.prices, peg.suggested || 0) || 100;
  const minPrice = Math.min(...peg.prices, peg.suggested || 0) || 0;

  if (!pegChart) pegChart = createPegChart(peg);
  else {
    pegChart.data.labels = peg.labels;
    pegChart.data.datasets[0].data = peg.weightsPercent;
    pegChart.data.datasets[1].data = peg.prices;
    pegChart.data.datasets[2].data = peg.labels.map(() => peg.suggested);
    pegChart.options.scales.yPrice.suggestedMin = Math.max(0, minPrice * 0.9);
    pegChart.options.scales.yPrice.suggestedMax = maxPrice * 1.1;
    pegChart.update();
  }

  const ifaceLabel = capitalize(iface);
  const condLabel = capitalize(cond);
  pegChartTitle.textContent = `${cap} ${ifaceLabel} – ${condLabel} Peg Inputs`;
}

function getExistingConfigMap(capacity) {
  const history = pegHistoryByCapacity[capacity] || [];
  const map = {};

  history.forEach(h => {
    const key =
      `${String(h.interface).toLowerCase()}|${String(h.condition_type).toLowerCase()}`;
    map[key] = Number(h.config_id);
  });

  return map;
}

function norm(v) {
  return String(v || '').toLowerCase();
}



function updateSummaryUI(cap) {
  updateSummary(cap);
  renderCapacityButtons();
}


function showEditor() {
  const el = document.getElementById('chartsContainer');
  if (!el) return;

  // Always show on desktop, conditional on mobile
  el.classList.remove('editor-hidden');
}

function hideEditorOnMobile() {
  if (window.innerWidth > 768) return;

  const el = document.getElementById('chartsContainer');
  if (!el) return;

  el.classList.add('editor-hidden');
}


// --------- Overall refresh
function refreshUI(cap, iface, cond) {
  currentCapacity = cap;
  currentInterfaceKey = iface || currentInterfaceKey;
  currentConditionKey = cond || currentConditionKey;

  interfaceSelect.value = currentInterfaceKey;
  conditionSelect.value = currentConditionKey;
  inventoryModeSelect.value = pegDataState[cap]?.inventoryMode || 'balanced';

  updateSalesChart(cap);
  renderSalesTable(cap);
  updatePegChart(cap, currentInterfaceKey, currentConditionKey);
  updateSummaryUI(cap);
  renderPegTable(cap, currentInterfaceKey, currentConditionKey);
  renderModifierTable(cap);
  renderCapacityButtons();


    if (activePegPointIndex !== null) {
  showPegHistoryFromDatabase(activePegPointIndex);
}

  const isEditorVisible = mainEditorLayout.style.display !== 'none';
  savePegBtn.style.display = currentCapacity && isEditorVisible ? 'inline-block' : 'none';
// 🔁 restore peg history after redraw
if (
  activePegPointIndex !== null &&
  pegDataState[currentCapacity]?.points?.[activePegPointIndex]?.id
) {
  showPegHistoryFromDatabase(activePegPointIndex);
}

}
async function handleInterfaceOrConditionChange() {
  if (!currentCapacity) return;

  currentInterfaceKey = interfaceSelect.value;
  currentConditionKey = conditionSelect.value;

  const map = getExistingConfigMap(currentCapacity);
  const key = `${norm(currentInterfaceKey)}|${norm(currentConditionKey)}`;

  if (map[key]) {
    // 🔁 EXISTING CONFIG → LOAD
    isCreatingNewConfig = false;
    window.currentConfigId = map[key];

    await fetchPegDataFor(
      currentCapacity,
      currentInterfaceKey,
      currentConditionKey
    );
  } else {
    //NEW CONFIG → EMPTY EDITOR
    isCreatingNewConfig = true;
    window.currentConfigId = null;

    pegDataState[currentCapacity] = {
      points: [],
      modifiers: [],
      sales: defaultSalesData(),
      inventoryMode: 'balanced',
      config_id: null
    };
  }

  refreshUI(currentCapacity, currentInterfaceKey, currentConditionKey);
}


// --------- small helper to generate simple history for new points (only front-end)
function generateSimpleHistory(base) {
  const arr = [];
  const b = Number(base) || 100;
  for (let i = 0; i < 30; i++) {
    const wiggle = 1 + Math.sin(i * 0.25) / 40 + (Math.random() - 0.5) / 100;
    arr.push(Number((b * wiggle).toFixed(2)));
  }
  return arr;
}

// --------- Init
async function init() {
hideEditorOnMobile();
showChooseCapacityState();
  // create empty charts so layout sizes are correct
  salesChart = createSalesChart({ labels: [''], salePrice: [0], marketPrice: [0], volume: [0] });
  pegChart = createPegChart({ labels: [], prices: [], weightsPercent: [], suggested: 0 });
  pegHistoryChart = createPegHistoryChart();

  // initially show main editor
  mainEditorLayout.style.display = 'grid';
  pegDataHistoryCard.style.display = 'none';

  await loadCapacities();

  // If there is at least one capacity, optionally auto-select first (or leave user to click)
  // If you'd like auto-load: uncomment below
  // if (capacities[0]) fetchAndSelectPeg(capacities[0]);
}

window.addEventListener('DOMContentLoaded', init);

// expose a small API to console for debugging
window._pegEditor = {
  state: () => ({ capacities, currentCapacity, pegDataState }),
  refresh: () => refreshUI(currentCapacity, currentInterfaceKey, currentConditionKey),
  fetchPegDataFor
};


function updatePegHistoryMeta(point, index) {
  const ifaceLabel = currentInterfaceKey.toUpperCase();
  const pointLabel = point.label || `Point ${index + 1}`;

  pegHistoryTitle.textContent =
    `Peg history – ${currentCapacity} ${ifaceLabel} – ${pointLabel}`;

  pegHistoryLabelEl.textContent = pointLabel;
  pegHistoryChannelEl.textContent = point.channel ? `– ${capitalize(point.channel)}` : '';

  if (point.url) {
    pegHistoryLinkEl.style.display = 'inline-block';
    pegHistoryLinkEl.href = point.url;
  } else {
    pegHistoryLinkEl.style.display = 'none';
  }
}





function clearPegHistory(message = 'No history') {
  if (!pegHistoryChart) return;

  pegHistoryChart.data.labels = [];
  pegHistoryChart.data.datasets[0].data = [];
  pegHistoryChart.update();

  pegHistoryTitle.textContent = 'Peg history';
  pegHistoryLabelEl.textContent = message;
  pegHistoryChannelEl.textContent = '';
  pegHistoryLinkEl.style.display = 'none';
}
async function showPegHistoryFromDatabase(pointIndex = activePegPointIndex) {
  console.log('--- showPegHistoryFromDatabase ---');
  console.log('currentCapacity:', currentCapacity);
  console.log('activePegPointIndex:', pointIndex);

  if (!currentCapacity || pointIndex === null) {
    return;
  }

  const point = pegDataState[currentCapacity]?.points?.[pointIndex];

  if (!point || !point.id) {
    clearPegHistory('Save this peg first');
    return;
  }

  console.log('Resolved point object:', point);
  console.log('point.id:', point.id, 'type:', typeof point.id);

  const days = Number(historyRangeSelect.value) || 30;

const FETCH_MULTIPLIER =
  days <= 30  ? 5 :
  days <= 90  ? 6 :
  days <= 180 ? 8 :
                10;

  let res;
  try {
    res = await api.loadPointHistory(
      Number(point.id),
      days * FETCH_MULTIPLIER
    );
  } catch (err) {
    console.error('History API failed:', err);
    clearPegHistory('Failed to load history');
    return;
  }

  console.log('Peg history API response:', res);

  if (!res || !Array.isArray(res.history) || res.history.length === 0) {
    clearPegHistory('No history found');
    return;
  }

  // 1 Group by date → keep HIGHEST price per day
  const byDate = {};

  for (const h of res.history) {
    const date = h.date;
    const price = Number(h.price);

    if (!byDate[date] || price > byDate[date]) {
      byDate[date] = price;
    }
  }

  // 2 Convert to array + sort (oldest → newest)
  const ordered = Object.entries(byDate)
    .map(([date, price]) => ({ date, price }))
    .sort((a, b) => a.date.localeCompare(b.date));

  // 3 Trim to EXACT requested range (keep newest N days)
  const final = ordered.slice(-days);

  // 4 Update chart
  pegHistoryChart.data.labels = final.map(h => h.date);
  pegHistoryChart.data.datasets[0].data =
    final.map(h => h.price);
  pegHistoryChart.update();

  // META
  pegHistoryTitle.textContent = `Peg history – ${currentCapacity}`;
  pegHistoryLabelEl.textContent =
    point.label || `Point ${pointIndex + 1}`;
  pegHistoryChannelEl.textContent =
    point.channel ? `(${point.channel})` : '';

  if (point.url) {
    pegHistoryLinkEl.style.display = 'inline-block';
    pegHistoryLinkEl.href = point.url;
  } else {
    pegHistoryLinkEl.style.display = 'none';
  }
}





function findFirstMissingCombo(capacity) {
  const history = pegHistoryByCapacity[capacity] || [];

  // Build set of existing combos
  const existing = new Set(
    history.map(h =>
      `${String(h.interface).toLowerCase()}|${String(h.condition_type).toLowerCase()}`
    )
  );

  // Check all valid combinations in order
  for (const iface of ALL_INTERFACES) {
    for (const cond of ALL_CONDITIONS) {
      const key = `${iface}|${cond}`;
      if (!existing.has(key)) {
        return {
          interface: iface,
          condition: cond
        };
      }
    }
  }

  // All combinations already exist
  return null;
}



function updatePegHistoryChartWithData(labels, prices) {
  if (!pegHistoryChart) return;
  pegHistoryChart.data.labels = labels;
  pegHistoryChart.data.datasets[0].data = prices;
  pegHistoryChart.update();
}


addNewPegConfigBtn.addEventListener('click', () => {
showEditor();
  if (!currentCapacity) {
    appAlert('Select a capacity first');
    return;
  }
  const missing = findFirstMissingCombo(currentCapacity);

  if (!missing) {
    // ALL EXIST
    document.getElementById('allConditionsModal').classList.remove('hidden');
    return;
  }

  // CREATE MODE
  isCreatingNewConfig = true;
  window.currentConfigId = null;

  currentInterfaceKey = missing.interface;
  currentConditionKey = missing.condition;

  interfaceSelect.value = missing.interface;
  conditionSelect.value = missing.condition;

  // Empty editor
  pegDataState[currentCapacity] = {
    points: [],
    modifiers: [],
    sales: defaultSalesData(),
    inventoryMode: 'balanced',
    config_id: null
  };
    pegNameContainer.style.display = "flex";
  pegNameInput.value = '';
  mainEditorLayout.style.display = 'grid';
  pegDataHistoryCard.style.display = 'none';
  savePegBtn.style.display = 'inline-block';

  refreshUI(currentCapacity, currentInterfaceKey, currentConditionKey);
});


function showAllConditionsExistModal() {
  document.getElementById("allConditionsModal").classList.remove("hidden");
}

document
  .getElementById("closeAllConditionsModal")
  .addEventListener("click", () => {
    document.getElementById("allConditionsModal").classList.add("hidden");
  });

function findConfigIdByCombo(capacity, iface, condition) {
  const history = pegHistoryByCapacity[capacity] || [];

  const found = history.find(h =>
    String(h.interface).toLowerCase() === iface.toLowerCase() &&
    String(h.condition_type).toLowerCase() === condition.toLowerCase()
  );

  return found ? Number(found.config_id) : null;
}

function maybeShowAllConditionsExistModal(existingConfigs) {
  if (!isCreatingNewConfig) return; // 🔑 IMPORTANT

  const required = [
    'sata:new', 'sata:used', 'sata:recertified',
    'sas:new',  'sas:used',  'sas:recertified'
  ];

  const existingKeys = existingConfigs.map(
    c => `${c.interface}:${c.condition_type}`
  );

  const missing = required.filter(k => !existingKeys.includes(k));

  if (missing.length === 0) {
    showAllConditionsExistModal();
    return true;
  }

  return false;
}
function showAllConditionsModal() {
  const modal = document.getElementById('allConditionsModal');
  modal.classList.remove('hidden');
}

function hideAllConditionsModal() {
  const modal = document.getElementById('allConditionsModal');
  modal.classList.add('hidden');
}

document
  .getElementById('closeAllConditionsModal')
  .addEventListener('click', hideAllConditionsModal);

document.addEventListener('DOMContentLoaded', () => {
  document
    .getElementById('allConditionsModal')
    .classList.add('hidden');
});

interfaceSelect.addEventListener('change', handleInterfaceOrConditionChange);
conditionSelect.addEventListener('change', handleInterfaceOrConditionChange);

const chartsContainer = document.getElementById('chartsContainer');
const chooseCapacityNotice = document.getElementById('chooseCapacityNotice');

function showChooseCapacityState() {
  if (chartsContainer) chartsContainer.style.display = 'none';
  if (chooseCapacityNotice) chooseCapacityNotice.style.display = 'block';
}

function showChartsState() {
  if (chooseCapacityNotice) chooseCapacityNotice.style.display = 'none';
  if (chartsContainer) chartsContainer.style.display = 'block';

  // Ensure charts render correctly when shown
  setTimeout(() => {
    salesChart?.resize();
    pegChart?.resize();
    pegHistoryChart?.resize();
  }, 0);
}


//toggle
document.addEventListener('click', function (e) {
  const btn = e.target.closest('#toggleSalesCard');
  if (!btn) return;

  const salesContent = document.getElementById('salesContent');
  if (!salesContent) return;

  const isHidden = salesContent.classList.toggle('hidden');

  btn.setAttribute('aria-expanded', String(!isHidden));
  btn.querySelector('.toggle-text').textContent = isHidden
    ? 'Show Sales'
    : 'Hide Sales';

  // Chart.js safe redraw
  if (!isHidden && window.salesChart) {
    setTimeout(() => {
      salesChart.resize();
      salesChart.update();
    }, 100);
  }
});

//mobile view
// =====================================================
// 🔒 GUARANTEED MOBILE SIDEBAR TOGGLE
// =====================================================
document.addEventListener('DOMContentLoaded', () => {
  const sidebar = document.querySelector('.sidebar');
  const btn = document.getElementById('sidebarSlideToggle');

  if (!sidebar) {
    console.error('❌ Sidebar not found');
    return;
  }
  if (!btn) {
    console.error('❌ Toggle button not found');
    return;
  }

  console.log('✅ Sidebar toggle initialized');

  btn.addEventListener('click', () => {
    sidebar.classList.toggle('collapsed');
    console.log(
      'Sidebar collapsed:',
      sidebar.classList.contains('collapsed')
    );
  });

  // Force collapse on mobile
  if (window.innerWidth <= 768) {
    sidebar.classList.add('collapsed');
  }
});

