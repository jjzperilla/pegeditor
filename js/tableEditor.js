function todayISO() {
  return new Date().toISOString().slice(0, 10);
}
function normalizeCondition(cond) {
  const c = String(cond || '').toLowerCase().trim();

  if (c === 'fr' || c.includes('recert')) return 'recertified';
  if (c === 'new') return 'new';
  if (c === 'cr' || c.includes('used')) return 'used';

  return null;
}
async function generateBuyPricesFromDB() {
  if (!pegSheetInstance) return;

  const hot = pegSheetInstance;
  const rows = hot.countRows();

  for (let r = 0; r < rows; r++) {
    const qty       = Number(hot.getDataAtCell(r, 1)) || 0; // Qty col 2
    const iface     = hot.getDataAtCell(r, 3);             // Interface col 4
    const capacity  = hot.getDataAtCell(r, 4);             // Capacity col 5
    const condition = hot.getDataAtCell(r, 5);             // Condition col F

    if (!capacity || !iface || !condition || qty <= 0) continue;

    const normCond = normalizeCondition(condition);
    if (!normCond) continue;

    try {
      const res = await fetch(
        `/api/get_peg_buy_price.php` +
        `?capacity=${encodeURIComponent(capacity)}` +
        `&interface=${encodeURIComponent(iface.toLowerCase())}` +
        `&condition=${encodeURIComponent(normCond)}`
      ).then(r => r.json());

      if (res.status !== 'success') {
        //console.warn(`Row ${r + 1}: No PEG found`);
        continue;
      }

      const adjusted = Number(res.adjusted_price);
      const margin   = Number(res.margin_percent) || 80;

      // Base unit prices
      const lowUnit  = adjusted * (margin / 100);
      const highUnit = lowUnit * 1.05;

      // Totals
      const lowTotal  = lowUnit * qty;
      const highTotal = highUnit * qty;

      // Write back to table
      hot.setDataAtCell(r, 7, lowUnit.toFixed(2));   // Low Buy / Unit
      hot.setDataAtCell(r, 8, highUnit.toFixed(2));  // High Buy / Unit
      hot.setDataAtCell(r, 9, lowTotal.toFixed(2));  // Low Buy
      hot.setDataAtCell(r, 10, highTotal.toFixed(2)); // High Buy

    } catch (err) {
      //console.error(`Row ${r + 1}: API error`, err);
    }
  }

  hot.render();
}

document
  .getElementById("generatePegBuyPrices")
  ?.addEventListener("click", generateBuyPricesFromDB);


//export
function exportPegSheetToCSV() {
  if (!pegSheetInstance) return;

  const hot = pegSheetInstance;

  const data = hot.getData();
  const headers = hot.getColHeader();

  // Build CSV
  let csv = '';
  csv += headers.join(',') + '\n';

  data.forEach(row => {
    csv += row
      .map(cell => {
        if (cell == null) return '';
        const v = String(cell).replace(/"/g, '""');
        return `"${v}"`;
      })
      .join(',') + '\n';
  });

  // 🔑 Filename with date
  const date = todayISO();
  const filename = `peg-buy-prices-${date}.csv`;

  // Download
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);

  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  link.click();

  URL.revokeObjectURL(url);

}
document
  .getElementById('exportPegCsvBtn')
  ?.addEventListener('click', () => {
    exportPegSheetToCSV();
  });
