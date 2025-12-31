// api.js — Fully normalized API wrapper (NON-MODULE)

/* ===============================
   Generic JSON fetch helper
================================ */
async function safeFetch(url, options = {}) {
  const resp = await fetch(url, {
    headers: { "Content-Type": "application/json" },
    ...options
  });

  const text = await resp.text();

  try {
    return JSON.parse(text);
  } catch (e) {
    console.error("❌ Invalid JSON from server:", text);
    throw new Error("Server returned invalid JSON");
  }
}

/* ===============================
   GLOBAL API OBJECT
================================ */
window.api = {

  /* -------------------------------
     1. Load all capacities
  -------------------------------- */
  async fetchCapacities() {
    const res = await safeFetch("/api/load_capacities.php");

    // CASE 1: ["12TB", "14TB"]
    if (Array.isArray(res) && typeof res[0] === "string") {
      return res;
    }

    // CASE 2: { capacities: [...] }
    if (res && Array.isArray(res.capacities)) {
      return res.capacities.map(c =>
        typeof c === "string" ? c : c.capacity
      );
    }

    // CASE 3: [{ capacity: "12TB" }]
    if (Array.isArray(res)) {
      return res.map(c =>
        typeof c === "string" ? c : c.capacity
      );
    }

    return [];
  },

  /* -------------------------------
     2. Load peg data
  -------------------------------- */
async fetchPegData(capacity, iface, condition) {
  const url =
    `/api/load_peg_data.php?capacity=${encodeURIComponent(capacity)}` +
    `&interface=${encodeURIComponent(iface)}` +
    `&condition=${encodeURIComponent(condition)}`;

  const res = await safeFetch(url);

  if (res.status === "not_found") {
    return { status: "not_found" };
  }

  // NORMALIZE MARGIN HERE (SINGLE SOURCE OF TRUTH)
  const margin =
    Number.isFinite(Number(res.margin_percent))
      ? Number(res.margin_percent)
      : Number.isFinite(Number(res.marginPercent))
        ? Number(res.marginPercent)
        : undefined;

  return {
    status: "success",
    config_id: res.config_id ?? null,
    peg_name: res.peg_name ?? null,

    // ✅ PASS MARGIN THROUGH
    margin_percent: margin,
    marginPercent: margin,

    inventoryMode: res.inventoryMode ?? "balanced",

    peg: {
      points: Array.isArray(res.peg?.points) ? res.peg.points : [],
      modifiers: Array.isArray(res.peg?.modifiers) ? res.peg.modifiers : [],
      sales: Array.isArray(res.peg?.sales) ? res.peg.sales : []
    }
  };
},

  /* -------------------------------
     3. Load peg data history
  -------------------------------- */
  async loadHistory(capacity) {
    const res = await safeFetch(
      `/api/load_history.php?capacity=${encodeURIComponent(capacity)}`
    );

    if (!res || res.status !== "success" || !Array.isArray(res.history)) {
      return { history: [] };
    }

    return {
      history: res.history.map(h => ({
        id: h.id,
        config_id: h.config_id ?? null,
        capacity: h.capacity,
        interface: h.interface,
        condition_type: h.condition_type,
        peg_name: h.peg_name ?? null,
        base_price: Number(h.base_price) || 0,
        adjusted_price: Number(h.adjusted_price) || 0,
        margin_percent: h.margin_percent,
        saved_at: h.saved_at
      }))
    };
  },

  /* -------------------------------
     4. Load peg point price history
  -------------------------------- */
  async loadPointHistory(pointId, days = 30) {
    const res = await safeFetch(
      `/api/load_point_history.php?point_id=${pointId}&days=${days}`
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
     5. Save peg configuration
  -------------------------------- */
  async savePeg(payload) {
    return await safeFetch("/api/save_peg.php", {
      method: "POST",
      body: JSON.stringify(payload)
    });
  },

  /* -------------------------------
     6. Delete history entry
  -------------------------------- */
  async deleteHistory(id) {
    return await safeFetch("/api/delete_history.php", {
      method: "POST",
      body: JSON.stringify({ id })
    });
  },

  /* -------------------------------
     7. Add capacity
  -------------------------------- */
  async saveCapacity(capacity) {
    return await safeFetch("/api/save_capacity.php", {
      method: "POST",
      body: JSON.stringify({ capacity })
    });
  },

  /* -------------------------------
     8. Load peg by config ID
  -------------------------------- */
  async fetchPegDataByConfigId(configId) {
    return await safeFetch("/api/load_config.php", {
      method: "POST",
      body: JSON.stringify({ config_id: configId })
    });
  },

  /* -------------------------------
     9. Load average PEG by combo
  -------------------------------- */
  async loadAvgPegByCombo(capacity, days = 30) {
    return await safeFetch(
      `/api/load_avg_peg_by_combo.php?capacity=${encodeURIComponent(capacity)}&days=${days}`
    );
  },

/* -------------------------------
     10. Load average PEG by combo
  -------------------------------- */
async loadPegByDate(configId, date) {
  return await safeFetch(
    `/api/load_peg_by_date.php?config_id=${configId}&date=${encodeURIComponent(date)}`
  );
},
    
async savePegHistory(payload) {
  return await safeFetch('/api/save_peg_history.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload)
  });
}
    
    
};

