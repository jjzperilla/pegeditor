// js/api/history.api.js
import { safeFetch } from "./client.js";

function normalizeHistory(res) {
  if (!res || res.status !== "success" || !Array.isArray(res.history)) {
    return { history: [] };
  }

  return {
    history: res.history.map(h => ({
      id: h.id,
      config_id: h.config_id ?? null,
      capacity: h.capacity,
      drive_type: h.drive_type,
      interface: h.interface,
      condition_type: h.condition_type,
      peg_name: h.peg_name ?? null,
      base_price: Number(h.base_price) || 0,
      adjusted_price: Number(h.adjusted_price) || 0,
      low_buy: Number(h.low_buy) || 0,
      high_buy: Number(h.high_buy) || 0,
      margin_percent: h.margin_percent,
      saved_at: h.saved_at
    }))
  };
}

export async function loadHistory(capacity, driveTypeId = 1) {
  const res = await safeFetch(
    `./api/load_history.php?capacity=${encodeURIComponent(capacity)}&drive_type_id=${encodeURIComponent(driveTypeId)}`
  );
  return normalizeHistory(res);
}

// wrapper (no duplication)
export function loadSSDHistory(capacity) {
  return loadHistory(capacity, 2);
}
