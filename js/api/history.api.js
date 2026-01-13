// js/api/history.api.js
import { safeFetch } from './client.js';

export async function loadHistory(capacity) {
    const res = await safeFetch(
      `./api/load_history.php?capacity=${encodeURIComponent(capacity)}`
    );

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
