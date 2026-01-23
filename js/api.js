import { safeFetch } from './api/client.js';
import * as ModularAPI from './api/index.js';

/* ===============================
   GLOBAL API OBJECT (LEGACY ADAPTER)
   IMPORTANT:
   - Dates must pass through untouched
   - No normalization or defaulting here
================================ */

window.api = {
  /* -------------------------------
     PEG POINT PRICE HISTORY
     (date integrity critical)
  -------------------------------- */
  async loadPointHistory(pointId, days = 30) {
    const res = await safeFetch(
      `./api/load_point_history.php` +
      `?point_id=${encodeURIComponent(pointId)}` +
      `&days=${encodeURIComponent(days)}`
    );

    if (!res || res.status !== "success" || !Array.isArray(res.history)) {
      return { history: [] };
    }

    return {
      history: res.history.map(h => ({
        date: h.date,             
        price: Number(h.price) || 0
      }))
    };
  },

  /* -------------------------------
     SAVE PEG (date decided by caller)
  -------------------------------- */
  async savePeg(payload) {
    // payload may include:
    // - effective_date
    // - saved_at
    // DO NOT override
    return safeFetch("./api/save_peg.php", {
      method: "POST",
      body: JSON.stringify(payload)
    });
  },

  /* -------------------------------
     DELETE HISTORY (ID-BASED)
  -------------------------------- */
  async deleteHistory(id) {
    return safeFetch("./api/delete_history.php", {
      method: "POST",
      body: JSON.stringify({ id })
    });
  },

  /* -------------------------------
     CAPACITIES
  -------------------------------- */
  async saveCapacity(capacity) {
    return safeFetch("./api/save_capacity.php", {
      method: "POST",
      body: JSON.stringify({ capacity })
    });
  },

  /* -------------------------------
     LOAD PEG BY CONFIG ID
     (returns historical data)
  -------------------------------- */
  async fetchPegDataByConfigId(configId) {
    return safeFetch("./api/load_config.php", {
      method: "POST",
      body: JSON.stringify({ config_id: configId })
    });
  },

  /* -------------------------------
     AVG PEG BY COMBO (DATE-RANGE SENSITIVE)
  -------------------------------- */
  async loadAvgPegByCombo(capacity, days = 30) {
    return safeFetch(
      `./api/load_avg_peg_by_combo.php` +
      `?capacity=${encodeURIComponent(capacity)}` +
      `&days=${encodeURIComponent(days)}`
    );
  },

  /* -------------------------------
     LOAD PEG BY DATE
     (EXACT DATE MATCH – DO NOT FORMAT)
  -------------------------------- */
  async loadPegByDate(configId, date) {
    // date must already be YYYY-MM-DD
    return safeFetch(
      `./api/load_peg_by_date.php` +
      `?config_id=${encodeURIComponent(configId)}` +
      `&date=${encodeURIComponent(date)}`
    );
  },

  /* -------------------------------
     SAVE PEG HISTORY
     (date comes from caller / UI)
  -------------------------------- */
  async savePegHistory(payload) {
    // payload may include:
    // - effective_date
    // - saved_at
    // - history_date
    // DO NOT MODIFY
    return safeFetch("./api/save_peg_history.php", {
      method: "POST",
      body: JSON.stringify(payload)
    });
  },

  /* -------------------------------
     MODERN API BRIDGE (READ-ONLY)
     New code may import directly instead
  -------------------------------- */
  fetchPegData: ModularAPI.fetchPegData,
  loadHistory: ModularAPI.loadHistory,
  fetchCapacities: ModularAPI.fetchCapacities
};
