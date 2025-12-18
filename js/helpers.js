// js/helpers.js
export function formatMoney(amount) {
if (amount == null) return "$0.00";
return "$" + Number(amount).toFixed(2);
}


export function computePeg(points) {
if (!points || points.length === 0) return { labels: [], prices: [], weightsPercent: [], suggested: 0, rawAvg: 0 };


let weightedSum = 0; let totalWeight = 0; let rawSum = 0;
let noWeights = true;
points.forEach(p => { if (Number(p.weight) > 0) noWeights = false; });


points.forEach(p => {
const price = Number(p.price) || 0;
const weight = noWeights ? 1 : (Number(p.weight) || 0);
weightedSum += price * weight;
totalWeight += weight;
rawSum += price;
});


const suggested = totalWeight > 0 ? weightedSum / totalWeight : 0;
const rawAvg = points.length > 0 ? rawSum / points.length : 0;


const labels = points.map(p => p.label || 'Point');
const prices = points.map(p => Number(p.price) || 0);
const weightsPercent = points.map(p => {
const w = noWeights ? 1 : (Number(p.weight) || 0);
return totalWeight === 0 ? 0 : (w / totalWeight) * 100;
});


return { labels, prices, weightsPercent, suggested, rawAvg };
}


export function computeBandPrices(adjustedSalePrice, inventoryMode) {
let lowMultiplier = 0.65; let highMultiplier = 0.75;
switch (inventoryMode) {
case 'overstocked': lowMultiplier = 0.55; highMultiplier = 0.65; break;
case 'low': lowMultiplier = 0.70; highMultiplier = 0.80; break;
case 'critical': lowMultiplier = 0.75; highMultiplier = 0.85; break;
default: break;
}
return { low: adjustedSalePrice * lowMultiplier, high: adjustedSalePrice * highMultiplier };
}
