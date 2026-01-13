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
   BUY BAND COMPUTATION  (INPUT)
   Standard logic:
   - Low Buy  = Adjusted Sale - (Adjusted Sale * m)
   - High Buy = Low Buy × 1.05
===================================================== */
export function computeBandPricesFromMargin(salePrice, marginPercent) {
  const m = Number(marginPercent) / 100;
  const low = salePrice -(salePrice * m);

  return {
    low,
    high: low * 1.05
  };
}


export function computeTotalWeight(points = []) {
  return points.reduce(
    (sum, p) => sum + (Number(p.weight) || 0),
    0
  );
}

export function computeTotalAdjustedPeg(points = []) {
  return points.reduce(
    (sum, p) => sum + (Number(p.adjusted_peg_price) || 0),
    0
  );
}

export function recomputeRowAdjustedPegPrices(block) {
  const points = block.points || [];

  points.forEach(p => {
    const base = Number(p.peg_base_price) || 0;
    const mod  = Number(p.peg_modifier) || 0;

    p.adjusted_peg_price =
      base * (1 + mod / 100);
  });
}


export function computePegFromPoints(points = []) {
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

export function computeAdjustedPeg(price, modifier = 0) {
  if (!price || isNaN(price)) return null;
  return price * (1 + modifier / 100);
}

export function computePegPointAverages(seriesMap) {
  const result = {};

  for (const pegPointId in seriesMap) {
    const series = seriesMap[pegPointId];
    if (!series || !Array.isArray(series.points)) continue;

    const values = series.points
      .map(p => Number(p.y))
      .filter(v => Number.isFinite(v));

    if (!values.length) continue;

    const avg =
      values.reduce((s, v) => s + v, 0) / values.length;

    result[pegPointId] = {
      label: series.label,
      avg
    };
  }

  return result;
}