let allCapsChart = null;

function sortCaps(a, b) {
  const na = parseFloat(String(a).replace("TB", "")) || 0;
  const nb = parseFloat(String(b).replace("TB", "")) || 0;
  return na - nb;
}

function uniq(arr) {
  return Array.from(new Set(arr));
}

function colorForIndex(i, total) {
  const hue = Math.round((360 / Math.max(total, 1)) * i);
  return `hsl(${hue} 85% 60%)`;
}

async function loadAllCapsAvg(days) {
  const res = await fetch(`api/avg_history_all_caps.php?days=${encodeURIComponent(days)}`, {
    credentials: "same-origin"
  });
  const data = await res.json();
  if (!data || data.status !== "ok") throw new Error("Failed to load avg history");
  return Array.isArray(data.rows) ? data.rows : [];
}

function buildSeries(rows) {
  const caps = uniq(rows.map(r => r.capacity)).sort(sortCaps);
  const dates = uniq(rows.map(r => r.date)).sort();

  const map = {};
  for (const cap of caps) map[cap] = {};
  for (const r of rows) map[r.capacity][r.date] = Number(r.avg);

  return { caps, dates, map };
}

function renderAllCapsChart(rows) {
  const canvas = document.getElementById("allCapsChart");
  if (!canvas) return;

  const { caps, dates, map } = buildSeries(rows);

  const datasets = caps.map((cap, i) => {
    const c = colorForIndex(i, caps.length);
    return {
      label: cap,
      data: dates.map(d => (Number.isFinite(map[cap]?.[d]) ? map[cap][d] : null)),
      borderColor: c,
      backgroundColor: c,
      tension: 0.35,
      spanGaps: true,
      borderWidth: 2,
      pointRadius: 3,
      pointHoverRadius: 5
    };
  });

  if (allCapsChart) {
    allCapsChart.destroy();
    allCapsChart = null;
  }

  allCapsChart = new Chart(canvas, {
    type: "line",
    data: { labels: dates, datasets },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      animation: false,
      interaction: { mode: "nearest", intersect: false },
      plugins: {
        legend: {
          display: true,
          position: "bottom",
          labels: { boxWidth: 12, boxHeight: 12 }
        },
        tooltip: {
          callbacks: {
            title(items) {
              return items?.[0]?.label || "";
            },
            label(ctx) {
              const v = ctx.parsed.y;
              return `${ctx.dataset.label}: $${Number(v || 0).toFixed(2)}`;
            }
          }
        }
      },
      scales: {
        x: {
          title: { display: true, text: "Day" },
          grid: { display: true }
        },
        y: {
          title: { display: true, text: "PEG Price" },
          grid: { display: true }
        }
      }
    }
  });
}

async function refreshAllCaps() {
  const days = document.getElementById("allCapsRange")?.value || "90";
  const rows = await loadAllCapsAvg(days);

  renderAllCapsChart(rows);
  renderCapSummaryList(rows, days);
}

document.addEventListener("DOMContentLoaded", () => {
  document.getElementById("allCapsRange")?.addEventListener("change", refreshAllCaps);
  document.getElementById("allCapsRefresh")?.addEventListener("click", refreshAllCaps);
  refreshAllCaps();
  
});

//second graph
function money2(n) {
  const x = Number(n);
  return Number.isFinite(x) ? x.toFixed(2) : "0.00";
}

function computeOverallAvgByCapacity(rows) {
  // rows: [{capacity, date, avg}]
  const acc = {};
  for (const r of rows) {
    const cap = r.capacity;
    const v = Number(r.avg);
    if (!Number.isFinite(v)) continue;
    if (!acc[cap]) acc[cap] = { sum: 0, count: 0, days: 0 };
    acc[cap].sum += v;
    acc[cap].count += 1;
  }

  const out = Object.keys(acc).map(cap => {
    const { sum, count } = acc[cap];
    return {
      capacity: cap,
      overallAvg: count ? (sum / count) : 0,
      points: count
    };
  });

  out.sort((a, b) => sortCaps(a.capacity, b.capacity));
  return out;
}

function renderCapSummaryList(rows, days) {
  const list = document.getElementById("capSummaryList");
  const note = document.getElementById("capSummaryNote");
  if (!list) return;

  const items = computeOverallAvgByCapacity(rows);

  list.innerHTML = items.map(it => `
    <div class="cap-item">
      <div class="cap-name">${it.capacity}</div>
      <div class="cap-avg">$${money2(it.overallAvg)}</div>
      <div class="cap-meta">${it.points} day(s) in range</div>
    </div>
  `).join("");

  if (note) note.textContent = `Range: last ${days} day(s)`;
}
