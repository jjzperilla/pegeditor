// js/helpers.js
export function formatMoney(amount) {
  if (amount == null || isNaN(Number(amount))) return "$0.00";
  return "$" + Number(amount).toFixed(2);
}

/* =====================================================
   PEG SNAPSHOT COMPUTATION
===================================================== */
export function computePeg(points) {
  if (!points || points.length === 0) {
    return {
      labels: [],
      prices: [],
      weightsPercent: [],
      suggested: 0,
      rawAvg: 0
    };
  }

  let weightedSum = 0;
  let totalWeight = 0;
  let rawSum = 0;

  let noWeights = true;
  points.forEach(p => {
    if (Number(p.weight) > 0) noWeights = false;
  });

  points.forEach(p => {
    const price = Number(p.price) || 0;
    const weight = noWeights ? 1 : (Number(p.weight) || 0);

    weightedSum += price * weight;
    totalWeight += weight;
    rawSum += price;
  });

  const suggested = totalWeight > 0 ? weightedSum / totalWeight : 0;
  const rawAvg = rawSum / points.length;

  const labels = points.map(p => p.label || "Point");
  const prices = points.map(p => Number(p.price) || 0);

  const weightsPercent = points.map(p => {
    const w = noWeights ? 1 : (Number(p.weight) || 0);
    return totalWeight === 0 ? 0 : (w / totalWeight) * 100;
  });

  return { labels, prices, weightsPercent, suggested, rawAvg };
}

/* =====================================================
   BUY BAND COMPUTATION (50%–100% DROPDOWN)
   Standard logic:
   - Low Buy  = Adjusted Sale × (marginPercent / 100)
   - High Buy = Low Buy × 1.05
===================================================== */
export function computeBandPrices(adjustedSalePrice, marginPercent) {
  const pct = Number(marginPercent) / 100;

  const low = adjustedSalePrice * pct;
  const high = low * 1.05;

  return { low, high };
}
