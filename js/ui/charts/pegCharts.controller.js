/**
 * pegCharts.controller.js
 *
 * Purpose:
 * - Orchestrates PEG-related charts
 * - Reads state via injected ctx
 * - Never mutates pegDataState directly
 *
 * app.js responsibilities:
 * - Owns state
 * - Calls into this controller
 *
 * charts.js responsibilities:
 * - Pure Chart.js rendering
 */

import { refreshChart, createPegChart } from '../../charts.js';
import { computePegFromPoints } from '../../helpers/computation.js';
import { capitalize } from '../../helpers/format.js';

let ctx = {};

export function setPegChartsContext(context) {
  ctx = context;
}
// --------- Chart update helpers ----------
export function normalizeComboKey(key) {
  return key
    .toLowerCase()
    .replace(/\s+/g, '')
    .replace('/', '|')
    .replace('-', '|')
    .replace('_', '|');
}

export function updateSalesChart(cap) {
  const data =
    ctx.pegDataState?.[cap]?.sales || defaultSalesData();

  const labels = data.map(r => r.day_label || '');
  const salePrice = data.map(r => Number(r.sale_price || 0));
  const marketPrice = data.map(r => Number(r.market_price || 0));
  const volume = data.map(r => Number(r.volume || 0));

  if (!ctx.salesChart) {
    ctx.salesChart = createSalesChart({
      labels,
      salePrice,
      marketPrice,
      volume
    });
  } else {
    ctx.salesChart.data.labels = labels;
    ctx.salesChart.data.datasets[0].data = volume;
    ctx.salesChart.data.datasets[1].data = salePrice;
    ctx.salesChart.data.datasets[2].data = marketPrice;

    const maxPrice = Math.max(...salePrice, ...marketPrice, 1);
    ctx.salesChart.options.scales.yPrice.suggestedMax = maxPrice * 1.2;
    ctx.salesChart.update();
  }

  ctx.salesChartTitle.textContent =
    `${cap || 'Select a Capacity'} Sales Data`;
}


export function updatePegChart(cap, iface, cond) {
  const points = ctx.pegDataState?.[cap]?.points || [];
  const peg = computePegFromPoints(points);

  const maxPrice = Math.max(...peg.prices, peg.suggested || 0) || 100;
  const minPrice = Math.min(...peg.prices, peg.suggested || 0) || 0;

  if (!ctx.pegChart) {
    ctx.pegChart = createPegChart(peg, {
      getCapacity: () => ctx.currentCapacity,

      onPegPointClick: (idx) => {
        const block = ctx.getCurrentPegBlock();
        if (!block?.points?.[idx]) return;

        ctx.setActivePegPointIndex?.(idx);
        showPegHistoryFromDatabase(idx);

        document
          .querySelectorAll('#pegTableBody tr')
          .forEach(r => r.classList.remove('active'));

        const row = document.querySelector(
          `#pegTableBody tr[data-index="${idx}"]`
        );
        if (row) row.classList.add('active');

        showPegHistorySection();
      }
    });
  } else {
    ctx.pegChart.data.labels = peg.labels;
    ctx.pegChart.data.datasets[0].data = peg.weightsPercent;
    ctx.pegChart.data.datasets[1].data = peg.prices;
    ctx.pegChart.data.datasets[2].data =
      peg.labels.map(() => peg.suggested);

    ctx.pegChart.options.scales.yPrice.suggestedMin =
      Math.max(0, minPrice * 0.9);
    ctx.pegChart.options.scales.yPrice.suggestedMax =
      maxPrice * 1.1;

    ctx.pegChart.update();
  }

  const ifaceLabel = capitalize(iface);
  const condLabel = capitalize(cond);
  ctx.pegChartTitle.textContent =
    `${cap} ${ifaceLabel} – ${condLabel} Peg Inputs`;
}


export function createOrRecreateAvgPegChart() {
  const canvas = document.getElementById('avgPegChart');
  if (!canvas) return;

  // destroy safely
  if (ctx.avgPegChart && typeof ctx.avgPegChart.destroy === 'function') {
    ctx.avgPegChart.destroy();
    ctx.avgPegChart = null;
  }

  const chartCtx = canvas.getContext('2d');

  ctx.avgPegChart = new Chart(chartCtx, {
    type: 'line',
    data: {
      labels: [],
      datasets: []
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { position: 'bottom' }
      },
      scales: {
        x: { title: { display: true, text: 'Date' } },
        y: { title: { display: true, text: 'Average Peg Price' } }
      }
    }
  });
}



export async function loadAvgPegByCombo(capacity, days) {
  if (!capacity || !ctx.avgPegChart) return;

  const res = await ctx.api.loadAvgPegByCombo(capacity, days);
  if (!res || res.status !== 'success') return;

  const combos = res.data;

  const dateSet = new Set();
  Object.values(combos).forEach(rows =>
    rows.forEach(r => dateSet.add(r.date))
  );

  const labels = [...dateSet].sort();
  const datasets = buildDatasets(combos, labels);

  ctx.avgPegChart.data.labels = labels;
  ctx.avgPegChart.data.datasets = datasets;
  ctx.avgPegChart.update();

  updateAvgPegSummary(combos, days);
}

export function buildDatasets(series, allDates) {
  return Object.entries(series).map(([rawKey, points]) => {
    const key = normalizeComboKey(rawKey);

    const map = {};
    points.forEach(p => {
      map[p.date] = Number(p.price) || null;
    });

    const color = ctx.AVG_PEG_COLORS[key] || '#6b7280';

    return {
      label: rawKey.toUpperCase(),
      data: allDates.map(d => map[d] ?? null),

      borderColor: color,
      backgroundColor: color,
      borderWidth: 2,
      tension: 0.3,
      spanGaps: true,

      pointRadius: 3,
      pointHoverRadius: 6
    };
  });
}

export function updateAvgPegSummary(series, days) {
  const container = document.getElementById('avgPegSummaryRows');
  const daysEl = document.getElementById('avgPegDays');

  if (!container || !daysEl) return;

  daysEl.textContent = days;
  container.innerHTML = '';

  Object.entries(series).forEach(([key, points]) => {
    if (!points.length) return;

    const avg =
      points.reduce((s, p) => s + Number(p.price || 0), 0) / points.length;

    const row = document.createElement('div');
    row.className = 'row';
    row.innerHTML = `
      <span>${key.replace('|', ' / ').toUpperCase()}</span>
      <strong>$${avg.toFixed(2)}</strong>
    `;
    container.appendChild(row);
  });
}

export function updateAvgSummary(datasets, days) {
  let total = 0;
  let count = 0;

  datasets.forEach(ds => {
    ds.data.forEach(v => {
      if (v != null) {
        total += v;
        count++;
      }
    });
  });

  const avg = count ? total / count : 0;
  document.getElementById('avgPegSummary').textContent =
    `Average PEG over last ${days} days: $${avg.toFixed(2)}`;
}

export function reloadPegHistoryChart() {
  if (!ctx.pegHistoryChart) return;
  if (ctx.activePegPointIndex == null) return;

  showPegHistoryFromDatabase(ctx.activePegPointIndex);
}

export function clearPegHistory(message = 'No history') {
  if (!ctx.pegHistoryChart) return;

  ctx.pegHistoryChart.data.labels = [];
  ctx.pegHistoryChart.data.datasets[0].data = [];
  refreshChart(ctx.pegHistoryChart);

  ctx.pegHistoryTitle.textContent = 'Peg history';
  ctx.pegHistoryLabelEl.textContent = message;
  ctx.pegHistoryChannelEl.textContent = '';
  ctx.pegHistoryLinkEl.style.display = 'none';
}

export async function showPegHistoryFromDatabase(pointIndex = ctx.activePegPointIndex) {
const capacity = ctx.currentCapacity;
if (!capacity || pointIndex === null) return;

const point = ctx.pegDataState[capacity]?.points?.[pointIndex];

  if (!point || !point.id) {
    clearPegHistory('Save this peg first');
    return;
  }


  const days = Number(ctx.historyRangeSelect.value) || 30;

const FETCH_MULTIPLIER =
  days <= 30  ? 5 :
  days <= 90  ? 6 :
  days <= 180 ? 8 :
                10;

  let res;
  try {
    res = await ctx.api.loadPointHistory(
  Number(point.id),
  days * FETCH_MULTIPLIER
);
  } catch (err) {
    clearPegHistory('Failed to load history');
    return;
  }

 

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
  ctx.pegHistoryChart.data.labels =
  final.map(h => h.date);
ctx.pegHistoryChart.data.datasets[0].data =
  final.map(h => h.price);

async function loadOosRanges(pegPointId) {
  try {
    const res = await ctx.api.loadPointOosRanges(pegPointId);
    return Array.isArray(res?.ranges) ? res.ranges : [];
  } catch (e) {
    console.warn("loadPointOosRanges failed, continuing without OOS flags:", e);
    return []; // <-- important: do not block chart
  }
}

function isDateInAnyRange(yyyyMmDd, ranges) {
  // ranges: [{start:'2026-02-10', end:'2026-02-12' or null}]
  for (const r of ranges) {
    if (!r?.start) continue;
    const start = r.start;
    const end = r.end || "9999-12-31"; // open-ended
    if (yyyyMmDd >= start && yyyyMmDd <= end) return true;
  }
  return false;
}
 
const ranges = await loadOosRanges(Number(point.id));

const flags = final.map(r => isDateInAnyRange(r.date, ranges));  
ctx.pegHistoryChart.$oosFlags = flags;

// set data as normal (y numbers or {x,y})
ctx.pegHistoryChart.data.labels = final.map(r => r.date);
ctx.pegHistoryChart.data.datasets[0].data = final.map(r => r.price);
  
refreshChart(ctx.pegHistoryChart);

console.log("PEG HISTORY FINAL OOS COUNT:", flags.filter(Boolean).length);
  // META
  ctx.pegHistoryTitle.textContent =
  `Peg history – ${capacity}`;
ctx.pegHistoryLabelEl.textContent =
  point.label || `Point ${pointIndex + 1}`;
ctx.pegHistoryChannelEl.textContent =
  point.channel ? `(${point.channel})` : '';

  if (point.url) {
    ctx.pegHistoryLinkEl.style.display = 'inline-block';
    ctx.pegHistoryLinkEl.href = point.url;
  } else {
    ctx.pegHistoryLinkEl.style.display = 'none';
  }
}

export function showPegHistorySection() {
  const pointSection = document.getElementById("pegPointHistorySection");
  const singleSection = document.getElementById("pegHistorySection");

  if (pointSection) pointSection.style.display = "none";
  if (singleSection) singleSection.style.display = "block";

  // resize after visibility change
  setTimeout(() => {
    ctx.pegHistoryChart?.resize();
  }, 0);
}

export function showPegPointHistorySection() {
  const pointSection = document.getElementById("pegPointHistorySection");
  const singleSection = document.getElementById("pegHistorySection");

  if (pointSection) pointSection.style.display = "block";
  if (singleSection) singleSection.style.display = "none";

  setTimeout(() => {
    ctx.pegPointHistoryChartInstance?.resize?.();
  }, 0);
}