// js/charts.js
const salesChartEl = document.getElementById('salesChart');
const pegChartEl = document.getElementById('pegChart');
const pegHistoryChartEl = document.getElementById('pegHistoryChart');
let pegPointHistoryChartInstance = null;


export function refreshChart(chart) {
  if (!chart) return;
  chart.resize();
  chart.update();
}

//peg chart
export function createPegChart(initialPeg, context = {}) {
  const {
  getCapacity,
  onPegPointClick
} = context;

  const pegChartEl = document.getElementById('pegChart');
  if (!pegChartEl) return null;

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
          pointHoverRadius: 10,
          pointHitRadius: 18,
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

      animation: {
        duration: 500,
        easing: 'easeOutQuart'
      },

      interaction: {
        mode: 'nearest',
        intersect: true
      },

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

  onClick: (evt, elements, chart) => {
  const points = chart.getElementsAtEventForMode(
    evt,
    'nearest',
    { intersect: true },
    false
  );

  // IMPORTANT GUARDS
  if (!points || !points.length) return;

  const point = points[0];
  if (point.index == null) return;

  if (typeof onPegPointClick === "function") {
    onPegPointClick(point.index);
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


//peg history
export function createPegHistoryChart() {
  const ctx = pegHistoryChartEl.getContext('2d');

  return new Chart(ctx, {
    type: 'line',
    data: {
      labels: [],
      datasets: [{
  label: 'Peg price',
  data: [],
  borderColor: '#2563eb',
  borderWidth: 2,

  tension: 0.25,
  pointRadius: 5,
  pointHoverRadius: 8,
  pointHitRadius: 18,
  pointBackgroundColor: '#2563eb',

  showLine: true
}]
    },

    options: {
      responsive: true,
      maintainAspectRatio: false,

      animation: {
        duration: 500,
        easing: 'easeOutQuart'
      },

      interaction: {
        mode: 'nearest',
        intersect: false
      },

      plugins: {
        legend: { display: false },
        tooltip: {
          mode: 'index',
          intersect: false,
          callbacks: {
            label: (ctx) => `$${ctx.formattedValue}`
              
          }
        }
      },

      scales: {
        y: {
          title: { display: true, text: 'Price (USD)' }
        },
        x: {
          title: { display: true, text: 'Day' }
        }
      }
    }
  });
}
//sales chart
export function createSalesChart(initialData) {
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

//peg point history //pegPointHistoryChart //Adjusted Peg Price 
export function buildPegPointDatasets(series) {
  const dateSet = new Set();

  Object.values(series).forEach(s => {
    s.points.forEach(p => dateSet.add(p.x));
  });

  const labels = [...dateSet].sort();

  const datasets = Object.values(series).map((s, idx) => {
    const map = {};
    s.points.forEach(p => {
      map[p.x] = p.y;
    });

    return {
      label: s.label,
      data: labels.map(d => map[d] ?? null),

      borderWidth: 2,
      tension: 0.3,
      spanGaps: true,
      pointRadius: 3
    };
  });

  return {
    labels,
    datasets  
  };
}



export function renderPegPointHistoryChart(seriesMap) {
  const canvas = document.getElementById("pegPointHistoryChart");
  if (!canvas) return;

  const ctx = canvas.getContext("2d");

  const { labels, datasets } = buildPegPointDatasets(seriesMap);

  if (pegPointHistoryChartInstance) {
    pegPointHistoryChartInstance.data.labels = labels;
    pegPointHistoryChartInstance.data.datasets = datasets;
    pegPointHistoryChartInstance.update();
    return;
    
  }

  pegPointHistoryChartInstance = new Chart(ctx, {
    type: "line",
    data: {
      labels,     
      datasets   
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: {
        mode: "nearest",
        intersect: false
      },
      plugins: {
        legend: {
          position: "bottom"
        },
        tooltip: {
          callbacks: {
            label(ctx) {
              return `${ctx.dataset.label}: $${ctx.parsed.y.toFixed(2)}`;
            }
          }
        }
      },
      scales: {
        x: {
          type: "category",
          title: {
            display: true,
            text: "Date"
          }
        },
        y: {
          title: {
            display: true,
            text: "PEG Price"
          },
          ticks: {
            callback: v => `$${v}`
          }
        }
      }
    }
  });
}

export function highlightSelectedPegPoint() {
  if (!pegPointHistoryChartInstance) return;

  pegPointHistoryChartInstance.data.datasets.forEach(ds => {
    const pegPointId = ds.label.replace("PEG Point ", "");

    if (!selectedPegPointId) {
      ds.borderWidth = 2;
      ds.borderColor = ds._baseColor;
      ds.pointRadius = 2;
      return;
    }

    if (String(pegPointId) === String(selectedPegPointId)) {
      ds.borderWidth = 3;
      ds.borderColor = ds._baseColor;
      ds.pointRadius = 3;
    } else {
      ds.borderWidth = 1;
      ds.borderColor = hexToRgba(ds._baseColor, 0.25);
      ds.pointRadius = 1;
    }
  });

  pegPointHistoryChartInstance.update();
}

export function clearPegPointHistoryChart() {
  if (!pegPointHistoryChartInstance) {
    console.warn("pegPointHistoryChartInstance not initialized");
    return;
  }


  pegPointHistoryChartInstance.data.labels = [];
  pegPointHistoryChartInstance.data.datasets = [];
  pegPointHistoryChartInstance.update();

  // clear averages UI safely
  const avg = document.getElementById("pegPointAverages");
  if (avg) avg.innerHTML = "";
}
