/**
 * =====================================================
 * PEG EDITOR – APP STATE OVERVIEW
 *
 * Core state:
 * - currentCapacity
 * - currentInterfaceKey
 * - currentConditionKey
 * - driveTypeSelect.value
 *
 * Data store:
 * - pegDataState[capacity]
 *   ├─ points[]
 *   ├─ modifiers[]
 *   ├─ saleModifiers[]
 *   ├─ sales[]
 *   ├─ marginPercent
 *
 * History:
 * - pegHistoryByCapacity[capacity]
 *
 * Charts:
 * - salesChart
 * - pegChart
 * - pegHistoryChart
 * - avgPegChart
 * - pegPointHistoryChartInstance
 *
 * Rule:
 * - app.js OWNS state
 * - charts.js ONLY renders
 * - helpers/* ONLY compute
 * =====================================================
 */

let idleTimer = null;
const IDLE_LIMIT = 30 * 60 * 1000;

function resetIdleTimer() {
  clearTimeout(idleTimer);
  idleTimer = setTimeout(forceLogout, IDLE_LIMIT);
}

function forceLogout() {
  fetch("api/logout.php").finally(() => {
    window.location.href = "login.html?expired=1";
  });
}

["mousemove", "keydown", "click", "scroll"].forEach(evt => {
  document.addEventListener(evt, resetIdleTimer, true);
});

resetIdleTimer();

import { setPegSheetInstance } from './tableEditor.js';
import { isValidPegRow, showPegHistoryLoading, updatePegRowAdjustedUI, hexToRgba } from './helpers/helpers.js';
import { refreshChart, highlightSelectedPegPoint, createPegChart, createSalesChart, createPegHistoryChart, buildPegPointDatasets, renderPegPointHistoryChart, clearPegPointHistoryChart} from './charts.js';
import {computePeg, computeBandPricesFromMargin, computeTotalWeight, computeTotalAdjustedPeg, recomputeRowAdjustedPegPrices, computePegFromPoints, computeAdjustedPeg, computePegPointAverages } from './helpers/computation.js';
import {getEffectiveDate, getPreviousWeekDates, normalizeSalesToPreviousWeek, formatSaveTime, normalizeDate} from './helpers/date.js';
import {formatMoney, escapeHtml, capitalize } from './helpers/format.js';
import {
  clearPegHistory,
  showPegHistorySection,
  showPegPointHistorySection,
  reloadPegHistoryChart,
  updateSalesChart,
  updatePegChart, 
  createOrRecreateAvgPegChart,
  loadAvgPegByCombo,
  showPegHistoryFromDatabase
  
} from './ui/charts/pegCharts.controller.js';
import { setPegChartsContext } from './ui/charts/pegCharts.controller.js';

const api = window.api;

 const appConfirmModal = document.getElementById('appConfirmModal');
const appConfirmTitle = document.getElementById('appConfirmTitle');
const appConfirmMessage = document.getElementById('appConfirmMessage');
const appConfirmOk = document.getElementById('appConfirmOk');
const appConfirmCancel = document.getElementById('appConfirmCancel');
const saleModifierTableBody =document.getElementById('saleModifierTableBody');
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

function updateAvgPegCardTitle(capacity) {
  const el = document.getElementById('avgPegCardTitle');
  if (!el) return;

  el.textContent = capacity
    ? `${capacity} - Average PEG Price Over Time`
    : 'Average PEG Price Over Time';
}




//function computeBandPrices(adjustedSalePrice, inventoryMode) {
 // let lowMultiplier = 0.65;
//  let highMultiplier = 0.75;
//  switch (inventoryMode) {
//    case 'overstocked':
//      lowMultiplier = 0.55; highMultiplier = 0.65; break;
//    case 'low':
//      lowMultiplier = 0.70; highMultiplier = 0.80; break;
//    case 'critical':
//      lowMultiplier = 0.75; highMultiplier = 0.85; break;
//    default:
//      break;
//  }
//  return { low: adjustedSalePrice * lowMultiplier, high: adjustedSalePrice * highMultiplier };
//}

//set pegdate
async function setPegDate(rawDate) {
  const date = normalizeDate(rawDate);
  if (!date) return;


  const today = getEffectiveDate();

  if (date > today) {
    appAlert("Future dates are not allowed.");
    pegHistoryDate.value = today;
    return;
  }

  activePegDate = date;
  pegHistoryDate.value = date;

  await loadPegForDate(date);
}

//load peg for date
async function loadPegForDate(selectedDate) {
  if (!currentCapacity || !selectedDate) return;

  // HARD RESET (prevents stale UI)
  modalPegDraft = null;
  pegEditorContainer.innerHTML = "";
  pegSaveStatus.textContent = "";

  const configId = findConfigIdByCombo(
  currentCapacity,
  driveTypeSelect.value,
  currentInterfaceKey,
  currentConditionKey
);

  if (!configId) {
    pegDateStatus.textContent =
      "No configuration exists yet. Create one first.";
    return;
  }

  const res = await api.loadPegByDate(configId, selectedDate);


  // Live peg structure (labels, qty, channels)
  const livePoints = getLivePegPointsFromEditor();

  // History lookup by peg_point_id
  const historyMap = new Map();
  if (Array.isArray(res.points)) {
    res.points.forEach(p => {
      historyMap.set(p.peg_point_id, p);
    });
  }

  // MERGE: keep price ONLY if history exists
  const mergedPoints = livePoints.map(lp => {
    const hist = historyMap.get(lp.peg_point_id);

    return {
      peg_point_id: lp.peg_point_id,
      label: lp.label,
      channel: lp.channel,
      qty: lp.qty,

      // PRICE RULE
      price: hist ? hist.price : "",   // blank ONLY if no data
    };
  });

  modalPegDraft = { points: mergedPoints };

  if (historyMap.size > 0) {
    pegDateStatus.textContent =
      `Editing PEG data for ${selectedDate}`;
  } else {
    pegDateStatus.textContent =
      `No PEG data for ${selectedDate}. Enter values.`;
  }

  renderModalPegEditor();
}


// --------- DOM refs ----------
const capacityListEl = document.getElementById('capacityList');

const salesTableBody = document.getElementById('salesTableBody');
const pegTableBody = document.getElementById('pegTableBody');
const modifierTableBody = document.getElementById('modifierTableBody');
const pegHistoryTableBody = document.getElementById('pegHistoryTableBody');

const interfaceSelect = document.getElementById('interfaceSelect');
const driveTypeSelect = document.getElementById("driveTypeSelect");
const conditionSelect = document.getElementById('conditionSelect');
const inventoryModeSelect = document.getElementById('inventoryMode');

const addRowBtn = document.getElementById('addRowBtn');
const clearPegSelectBtn = document.getElementById('clearPegSelectBtn');
const addModifierBtn = document.getElementById('addModifierBtn');
const addSaleModifierBtn = document.getElementById('addSaleModifierBtn');
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
const avgPegCard = document.getElementById('avgPegCard');
const mainEditorLayout = document.getElementById('mainEditorLayout');
const historyCardSubtitle = document.getElementById('historyCardSubtitle');
const pegNameContainer = document.getElementById('pegNameContainer');
const SSD_INTERFACES = ["sata", "sas", "nvme", "u.2", "u.3", "pcie"];
const HDD_INTERFACES = ["sata", "sas"]; 
const ALL_CONDITIONS = ["new", "used", "recertified"];
const DRIVE_TYPES = ["hdd", "ssd"];
const marginInput = document.getElementById('marginPercent');
const AVG_PEG_COLORS = {
  'u.2|new': '#4f8df7',
  'u.2|used': '#f76c6c',
  'u.2|recertified': '#7dd3a6',

  'u.3|new': '#f5a623',
  'u.3|used': '#6b8e23',
  'u.3|recertified': '#9b59b6',

  'pcie|new': '#1abc9c',
  'pcie|used': '#e67e22',
  'pcie|recertified': '#34495e',

  'nvme|new': '#00bcd4',
  'nvme|used': '#ff9800',
  'nvme|recertified': '#8bc34a',

  'sas|new': '#3f51b5',
  'sas|used': '#e91e63',
  'sas|recertified': '#607d8b',

  'sata|new': '#009688',
  'sata|used': '#795548',
  'sata|recertified': '#a48ff9'  
};


const filterDriveType   = document.getElementById('filterDriveType');
const filterInterface   = document.getElementById('filterInterface');
const filterCondition   = document.getElementById('filterCondition');

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
let avgPegChart = null;
let pegDataState = {}; 
let pegHistoryByCapacity = {};
let activePegDate = null;
let modalPegDraft = null;
let lastModifierEditType = null;
let hasUnsavedChanges = false;
let isViewingHistory = false;
let pegPointHistoryRange = 30;
let pegPointHistoryData  = [];
let selectedPegPointId = null;
let autosaveTimer = null;
const AUTOSAVE_DELAY = 10000; 
let isAutosaving = false;
let pegPointHistoryChartInstance = null;
let lastSavedAt = null;
let isConfirmingUnsaved = false;
let autosavePaused = false;
let idlePaused = false;



const INTERFACES_BY_TYPE = {
  HDD: ['sata', 'sas'],
  SSD: ['sata', 'sas', 'nvme', 'u.2', 'u.3']
};

function setActivePegPointIndex(idx) {
  activePegPointIndex = idx;
}

function updateInterfaceOptions() {
  const type = driveTypeSelect.value;
  const allowed = INTERFACES_BY_TYPE[type] || null;

  let firstValidValue = null;
  let currentValid = false;

  Array.from(interfaceSelect.options).forEach(opt => {
    if (!opt.value) return;

    if (!allowed) {
      opt.hidden = false;
      if (!firstValidValue) firstValidValue = opt.value;
      if (opt.selected) currentValid = true;
      return;
    }

    const isAllowed = allowed.includes(opt.value);
    opt.hidden = !isAllowed;

    if (isAllowed && !firstValidValue) {
      firstValidValue = opt.value;
    }

    if (opt.selected && isAllowed) {
      currentValid = true;
    }
  });

  if (!currentValid && firstValidValue) {
    interfaceSelect.value = firstValidValue;
  }
}


// Init + listen
updateInterfaceOptions();
driveTypeSelect.addEventListener('change', updateInterfaceOptions);



function getCurrentPegBlock() {
  if (!currentCapacity) return null;

  if (!pegDataState[currentCapacity]) {
    pegDataState[currentCapacity] = {
      points: [],
      saleModifiers: [],
      modifiers: [],
      sales: defaultSalesData(),
      marginPercent: pegDataState[currentCapacity]?.marginPercent ?? undefined,
      config_id: window.currentConfigId ?? null
    };
  }

  return pegDataState[currentCapacity];
}


function normalizeModifiers(cap) {
  const state = pegDataState[cap];
  if (!state) return;

  const raw = Array.isArray(state._rawModifiers)
    ? state._rawModifiers
    : [];

  state.modifiers = raw
    .filter(m => m.modifier_type !== 'sale')
    .map(m => ({
      id: m.id ?? null,
      label: m.label ?? '',
      amount: Number(m.amount) || 0
    }));

  state.saleModifiers = raw
    .filter(m => m.modifier_type === 'sale')
    .map(m => ({
      id: m.id ?? null,
      label: m.label ?? '',
      amount: Number(m.amount) || 0
    }));
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

  // SORT LOW → HIGH (GB-normalized)
  [...capacities]
    .sort((a, b) => capacityToNumber(a) - capacityToNumber(b))
    .forEach(cap => {
      const btn = document.createElement('button');
      btn.className = 'capacity-btn';
      btn.id = `cap-btn-${cap}`;
      btn.dataset.capacity = cap;

      // calculate status using current in-memory peg (if any)
let status = 'N/A';

const history = pegHistoryByCapacity[cap] || [];


const prices = history
  .map(h => {
   return Number(h.adjusted_price);
  })
  .filter(v => {
    const ok = Number.isFinite(v);
    if (!ok) {
    }
    return ok;
  });


if (prices.length) {
  const sum = prices.reduce((s, v) => s + v, 0);
  const avg = sum / prices.length;

  const min = Math.min(...prices);
  const max = Math.max(...prices);
  const mid = (min + max) / 2;

  status = formatMoney(avg);

  if (avg > mid) status += ' (High)';
  else if (avg < mid) status += ' (Low)';
  else status += ' (Avg)';
} else {
}


      btn.innerHTML =
        `<span class="label">${cap}</span><span class="meta">${status}</span>`;

      btn.addEventListener('click', async () => {
  const ok = await confirmIfUnsaved(
    "You have unsaved changes. Switching capacity will discard them. Continue?"
  );
  if (!ok) return;

  hasUnsavedChanges = false;
  clearChangeIndicator();
  fetchAndSelectPeg(cap);
});
      capacityListEl.appendChild(btn);
    });
}


function renderSalesTable(cap) {
  salesTableBody.innerHTML = '';
  if (!pegDataState[cap]) return;

  // LOCK SALES STATE
  if (!Array.isArray(pegDataState[cap].sales)) {
    pegDataState[cap].sales = defaultSalesData();
  }

  const data = pegDataState[cap].sales;

  data.forEach((row, index) => {
    const tr = document.createElement('tr');
    tr.dataset.index = index;

    tr.innerHTML = `
      <td>${row.day_label}</td>
      <td><input type="number" step="0.01" data-field="salePrice" value="${row.sale_price ?? 0}"></td>
      <td><input type="number" step="0.01" data-field="marketPrice" value="${row.market_price ?? 0}"></td>
      <td><input type="number" step="1" data-field="volume" value="${row.volume ?? 0}"></td>
    `;

    salesTableBody.appendChild(tr);
  });
}

function defaultSalesData() {
  return getPreviousWeekDates().map(d => ({
    day_label: d,
    sale_price: 0,
    market_price: 0,
    volume: 0
  }));
}





function renderPegTable(cap, iface, cond) {
  pegTableBody.innerHTML = '';

  const points =
    pegDataState[cap]?.points ?? [];

  points.forEach((p, idx) => {

    /* =========================
       MAIN ROW
    ========================= */
    const tr = document.createElement('tr');
    tr.dataset.index = idx;
    tr.className = 'clickable-peg-row';

    tr.innerHTML = `
  <td>
    <input type="text" data-field="label" value="${escapeHtml(p.label ?? '')}">
  </td>

  <td><input type="text" data-field="channel" value="${escapeHtml(p.channel ?? '')}"></td>
  <td><input type="url" data-field="url" value="${escapeHtml(p.url ?? '')}"></td>
  <td><input type="number" step="0.01" data-field="price" value="${p.price || ''}"></td>
  <td><input type="number" step="1" data-field="qty" class="peg-qty" value="${p.qty || ''}"></td>
  <td><input type="number" step="0.01" min="0" max="1" data-field="weight" value="${p.weight || ''}"></td>

  <!-- ADDITIONAL COLUMN -->
  <td style="text-align:center;">
    <button
  type="button"
  class="details-toggle"
  data-action="toggleDetails"
  aria-expanded="false"
>
  <span class="details-text">Details</span>
  <span class="chevron">▼</span>
</button>
  </td>

  <td class="row-actions">
    <button data-action="deleteRow">X</button>
  </td>
`;


    /* =========================
       DETAILS ROW (COLLAPSIBLE)
    ========================= */
    const detailsTr = document.createElement('tr');
    detailsTr.className = 'peg-details-row hidden';
    detailsTr.dataset.index = idx;

    detailsTr.innerHTML = `
      <td id="pegDetailCard" colspan="8">
  <div class="peg-details-card">

    <div class="peg-details-left">
      <label class="peg-details-label">Notes</label>
      <textarea data-field="notes">${escapeHtml(p.notes ?? '')}</textarea>
    </div>

    <div class="peg-details-right">

      <div class="peg-modifier-group">
        <label class="peg-details-label">Modifier (%)</label>
        <div class="modifier-input">
          <input type="number" data-field="peg_modifier" value="${p.peg_modifier ?? 0}">
          <span class="percent">%</span>
        </div>
        <small>Percent applied to the peg price (can be negative).</small>
      </div>

      <div class="peg-adjusted-group">
        <label class="peg-details-label">Adjusted PEG Price</label>
        <input type="text" data-field="adjusted_peg_price" readonly>
        <div class="peg-adjusted-meta">
          <span class="base"></span>
          <span class="factor"></span>
        </div>
      </div>

    </div>
  </div>
</td>`;

const price = Number(p.price || 0);
const modifier = Number(p.modifier || 0);

const adjusted = computeAdjustedPeg(price, modifier);

// store it back to state immediately
p.adjusted_price = adjusted;

// update UI (even if hidden)
const adjustedInput = detailsTr.querySelector('[data-field="adjusted_peg_price"]');
const baseSpan = detailsTr.querySelector('.base');
const factorSpan = detailsTr.querySelector('.factor');

if (adjusted !== null) {
  adjustedInput.value = Number.isFinite(p.adjusted_peg_price) ? p.adjusted_peg_price.toFixed(2): '';
  baseSpan.textContent = `Base: $${price.toFixed(2)}`;
  factorSpan.textContent = `Factor: ${(1 + modifier / 100).toFixed(4)}`;
}

    
    pegTableBody.appendChild(tr);
    pegTableBody.appendChild(detailsTr);

    /* =========================
       ROW CLICK → HISTORY
    ========================= */
    tr.addEventListener('click', (e) => {
      if (e.target.closest('button')) return;

      const block = getCurrentPegBlock();
      if (!block?.points?.[idx]) return;

      activePegPointIndex = idx;
      showPegHistoryFromDatabase(idx);

      document.querySelectorAll('#pegTableBody tr.clickable-peg-row')
        .forEach(r => r.classList.remove('active'));
      tr.classList.add('active');
      showPegHistorySection();
    });

  });
}


//peg toggle
document.addEventListener('click', (e) => {
  const btn = e.target.closest('button[data-action="toggleDetails"]');
  if (!btn) return;

  const row = btn.closest('tr');
  const idx = row.dataset.index;

  const detailsRow = pegTableBody.querySelector(
    `.peg-details-row[data-index="${idx}"]`
  );
  if (!detailsRow) return;

  const isOpen = !detailsRow.classList.contains('hidden');

  // toggle row
  detailsRow.classList.toggle('hidden');

  // toggle icon + state
  btn.setAttribute('aria-expanded', String(!isOpen));
  btn.querySelector('.chevron').textContent = isOpen ? '▼' : '▲';
  updateSummary(currentCapacity);
});

document.addEventListener('input', (e) => {
  if (!e.target.matches('[data-field="label"], [data-field="channel"],[data-field="url"],[data-field="notes"],[data-field="qty"]')) return;
  exitHistoryModeIfNeeded();
  markUnsaved();
  scheduleAutosave();
  
});
                          

// peg modifier input / price / weight input
document.addEventListener('input', (e) => {
  if (!e.target.matches('[data-field="price"], [data-field="peg_modifier"], [data-field="weight"]')) return;

  // 🔑 always resolve index from the closest row that HAS data-index
  const row = e.target.closest('tr[data-index]');
  if (!row) return;

  const idx = Number(row.dataset.index);
  if (!Number.isInteger(idx)) return;

  const block = getCurrentPegBlock();
  if (!block || !block.points?.[idx]) return;

  hasUnsavedChanges = true;
  exitHistoryModeIfNeeded();
  markUnsaved();
  scheduleAutosave();
  
  const point = block.points[idx];

  // MAIN ROW inputs
  const mainRow = pegTableBody.querySelector(
    `tr.clickable-peg-row[data-index="${idx}"]`
  );

  const detailsRow = pegTableBody.querySelector(
    `.peg-details-row[data-index="${idx}"]`
  );

  const priceInput = mainRow?.querySelector('[data-field="price"]');
  const weightInput = mainRow?.querySelector('[data-field="weight"]');
  const modifierInput = detailsRow?.querySelector('[data-field="peg_modifier"]');

  point.price        = Number(priceInput?.value) || 0;
  point.weight       = Number(weightInput?.value) || 0;
  point.peg_modifier = Number(modifierInput?.value) || 0;

  updateSummary(currentCapacity);
});







//low/high buy modifier
function renderModifierTable(cap) {
  modifierTableBody.innerHTML = '';

  const modifiers = (pegDataState[cap]?.modifiers || [])
    .filter(m => m.modifier_type !== 'sale');

  modifiers.forEach((m, idx) => {
    const tr = document.createElement('tr');
    tr.dataset.index = idx;

    tr.innerHTML = `
      <td><input type="text" data-field="label" value="${escapeHtml(m.label ?? '')}"></td>
      <td><input type="number" step="0.01" data-field="amount" value="${m.amount ?? 0}"></td>
      <td class="row-actions">
        <button data-action="deleteModifier">X</button>
      </td>
    `;

    modifierTableBody.appendChild(tr);
  });
}

//adjusted sale price modifier
function renderSaleModifierTable(cap) {
  if (!saleModifierTableBody) return;

  saleModifierTableBody.replaceChildren();

  const saleMods = pegDataState[cap]?.saleModifiers || [];

  saleMods.forEach((m, idx) => {
    const tr = document.createElement('tr');
    tr.dataset.index = idx;

    tr.innerHTML = `
      <td><input type="text" data-field="label" value="${escapeHtml(m.label)}"></td>
      <td><input type="number" data-field="amount" step="0.01" value="${m.amount}"></td>
      <td class="row-actions"><button data-action="deleteSaleModifier">X</button></td>
    `;

    saleModifierTableBody.appendChild(tr);
  });
}

function loadSelectedHistoryById(capacityKey, historyId) {
  const history = pegHistoryByCapacity[capacityKey] || [];
  const idx = history.findIndex(h => Number(h.id) === Number(historyId));
  if (idx === -1) return;

  loadSelectedHistory(capacityKey, idx);
}

function normCap(cap) {
  return String(cap || '').trim().toUpperCase();
}

function normDriveType(v) {
  return String(v || '')
    .trim()
    .toUpperCase()
    .replace(/\s+/g, '');
}


function renderPegHistoryTable(cap) {
  const key = normCap(cap);
  pegHistoryTableBody.innerHTML = '';

  const allHistory = pegHistoryByCapacity[key] || [];

  const driveFilter = filterDriveType?.value || '';
  const ifaceFilter = filterInterface?.value || '';
  const condFilter  = filterCondition?.value || '';

  const history = allHistory.filter(h => {
    if (driveFilter &&
        String(h.drive_type).toUpperCase() !== driveFilter.toUpperCase()) {
      return false;
    }

    if (ifaceFilter &&
        String(h.interface).toLowerCase() !== ifaceFilter.toLowerCase()) {
      return false;
    }

    if (condFilter &&
        String(h.condition_type).toLowerCase() !== condFilter.toLowerCase()) {
      return false;
    }

    return true;
  });

  if (!history.length) {
    pegHistoryTableBody.innerHTML = `
      <tr>
        <td colspan="9" style="text-align:center; color: var(--text-muted);">
          No history available.
        </td>
      </tr>`;
    return;
  }

  history.forEach(h => {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td style="text-align:center; place-items: center;">
        <button class="peg-add-row"
                data-action="viewHistory"
                data-id="${h.id}">
          View
        </button>
      </td>

      <td>${h.saved_at || '-'}</td>
      <td>${h.peg_name
        ? escapeHtml(h.peg_name)
        : '<span style="color:#9ca3af;">(No name)</span>'}
      </td>

      <td>${escapeHtml(h.drive_type)}</td>

      <td>
        ${formatMoney(h.base_price)}
        (${h.interface.toUpperCase()} / ${capitalize(h.condition_type)})
      </td>

      <td>${formatMoney(h.adjusted_price)}</td>
      <td>${formatMoney(h.low_buy)}</td>
      <td>${formatMoney(h.high_buy)}</td>

      <td style="text-align:center; place-items: center;">
        <button class="peg-delete-row"
                data-action="deleteHistory"
                data-id="${h.id}">
          Delete
        </button>
      </td>
    `;
    pegHistoryTableBody.appendChild(tr);
  });
}





[filterDriveType, filterInterface, filterCondition].forEach(el => {
  el?.addEventListener('change', () => {
    if (currentCapacity) {
      renderPegHistoryTable(currentCapacity);
    }
  });
});


if (marginInput) {
  marginInput.addEventListener("input", e => {
    if (!currentCapacity) return;

    const value = Number(e.target.value);

    pegDataState[currentCapacity] =
      pegDataState[currentCapacity] || {};

    pegDataState[currentCapacity].marginPercent =
      Number.isFinite(value) ? value : 50;
  });
}


//updateSummary
function updateSummary(cap) {
  if (!cap || !pegDataState[cap]) {
    summaryBasePeg.textContent = '$0.00';
    summarySuggested.textContent = '$0.00';
    summaryRawAvg.textContent = '$0.00';
    summaryModifiers.textContent = '$0.00';
    summaryLow.textContent = '$0.00';
    summaryHigh.textContent = '$0.00';
    return;
  }

  const block = pegDataState[cap];

  /* ===============================
     1) BASE PEG (NO MODIFIERS)
  =============================== */
  const { suggested, rawAvg } =
    computePegFromPoints(block.points || []);

  /* ===============================
     2) MODIFIER TOTALS
  =============================== */
  const buyModifierTotal =
    (block.modifiers || []).reduce(
      (s, m) => s + (Number(m.amount) || 0),
      0
    );

  const saleModifierTotal =
    (block.saleModifiers || []).reduce(
      (s, m) => s + (Number(m.amount) || 0),
      0
    );

  /* ===============================
     3) ADJUSTED PEG BASE
  =============================== */
  let weightedSum = 0;
  let totalWeight = 0;

  (block.points || []).forEach(p => {
    const price    = Number(p.price) || 0;
    const weight   = Number(p.weight) || 0;
    const modifier = Number(p.peg_modifier) || 0;

    const adjustedRowPrice =
      price * (1 + modifier / 100);

    p.adjusted_peg_price = adjustedRowPrice;

    if (weight > 0) {
      weightedSum += adjustedRowPrice * weight;
      totalWeight += weight;
    }
  });

  const adjustedPegBase =
    totalWeight > 0
      ? weightedSum / totalWeight
      : suggested;

  // expose for save
  block.adjustedPegBase  = adjustedPegBase;
  block.totalAdjustedPeg = adjustedPegBase;

  /* ===============================
     4) FINAL ADJUSTED SALE PRICE
  =============================== */
  const liveAdjustedSalePrice =
    adjustedPegBase + saleModifierTotal;

  const adjustedSalePrice = liveAdjustedSalePrice;


  /* ===============================
     5) MARGIN / BUY BAND
  =============================== */
  const marginPercent =
    Number.isFinite(Number(block.marginPercent))
      ? Number(block.marginPercent)
      : 50;

  if (marginInput) {
    marginInput.value = marginPercent;
  }

  const band = computeBandPricesFromMargin(
    suggested,
    marginPercent
  );

  const adjustedLow =
    (Number(band.low) || 0) + buyModifierTotal;

  const adjustedHigh = adjustedLow * 1.05;

  /* ===============================
     6) TOTAL WEIGHT UI
  =============================== */
  const totalWeightEl = document.getElementById("totalWeight");
  if (totalWeightEl) {
    const rounded = totalWeight.toFixed(2);
    totalWeightEl.innerHTML =
      totalWeight < 1
        ? `Total Weight: <strong style="color:#f87171">${rounded}</strong> ⚠️ (Less than 1)`
        : `Total Weight: <strong style="color:#34d399">${rounded}</strong>`;
  }

  /* ===============================
     7) SUMMARY UI
  =============================== */
  summaryBasePeg.textContent = formatMoney(suggested);
  summarySuggested.textContent = formatMoney(adjustedSalePrice);
  summaryRawAvg.textContent = formatMoney(rawAvg);
  summaryModifiers.textContent =
    formatMoney(buyModifierTotal + saleModifierTotal);
  summaryLow.textContent = formatMoney(adjustedLow);
  summaryHigh.textContent = formatMoney(adjustedHigh);

  updatePegRowAdjustedUI(block);
}






// --------- Utilities ----------


// --------- Data loading & sync ----------
async function loadCapacities() {
  try {
    capacities = await api.fetchCapacities();
    // ensure array of unique strings
    capacities = Array.from(new Set(capacities || []));
    renderCapacityButtons();
  } catch (err) {
    ////console.error(err);
    capacityListEl.innerHTML = `<span style="color: #f87171; font-size: 13px;">Error: ${err.message}</span>`;
  }
}

/**
 * Fetch peg data from the server for specific capacity/interface/condition
 * Stores into pegDataState[capacity] and refreshes UI
 */
async function fetchPegDataFor(
  cap,
  iface,
  cond,
  driveType = driveTypeSelect.value
) {
  if (!cap) return;

  // 🔒 Preserve snapshot value
  const preservedAdjustedPrice =
    pegDataState[cap]?.adjusted_price;

  try {
    const data = await api.fetchPegData(
      cap,
      iface,
      cond,
      driveType
    );

    const resolvedMargin =
      Number.isFinite(Number(data?.margin_percent))
        ? Number(data.margin_percent)
        : Number.isFinite(Number(data?.marginPercent))
          ? Number(data.marginPercent)
          : 50;

    if (!data || data.status === 'not_found') {
      pegDataState[cap] = {
        points: [],
        modifiers: [],
        saleModifiers: [],
        sales: defaultSalesData(),
        marginPercent: resolvedMargin,
        config_id: null
      };
      return;
    }

    if (data.status !== 'success') return;

    const peg = data.peg || {};

    pegDataState[cap] = {
      points: (peg.points || []).map(p => ({
        id: p.id ?? null,
        label: p.label ?? '',
        channel: p.channel ?? '',
        url: p.url ?? '',
        price: Number(p.price) || 0,
        qty: Number(p.qty) || 0,
        weight: Number(p.weight) || 0,
        notes: p.notes ?? '',
        peg_modifier: Number(p.peg_modifier) || 0,
        adjusted_peg_price: Number(p.adjusted_peg_price) || 0
      })),
      _rawModifiers: Array.isArray(peg.modifiers) ? peg.modifiers : [],
      modifiers: [],
      saleModifiers: [],
      sales: normalizeSalesToPreviousWeek(
        Array.isArray(peg.sales) ? peg.sales : []
      ),
      marginPercent: resolvedMargin,
      config_id: data.config_id ?? null
    };

    // 🔒 Restore snapshot price
    if (Number.isFinite(preservedAdjustedPrice)) {
      pegDataState[cap].adjusted_price = preservedAdjustedPrice;
    }

    normalizeModifiers(cap);

    if (cap === currentCapacity) {
      refreshUI(cap, iface, cond);
    } else {
      renderCapacityButtons();
    }

  } catch (err) {
    //console.error('❌ fetchPegDataFor failed:', err);
  }
}








// --------- Save
async function saveCurrentPegData({ silent = false } = {}) {
  
const DRIVE_TYPE_MAP = {
  HDD: 1,
  SSD: 2
};
  if (!currentCapacity) {
    appAlert("Select a capacity first.");
    return;
  }

  const state = getCurrentPegBlock();
if (!state) {
  appAlert("Invalid PEG state.");
  return;
}

  state.points        = Array.isArray(state.points) ? state.points : [];
  state.modifiers     = Array.isArray(state.modifiers) ? state.modifiers : [];
  state.saleModifiers = Array.isArray(state.saleModifiers) ? state.saleModifiers : [];
  state.sales         = Array.isArray(state.sales) ? state.sales : [];
  const { suggested } = computePegFromPoints(state.points || []);
state.basePegPrice = Number.isFinite(Number(state.basePegPrice))
  ? Number(state.basePegPrice)
  : Number(suggested) || 0;
  
  const resolvedConfigId = findConfigIdByCombo(
  currentCapacity,
  driveTypeSelect.value,
  currentInterfaceKey,
  currentConditionKey
  );

  /* =====================================================
     1) NORMALIZE POINT STATE
  ===================================================== */
  state.points.forEach(p => {
    p.price = Number(p.price) || 0;
    p.weight = Number(p.weight) || 0;
    p.peg_modifier = Number(p.peg_modifier) || 0;
    p.adjusted_peg_price = Number(p.adjusted_peg_price) || 0;
    p.notes = p.notes ?? '';
  });

  /* =====================================================
     2)RECOMPUTE ADJUSTED PEG BASE
  ===================================================== */
  const basePegPrice = Number(state.basePegPrice);
  
  let weightedSum = 0;
  let totalWeight = 0;

  state.points.forEach(p => {
    const adjustedRow =
      p.price * (1 + p.peg_modifier / 100);

    // keep row value consistent
    p.adjusted_peg_price = adjustedRow;

    if (p.weight > 0) {
      weightedSum += adjustedRow * p.weight;
      totalWeight += p.weight;
    }
  });

  
  const adjustedPegBase =
    totalWeight > 0
      ? weightedSum / totalWeight
      : 0;

  /* =====================================================
     3) SALE MODIFIERS
  ===================================================== */
  const saleModifierTotal =
    state.saleModifiers.reduce(
      (s, m) => s + (Number(m.amount) || 0),
      0
    );

  const adjustedSalePrice =
    adjustedPegBase + saleModifierTotal;

  /* =====================================================
     4) BUILD PAYLOAD
  ===================================================== */
  const payload = {
    capacity: currentCapacity,
    drive_type_id: DRIVE_TYPE_MAP[driveTypeSelect.value],
    interface: currentInterfaceKey,
    condition: currentConditionKey,
    peg_name: pegNameInput.value || null,
    marginPercent: Number(state.marginPercent) || 50,

    // 🔒 FINAL VALUES (DB SOURCE OF TRUTH)
    adjustedPegBase,
    adjustedSalePrice,
    basePegPrice,

    peg: {
      points: state.points.map(p => ({
        id: p.id ?? null,
        label: p.label ?? '',
        channel: p.channel ?? '',
        url: p.url ?? '',
        price: p.price,
        qty: Number(p.qty) || 0,
        weight: p.weight,

        notes: p.notes,
        peg_modifier: p.peg_modifier,
        adjusted_peg_price: p.adjusted_peg_price,

        created_at: p.created_at
          ? p.created_at.replace('T', ' ').slice(0, 19)
          : new Date().toISOString().slice(0, 19).replace('T', ' ')
      })),

      modifiers: [
        ...state.modifiers.map(m => ({
          id: m.id ?? null,
          label: m.label ?? '',
          amount: Number(m.amount) || 0,
          modifier_type: 'buy'
        })),
        ...state.saleModifiers.map(m => ({
          id: m.id ?? null,
          label: m.label ?? '',
          amount: Number(m.amount) || 0,
          modifier_type: 'sale'
        }))
      ],

      sales: state.sales.map(s => ({
        day_label: s.day_label ?? '',
        sale_price: Number(s.sale_price) || 0,
        market_price: Number(s.market_price) || 0,
        volume: Number(s.volume) || 0
      }))
    }
  };

  /* =====================================================
     5) SAVE
  ===================================================== */
  try {
    savePegBtn.disabled = true;

    const res = await api.savePeg(payload);

    if (res.status === "success") {
      
      await fetchPegDataFor(
    currentCapacity,
    currentInterfaceKey,
    currentConditionKey,
    driveTypeSelect.value
  );

      
      if (!silent) {
  appAlert(
    resolvedConfigId
      ? "Configuration updated."
      : "New configuration created."
  );
}

      const block = pegDataState[currentCapacity];
      if (block) {
        delete block.adjustedPegBase;
        delete block.totalAdjustedPeg;
      }

      
      // after successful save
const historyRes = await api.loadHistory(currentCapacity);
pegHistoryByCapacity[normCap(currentCapacity)] = historyRes.history || [];
renderPegHistoryTable(currentCapacity);

//get MOST RECENT snapshot
const latest = pegHistoryByCapacity[currentCapacity]?.[0];

if (latest) {
  pegDataState[currentCapacity].adjusted_price =
    Number(latest.adjusted_price) || 0;
}
      refreshUI(
        currentCapacity,
        currentInterfaceKey,
        currentConditionKey
      );
      hasUnsavedChanges = false;
      markSaved(true);
      reloadPegHistoryChart();
      loadPegPointHistory();
      showPegPointHistorySection();
    } else {
      throw new Error(res.message || "Unknown save error");
    }

  } catch (err) {
    appAlert("Save failed: " + err.message);
  } finally {
    savePegBtn.disabled = false;
  }
}






// --------- UI interactions and listeners ----------
async function fetchAndSelectPeg(capacityKey) {
  clearPegHistory();
  updateAvgPegCardTitle(capacityKey);
    hideEditorOnMobile();
    showChartsState();
  document.querySelectorAll('.capacity-btn').forEach(b => b.classList.remove('active'));

  const btn = document.getElementById(`cap-btn-${capacityKey}`);
  if (btn) btn.classList.add('active');

  currentCapacity = capacityKey;
  loadPegPointHistory();
// Auto-collapse sidebar after selection (mobile + desktop)
const sidebar = document.querySelector('.sidebar');

// Auto-collapse ONLY on mobile
if (sidebar && window.innerWidth <= 768) {
  sidebar.classList.add('collapsed');
}

  mainEditorLayout.style.display = 'none';
  pegDataHistoryCard.style.display = 'block';
  savePegBtn.style.display = 'none';
  avgPegCard.style.display = 'flex';
createOrRecreateAvgPegChart();

const days = Number(document.getElementById('avgPegRange')?.value || 30);
await loadAvgPegByCombo(currentCapacity, days);
  salesChartTitle.textContent = `${capacityKey} Selected`;
  historyCardSubtitle.textContent = `Past configurations for ${capacityKey}. Select one to load the editor.`;

showPegHistoryLoading();  
  // load history from API
const result = await api.loadHistory(capacityKey);
pegHistoryByCapacity[normCap(capacityKey)] = result.history || [];
pegNameInput.value = "";
pegNameContainer.style.display = 'none';
renderPegHistoryTable(capacityKey);
}


async function loadSelectedHistory(capacityKey, historyIndex) {
  showPegPointHistorySection();
  if (!capacityKey) return;
  isViewingHistory = true;
  clearChangeIndicator();
  hasUnsavedChanges = false;

  showEditor();
  updateAvgPegCardTitle(capacityKey);

  const history = pegHistoryByCapacity[capacityKey] || [];
  const selected = history[historyIndex];
  if (!selected) {
    isViewingHistory = false;
    return;
  }

  pegDataState[capacityKey] = pegDataState[capacityKey] || {
    points: [],
    modifiers: [],
    saleModifiers: [],
    sales: defaultSalesData(),
    marginPercent: null,
    config_id: null
  };

  pegDataState[capacityKey].adjusted_price =
    Number(selected.adjusted_price) || 0;

  currentCapacity = capacityKey;
  currentInterfaceKey = selected.interface;
  currentConditionKey = selected.condition_type;

  // 🔒 SAFE programmatic updates
  driveTypeSelect.value = selected.drive_type;
  updateInterfaceOptions();

  interfaceSelect.value = currentInterfaceKey;
  conditionSelect.value = currentConditionKey;

  window.currentConfigId = Number(selected.config_id) || null;
  window.originalInterface = selected.interface;
  window.originalCondition = selected.condition_type;

  const pegNameInputEl = document.getElementById("pegNameInput");
  if (pegNameInputEl) {
    pegNameInputEl.value = selected.peg_name || "";
  }

  await fetchPegDataFor(
    capacityKey,
    currentInterfaceKey,
    currentConditionKey,
    selected.drive_type
  );

  mainEditorLayout.style.display = 'grid';
  pegDataHistoryCard.style.display = 'none';
  savePegBtn.style.display = 'inline-block';

  normalizeModifiers(capacityKey);
  refreshUI(capacityKey, currentInterfaceKey, currentConditionKey);
  loadPegPointHistory();
  
  isViewingHistory = true;
hasUnsavedChanges = false;
clearChangeIndicator();
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
if (field === 'price') {
  val = val === '' ? 0 : Number(val);
}

if (field === 'weight') {
  // IMPORTANT: preserve existing value if input is empty
  val = val === ''
    ? (points[idx].weight ?? 1)
    : Math.max(0, Number(val));
}

if (field === 'qty') {
  val = val === '' ? 0 : Math.max(0, parseInt(val, 10) || 0);
}

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
  lastModifierEditType = 'buy';
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
  hasUnsavedChanges = true;
  markUnsaved();
  scheduleAutosave();
  lastModifierEditType = 'buy';
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
  const idx = Number(row.dataset.index);

  const state = pegDataState[currentCapacity];
  if (!state.sales[idx]) {
    state.sales[idx] = {
      day_label: row.children[0].textContent,
      sale_price: 0,
      market_price: 0,
      volume: 0
    };
  }

  const val = input.value === '' ? 0 : Number(input.value);

  if (field === 'salePrice') state.sales[idx].sale_price = val;
  if (field === 'marketPrice') state.sales[idx].market_price = val;
  if (field === 'volume') state.sales[idx].volume = val;

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
    qty: 1,
    weight: 0.1,
    history: generateSimpleHistory(base)
  });

  refreshUI(currentCapacity);
});

//low/high modifier btn
addModifierBtn.addEventListener('click', () => {
  if (!currentCapacity) return appAlert('Select a capacity or load a history first.');
  const s = pegDataState[currentCapacity] = pegDataState[currentCapacity] || { points: [], modifiers: [], saleModifiers: [], sales: defaultSalesData(), inventoryMode: 'balanced', config_id: null };
  s.modifiers.push({ label: `Modifier ${s.modifiers.length + 1}`, amount: 0 });
  refreshUI(currentCapacity, currentInterfaceKey, currentConditionKey);
});
//adjusted sales modifier btn
addSaleModifierBtn?.addEventListener('click', () => {
  lastModifierEditType = 'sale';

  if (!currentCapacity) {
    appAlert('Select a capacity or load a history first.');
    return;
  }

  const state = pegDataState[currentCapacity];

  if (!Array.isArray(state.saleModifiers)) {
    state.saleModifiers = [];
  }

  state.saleModifiers.push({
    label: `Sale Modifier ${state.saleModifiers.length + 1}`,
    amount: 0
  });

  renderSaleModifierTable(currentCapacity);
  updateSummaryUI(currentCapacity);
});


saleModifierTableBody?.addEventListener('input', (e) => {
  hasUnsavedChanges = true; 
  markUnsaved();
  scheduleAutosave();
  if (!currentCapacity) return;

  const input = e.target;
  const field = input.dataset.field;
  if (!field) return;

  const row = input.closest('tr');
  const idx = Number(row?.dataset.index);

  const arr = pegDataState[currentCapacity]?.saleModifiers;
  if (!arr?.[idx]) return;

  arr[idx][field] =
    field === 'amount'
      ? Number(input.value) || 0
      : input.value;

  updateSummaryUI(currentCapacity); // ✅ SAFE
});



saleModifierTableBody?.addEventListener('click', (e) => {
  lastModifierEditType = 'sale';

  if (e.target.dataset.action !== 'deleteSaleModifier') return;
  if (!currentCapacity) return;

  const row = e.target.closest('tr');
  const idx = Number(row?.dataset.index);

  pegDataState[currentCapacity].saleModifiers.splice(idx, 1);

  renderSaleModifierTable(currentCapacity);
  updateSummaryUI(currentCapacity);
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

  // VIEW HISTORY
if (e.target.dataset.action === 'viewHistory') {
  avgPegCard.style.display = 'none';
  pegNameContainer.style.display = "flex";
  const historyId = Number(e.target.dataset.id);
  if (!currentCapacity || !historyId) return;
  loadSelectedHistoryById(currentCapacity, historyId);
  loadPegPointHistory();
  return;
}

  async function reloadAvgPegChart(capacity, days) {
  if (!capacity) return;

  // Destroy safely
  if (avgPegChart) {
    avgPegChart.destroy();
    avgPegChart = null;
  }

  // Recreate empty shell
  createOrRecreateAvgPegChart();

  // Reload data
  await loadAvgPegByCombo(capacity, days);
}

  
  // DELETE
  if (e.target.dataset.action === 'deleteHistory') {
      const historyId = Number(e.target.dataset.id);
      if (!currentCapacity) return;

      const historyList = pegHistoryByCapacity[currentCapacity] || [];
      const item = historyList.find(h => Number(h.id) === historyId);
      if (!item) return;

      if (!(await appConfirm(`Delete this history entry saved on ${item.saved_at}?`,"Delete History"))) return;

      const result = await api.deleteHistory(item.id);

      if (result.status === "success") {
  appAlert("History deleted.");

  // Reload history
const res = await api.loadHistory(currentCapacity);
pegHistoryByCapacity[normCap(currentCapacity)] = res.history || [];

  // RESET STATE AFTER DELETE
  window.currentConfigId = null;
  isCreatingNewConfig = false;

  // Optional safety reset
  window.originalInterface = null;
  window.originalCondition = null;

  renderPegHistoryTable(currentCapacity);
  const days =
    Number(document.getElementById('avgPegRange')?.value) || 30;

  await reloadAvgPegChart(currentCapacity, days);
} else {
          appAlert("Delete failed: " + result.message);
      }
  }
});


// interface/condition change to reload data



// inventory change
//inventoryModeSelect.addEventListener('change', () => {
 // if (!currentCapacity) return;
//  pegDataState[currentCapacity] = pegDataState[currentCapacity] || { points: [], modifiers: [], sales: defaultSalesData(), inventoryMode: 'balanced', config_id: null };
//  pegDataState[currentCapacity].inventoryMode = inventoryModeSelect.value;
//  updateSummaryUI(currentCapacity);
//});

marginInput.addEventListener('change', () => {
  hasUnsavedChanges = true;
  markUnsaved();
  scheduleAutosave();
  if (!currentCapacity) return;

  const margin = Number(marginInput.value) || 50;
  pegDataState[currentCapacity].marginPercent = margin;

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



function getExistingConfigMap(capacity) {
  const history = pegHistoryByCapacity[capacity] || [];
  const map = {};

  history.forEach(h => {
    const key =
      `${norm(h.drive_type)}|${norm(h.interface)}|${norm(h.condition_type)}`;
    map[key] = Number(h.config_id);
  });

  return map;
}

function norm(v) {
  return String(v || '').toLowerCase();
}



function updateSummaryUI(cap) {
  updateSummary(cap);
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
  if (!cap) return;

  currentCapacity = cap;
  currentInterfaceKey = iface || currentInterfaceKey;
  currentConditionKey = cond || currentConditionKey;

  updateSalesChart(cap);
  renderSalesTable(cap);
  updatePegChart(cap, currentInterfaceKey, currentConditionKey);
  updateSummaryUI(cap);
  renderPegTable(cap, currentInterfaceKey, currentConditionKey);

  // ✅ render BOTH — but NEVER let one wipe the other
  renderModifierTable(cap);
  renderSaleModifierTable(cap);

  renderCapacityButtons();
}




async function handleInterfaceOrConditionChange() {
  if (!currentCapacity) return;

  currentInterfaceKey = interfaceSelect.value;
  currentConditionKey = conditionSelect.value;
  const driveType = driveTypeSelect.value;

  const map = getExistingConfigMap(currentCapacity);
  const key = `${norm(driveType)}|${norm(currentInterfaceKey)}|${norm(currentConditionKey)}`;

  if (map[key]) {
    // EXISTING CONFIG
    isCreatingNewConfig = false;
    window.currentConfigId = map[key];

    await fetchPegDataFor(
      currentCapacity,
      currentInterfaceKey,
      currentConditionKey,
      driveTypeSelect.value
    );
  } else {
    // NEW CONFIG
    isCreatingNewConfig = true;
    window.currentConfigId = null;

    pegDataState[currentCapacity] = {
      points: [],
      modifiers: [],
      saleModifiers: [],
      sales: defaultSalesData(),
      marginPercent: pegDataState[currentCapacity]?.marginPercent ?? 50,
      adjusted_price: 0,
      config_id: null
    };
  }

  refreshUI(currentCapacity, currentInterfaceKey, currentConditionKey);
  loadPegPointHistory();
  showPegPointHistorySection();
  updateSummary(currentCapacity);

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
  pegDataHistoryCard.style.display = 'none';
  hideEditorOnMobile();
  showChooseCapacityState();
  salesChart = createSalesChart({ labels: [''], salePrice: [0], marketPrice: [0], volume: [0] });
  pegHistoryChart = createPegHistoryChart();

  await loadCapacities();

  setPegChartsContext({
  salesChart,
  pegChart,
  avgPegChart,
  pegHistoryChart,
  pegPointHistoryChartInstance,
    
  salesChartTitle,
  pegChartTitle,
  pegHistoryTitle,
  pegHistoryLabelEl,
  pegHistoryChannelEl,
  pegHistoryLinkEl,
  historyRangeSelect,

  pegDataState,
  get currentCapacity() { return currentCapacity; },
  get activePegPointIndex() { return activePegPointIndex; },
    
  AVG_PEG_COLORS,  
  getCurrentPegBlock,
  setActivePegPointIndex,
  api
});


  await Promise.all(
    capacities.map(async cap => {
      try {
        const res = await api.loadHistory(cap);
        pegHistoryByCapacity[normCap(cap)] = res.history || [];
      } catch {
        pegHistoryByCapacity[cap] = [];
      }
    })
  );

  renderCapacityButtons();
}



window.addEventListener('DOMContentLoaded', init);
// expose a small API to ////console for debugging
window._pegEditor = {
  state: () => ({ capacities, currentCapacity, pegDataState }),
  refresh: () => refreshUI(currentCapacity, currentInterfaceKey, currentConditionKey),
  fetchPegDataFor
};








function findFirstMissingCombo(capacity) {
  const history = pegHistoryByCapacity[capacity] || [];

  // Build existing combo set: drive|interface|condition
  const existing = new Set(
    history.map(h =>
      `${String(h.drive_type).toLowerCase()}|` +
      `${String(h.interface).toLowerCase()}|` +
      `${String(h.condition_type).toLowerCase()}`
    )
  );

  for (const driveType of DRIVE_TYPES) {
    const interfaces =
      driveType === "ssd"
        ? SSD_INTERFACES
        : HDD_INTERFACES;

    for (const iface of interfaces) {
      for (const cond of ALL_CONDITIONS) {
        const key = `${driveType}|${iface}|${cond}`;

        if (!existing.has(key)) {
          return {
            drive_type: driveType.toUpperCase(), // keep UI consistent
            interface: iface,
            condition: cond
          };
        }
      }
    }
  }

  // All combinations already exist
  return null;
}


addNewPegConfigBtn.addEventListener('click', () => {
  showEditor();

  if (!currentCapacity) {
    appAlert('Select a capacity first');
    return;
  }
clearPegPointHistoryChart();
  const missing = findFirstMissingCombo(currentCapacity);

  if (!missing) {
    // ALL EXIST
    document.getElementById('allConditionsModal').classList.remove('hidden');
    return;
  }

  // CREATE MODE
  isCreatingNewConfig = true;
  window.currentConfigId = null;


  driveTypeSelect.value = missing.drive_type;
  updateInterfaceOptions(); // ensure interface list matches drive type

  currentInterfaceKey = missing.interface;
  currentConditionKey = missing.condition;

  interfaceSelect.value = missing.interface;
  conditionSelect.value = missing.condition;

  // Empty editor
  pegDataState[currentCapacity] = {
    points: [],
    modifiers: [],
    saleModifiers: [],
    sales: defaultSalesData(),
    marginPercent: pegDataState[currentCapacity]?.marginPercent ?? 50,
    config_id: null
  };

  pegNameContainer.style.display = "flex";
  pegNameInput.value = '';
  mainEditorLayout.style.display = 'grid';
  pegDataHistoryCard.style.display = 'none';
  avgPegCard.style.display = 'none';
  savePegBtn.style.display = 'inline-block';

  refreshUI(currentCapacity, currentInterfaceKey, currentConditionKey);
});


document
  .getElementById("closeAllConditionsModal")
  .addEventListener("click", () => {
    document.getElementById("allConditionsModal").classList.add("hidden");
  });

function findConfigIdByCombo(capacity, driveType, iface, condition) {
  const history = pegHistoryByCapacity[capacity] || [];

  const found = history.find(h =>
    String(h.drive_type).toUpperCase() === String(driveType).toUpperCase() &&
    String(h.interface).toLowerCase() === iface.toLowerCase() &&
    String(h.condition_type).toLowerCase() === condition.toLowerCase()
  );

  return found ? Number(found.config_id) : null;
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
  document.querySelector(".sidebar-action-btn") ?.addEventListener("click", openPegTableEditor); 
  document.getElementById("closePegTableModal") ?.addEventListener("click", () => { document.getElementById("pegTableModal")?.classList.add("hidden"); });
  
  document
    .getElementById('allConditionsModal')
    .classList.add('hidden');
});

interfaceSelect.addEventListener('change', async () => {
  const ok = await confirmIfUnsaved(
    "You have unsaved changes. Changing interface will discard them. Continue?"
  );
  if (!ok) {
    interfaceSelect.value = currentInterfaceKey;
    return;
  }

  hasUnsavedChanges = false;
  clearChangeIndicator();
  await handleInterfaceOrConditionChange();
});

conditionSelect.addEventListener('change', async () => {
  const ok = await confirmIfUnsaved(
    "You have unsaved changes. Changing condition will discard them. Continue?"
  );
  if (!ok) {
    conditionSelect.value = currentConditionKey;
    return;
  }

  hasUnsavedChanges = false;
  clearChangeIndicator();
  await handleInterfaceOrConditionChange();
});



driveTypeSelect.addEventListener("change", async () => {
  if (!currentCapacity) return;

  const prevDriveType = driveTypeSelect.dataset.prev;
  const prevInterface = interfaceSelect.dataset.prev;
  const prevCondition = conditionSelect.dataset.prev;

  const newDriveType = driveTypeSelect.value;

  const ok = await confirmIfUnsaved(
    "You have unsaved changes. Changing drive type will discard them. Continue?"
  );

  if (!ok) {
    driveTypeSelect.value = prevDriveType;
    updateInterfaceOptions(prevDriveType);

    if (prevInterface) interfaceSelect.value = prevInterface;
    if (prevCondition) conditionSelect.value = prevCondition;

    return;
  }
  driveTypeSelect.dataset.prev = newDriveType;
  interfaceSelect.dataset.prev = interfaceSelect.value;
  conditionSelect.dataset.prev = conditionSelect.value;

  hasUnsavedChanges = false;
  clearChangeIndicator();
  isViewingHistory = false;

  window.currentConfigId = null;
  isCreatingNewConfig = false;
  delete pegDataState[currentCapacity];

  updateInterfaceOptions(newDriveType);
  await handleInterfaceOrConditionChange();
});




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
    refreshChart(pegHistoryChart);
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
document.addEventListener('DOMContentLoaded', () => {
  const sidebar = document.querySelector('.sidebar');
  const btn = document.getElementById('sidebarSlideToggle');



  btn.addEventListener('click', () => {
    sidebar.classList.toggle('collapsed');
  });

  if (window.innerWidth <= 768) {
    sidebar.classList.add('collapsed');
  }
});














const avgPegRangeSelect = document.getElementById('avgPegRange');

if (avgPegRangeSelect) {
  avgPegRangeSelect.addEventListener('change', async (e) => {
    const days = Number(e.target.value);

    await loadAvgPegByCombo(currentCapacity, days);
  });
}


document.querySelectorAll(".section-header").forEach(header => {
  header.addEventListener("click", () => {
    const section = header.closest(".sidebar-section");
    section.classList.toggle("collapsed");
  });
});

/*peg table modal*/
let pegSheetInstance = null;

function initPegSheet() {
  const container = document.getElementById("pegSheet");
  if (!container) {
    ////console.error("pegSheet missing");
    return;
  }

  if (pegSheetInstance) {
    pegSheetInstance.destroy();
    pegSheetInstance = null;
  }

  const BASE_COLS = [
    "A",
    "B",
    "C",
    "D",
    "E",
    "F",
    "G",
    "H",
    "I",
    "J",
    "K"
  ];

  // 50 rows, dynamic columns
  const data = Array.from({ length: 50 }, () =>
    Array(BASE_COLS.length).fill("")
  );

  pegSheetInstance = new Handsontable(container, {
    
  data,

  colHeaders: BASE_COLS,

  colWidths: (index) => {
    if (index === 0) return 180;
    if (index === 1 || index === 2) return 50;
    if (index >= 6 && index <= 9) return 140;
    return 120;
  },

  rowHeaders: true,
  stretchH: "none",
  width: "100%",
  height: "100%",

  contextMenu: {
    items: {
      row_above: {},
      row_below: {},
      separator1: '---------',
      col_left: {},
      col_right: {},
      separator2: '---------',
      remove_row: {},
      remove_col: {},
      separator3: '---------',
      undo: {},
      redo: {}
    }
  },

  manualColumnResize: true,
  manualColumnMove: true,
  manualRowResize: true,

  minSpareCols: 0,
  minSpareRows: 10,

  afterCreateCol: () => pegSheetInstance.render(),
  afterRemoveCol: () => pegSheetInstance.render(),

  licenseKey: "non-commercial-and-evaluation"
});
setPegSheetInstance(pegSheetInstance);


}

function addPegSheetRow(rowData) {
  if (!pegSheetInstance) return;

  // Always insert at the very top (row 1 / index 0)
  pegSheetInstance.alter('insert_row_above', 0);

  rowData.forEach((value, colIndex) => {
    pegSheetInstance.setDataAtCell(0, colIndex, value);
  });

  pegSheetInstance.render();
}



function openPegTableEditor() {
  const modal = document.getElementById("pegTableModal");
  if (!modal) return;

  modal.classList.remove("hidden");

  // Handsontable MUST be created AFTER modal is visible
  requestAnimationFrame(() => {
    requestAnimationFrame(() => {
      initPegSheet();
      
       addPegSheetRow([
  "Product Number",
  "Qty",
  "Unit",
  "Interface",
  "Capacity",
  "Condition",
  "Brand",
  "Low Buy Price/Unit",
  "High Buy Price/Unit",
  "Low Buy Price",
  "High Buy Price"
]);
    });
  });
}



function closePegTableEditor() {
  const modal = document.getElementById("pegTableModal");
  if (!modal) return;

  modal.classList.add("hidden");

  // Clean destroy (prevents ghost editors)
  if (pegSheetInstance) {
    pegSheetInstance.destroy();
    pegSheetInstance = null;
  }
}

document.querySelector(".sidebar-action-btn")
  ?.addEventListener("click", openPegTableEditor);

document.getElementById("closePegTableModal")
  ?.addEventListener("click", closePegTableEditor);

document
  .getElementById("openPegHistoryBtn")
  .addEventListener("click", () => {
    openPegHistoryModal();
  });

// ================================
// PEG INPUT HISTORY
// ================================
// =========================
// PEG HISTORY STATE
// =========================

const pegHistoryModal     = document.getElementById("pegHistoryModal");
const pegHistoryDate      = document.getElementById("pegHistoryDate");
const pegDateStatus       = document.getElementById("pegDateStatus");
const pegSaveStatus       = document.getElementById("pegSaveStatus");
const pegEditorContainer  = document.getElementById("pegEditorContainer");

// =========================
// BUTTONS
// =========================
document.getElementById("openPegHistoryBtn")
  ?.addEventListener("click", openPegHistoryModal);

document.getElementById("closePegHistoryModal")
  ?.addEventListener("click", closePegHistoryModal);

document.getElementById("cancelPegHistory")
  ?.addEventListener("click", closePegHistoryModal);

document.getElementById("savePegHistory")
  ?.addEventListener("click", savePegHistory);

pegHistoryDate.addEventListener("change", e => {
  setPegDate(e.target.value);
});

// =========================
// OPEN / CLOSE MODAL
// =========================
function openPegHistoryModal() {
  pegHistoryModal.classList.remove("hidden");
  const today = getEffectiveDate();

  pegHistoryDate.max = today;
  if (!pegHistoryDate.value) {
    pegHistoryDate.value = today;
  }
  setPegDate(pegHistoryDate.value);
}


function closePegHistoryModal() {
  pegHistoryModal.classList.add("hidden");
  pegDateStatus.textContent = "";
  pegSaveStatus.textContent = "";
  pegEditorContainer.innerHTML = "";
  modalPegDraft = null;
  activePegDate = null;
}

// =========================
// DATE HELPERS
// =========================



// =========================
// GET LIVE PEG POINTS
// (used when no history exists)
// =========================
function getLivePegPointsFromEditor() {
  if (!currentCapacity || !pegDataState[currentCapacity]) return [];

  return pegDataState[currentCapacity].points.map(p => ({
    peg_point_id: Number(p.id),     // FORCE numeric ID
    label: p.label,
    channel: p.channel,
    qty: p.qty ?? 0
  }));
}

// =========================
// LOAD PEG BY DATE
// =========================




// =========================
// RENDER MODAL TABLE
// =========================
function renderModalPegEditor() {
  if (!modalPegDraft || !Array.isArray(modalPegDraft.points)) return;

  pegEditorContainer.innerHTML = `
    <table class="peg-table peg-history-tb-mb">
      <thead>
        <tr>
          <th>Label</th>
          <th>Channel</th>
          <th>Price</th>
        </tr>
      </thead>
      <tbody>
        ${modalPegDraft.points.map((p, i) => `
          <tr data-index="${i}">
            <td><input value="${p.label}" disabled></td>
            <td><input value="${p.channel}" disabled></td>
            <td>
              <input type="number" step="0.01"
                     value="${p.price ?? ""}"
                     data-field="price">
            </td>
          </tr>
        `).join("")}
      </tbody>
    </table>
  `;

  // Bind inputs → modal state only
  pegEditorContainer.querySelectorAll("tbody tr").forEach(tr => {
    const idx = Number(tr.dataset.index);

    tr.querySelectorAll("input[data-field]").forEach(input => {
      input.addEventListener("input", () => {
        const field = input.dataset.field;
        modalPegDraft.points[idx][field] =
          Number(input.value) || 0;
      });
    });
  });
}

// =========================
// SAVE PEG HISTORY
// =========================
async function savePegHistory() {
  if (!activePegDate || !modalPegDraft) {
    appAlert("Please select a valid date.");
    return;
  }

  const payload = {
    date: activePegDate,
    points: modalPegDraft.points.map(p => ({
      peg_point_id: p.peg_point_id,
      price: Number(p.price) || 0,
      qty: Number(p.qty) || 0
    }))
  };


  const res = await api.savePegHistory(payload);

  if (res.status === "success") {

    // reload modal data
    await loadPegForDate(activePegDate);
    
pegSaveStatus.className = "date-status success";
pegSaveStatus.textContent =
      `Saved successfully for ${activePegDate} (${new Date().toLocaleTimeString()})`;
    // reload main editor + charts ONLY if latest
    if (res.isLatest) {

      if (typeof fetchPegDataFor === "function") {
        await fetchPegDataFor(
          currentCapacity,
          currentInterfaceKey,
          currentConditionKey
        );
      }

      if (typeof refreshUI === "function") {
        refreshUI(
          currentCapacity,
          currentInterfaceKey,
          currentConditionKey
        );
      }
    }
loadPegPointHistory();
  } else {
    pegSaveStatus.className = "date-status error";
    pegSaveStatus.textContent = res.message || "Save failed.";
  }
}

//logout
const logoutBtn = document.getElementById("logoutBtn");

if (logoutBtn) {
  logoutBtn.addEventListener("click", logout);
}

async function logout() {
  try {
    await fetch("api/logout.php");
  } finally {
    window.location.href = "login.html";
  }
}

//qty toggle
document.addEventListener("DOMContentLoaded", () => {
  const qtyCheckbox = document.getElementById("qtyCheckbox");
  const qtyCol      = document.getElementById("col-qty");

  function toggleQtyColumn() {
    const show = qtyCheckbox.checked;

    if (show) {
      qtyCol.classList.add("col-visible");
      
    } else {
      qtyCol.classList.remove("col-visible");
 
    }
  }

  // Init state
  toggleQtyColumn();

  // Listen
  qtyCheckbox.addEventListener("change", toggleQtyColumn);
});

//pegPointHistoryAll pegPointHistorySection
const rangeSelect = document.getElementById("pegPointRangeSelect");

if (rangeSelect) {
  rangeSelect.addEventListener("change", () => {
    const val = rangeSelect.value;
    pegPointHistoryRange = val === "all" ? null : Number(val);
    loadPegPointHistory();
  });
}

async function loadPegPointHistory() {

  const capacity  = currentCapacity;
  const iface     = currentInterfaceKey;
  const driveType = driveTypeSelect.value;
  const condition = currentConditionKey;


  // HARD GUARD
  if (!capacity || !iface || !driveType || !condition) {
    //console.warn("Skipping PEG point history – missing filters");
    return;
  }

  const params = new URLSearchParams({
    capacity,
    interface: iface,
    drive_type: driveType,
    condition
  });

  if (pegPointHistoryRange) {
    params.append("days", pegPointHistoryRange);
  }

  const res = await fetch(`./api/load_peg_point_history.php?${params.toString()}`);
  const json = await res.json();

  if (json.status !== "ok") {
    console.error("PEG point history API error:", json);
    return;
  }

  pegPointHistoryData = json.data || [];

  const series   = groupPegPointSeries(pegPointHistoryData);
  const averages = computePegPointAverages(series);

  renderPegPointHistoryChart(series);
  updatePegPointAveragesUI(averages);
}


function groupPegPointSeries(rows) {
  const map = {};

  for (const r of rows) {
    if (!map[r.peg_point_id]) {
      map[r.peg_point_id] = {
        label: r.peg_label || `PEG ${r.peg_point_id}`,
        points: []
      };
    }

    map[r.peg_point_id].points.push({
      x: r.day,
      y: Number(r.price)
    });
  }

  return map;
}





function updatePegPointAveragesUI(averages) {
  const container = document.getElementById("pegPointAverages");
  if (!container) return;

  container.innerHTML = "";

  Object.values(averages).forEach(({ label, avg }) => {
    const el = document.createElement("div");
    el.className = "peg-point-average";
    el.innerHTML = `
    <span class="peg-point-name">${label}</span>
    <span class="peg-point-value">AVG: $${avg.toFixed(2)}</span>
`;
    container.appendChild(el);
  });
}

//show peg history


//show peg point history


function clearActivePegSelection() {
  activePegPointIndex = null;
  document
    .querySelectorAll('#pegTableBody tr.clickable-peg-row')
    .forEach(r => r.classList.remove('active'));
}

clearPegSelectBtn.addEventListener('click', () => {
  clearActivePegSelection();
  showPegPointHistorySection();
});


//autosave
function scheduleAutosave() {
  hasUnsavedChanges = true;
  markUnsaved();

  clearTimeout(autosaveTimer);
  autosaveTimer = setTimeout(runAutosave, AUTOSAVE_DELAY);
}

async function runAutosave() {
  clearTimeout(autosaveTimer);
  autosaveTimer = null;

  try {
    isAutosaving = true;
    markSaving();

    await saveCurrentPegData({ silent: true });

    hasUnsavedChanges = false;
    markSaved(false);
  } catch (err) {
    markUnsaved();
  } finally {
    isAutosaving = false;
  }
}




function markSaving() {
  const el = document.getElementById("changeIndicator");
  if (!el) return;

  el.textContent = "Saving…";
  el.classList.remove("unsaved", "saved");
}

function markUnsaved() {
  const el = document.getElementById("changeIndicator");
  if (!el) return;

  el.textContent = "Unsaved changes";
  el.classList.add("unsaved");
  el.classList.remove("saved");
}

function markSaved(isManual = false) {
  const el = document.getElementById("changeIndicator");
  if (!el) return;

  lastSavedAt = new Date();

  const label = isManual
    ? `Saved at ${formatSaveTime(lastSavedAt)}`
    : `Saved at ${formatSaveTime(lastSavedAt)}`;

  el.textContent = label;
  el.classList.add("saved");
  el.classList.remove("unsaved");

  // optional fade after a few seconds
  setTimeout(() => {
    if (el.textContent === label) {
      el.textContent = label; // keep timestamp visible
    }
  }, 10000);
}

function clearChangeIndicator() {
  const el = document.getElementById("changeIndicator");
  if (!el) return;

  el.textContent = "";
  el.classList.remove("unsaved", "saved");
}
function exitHistoryModeIfNeeded() {
  if (isViewingHistory) {
    isViewingHistory = false;
    clearChangeIndicator();
  }
}

async function confirmIfUnsaved(
  message = "You have unsaved changes. Continue without saving?"
) {
  if (!hasUnsavedChanges) {
    return true;
  }

  if (isConfirmingUnsaved) {
    return false;
  }

  isConfirmingUnsaved = true;
  pauseAutosave();
  const ok = await appConfirm(message, "Unsaved Changes");


  isConfirmingUnsaved = false;
  resumeAutosave();

  // 🔑 IMPORTANT PART
  if (!ok && hasUnsavedChanges) {
    scheduleAutosave();
  }

  return ok;
}



window.addEventListener("beforeunload", (e) => {

  if (!hasUnsavedChanges) return;

  pauseAutosave();

  e.preventDefault();
  e.returnValue = "";
  resumeAutosaveAfterUnloadCancel();
});


function resumeAutosaveAfterUnloadCancel() {
  setTimeout(() => {
    // If user is still on page, unload was canceled
    if (!hasUnsavedChanges) return;
    if (isAutosaving) return;
    if (isConfirmingUnsaved) return;
    if (isViewingHistory) return;

    resumeAutosave();
    scheduleAutosave();
  }, 500);
}

//pause autosave
function pauseAutosave() {
  autosavePaused = true;

  if (autosaveTimer) {
    clearTimeout(autosaveTimer);
    autosaveTimer = null;
  }
}

function resumeAutosave() {
  autosavePaused = false;
}