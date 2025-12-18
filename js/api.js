// api.js — Fully normalized API wrapper

// Generic JSON fetch helper
export async function safeFetch(url, options = {}) {
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

// -------------------------------------------------------------
// NORMALIZED API CALLS
// -------------------------------------------------------------

// 1. Load all capacities
export async function fetchCapacities() {
  const res = await safeFetch("/api/load_capacities.php");

  if (Array.isArray(res)) {
    return res.map(c => typeof c === "string" ? c : c.capacity);
  }

  if (res && Array.isArray(res.capacities)) {
    return res.capacities.map(c => typeof c === "string" ? c : c.capacity);
  }

  return [];
}

// 2. Load peg data
export async function fetchPegData(capacity, iface, condition) {
  const url = `/api/load_peg_data.php?capacity=${encodeURIComponent(capacity)}&interface=${iface}&condition=${condition}`;
  const res = await safeFetch(url);

  if (res.status === "not_found") {
    return { status: "not_found" };
  }

  return {
    status: "success",
    config_id: res.config_id ?? null,
    peg_name: res.peg_name ?? null,
    inventoryMode: res.inventoryMode ?? "balanced",
    peg: {
      points: Array.isArray(res.peg?.points) ? res.peg.points : [],
      modifiers: Array.isArray(res.peg?.modifiers) ? res.peg.modifiers : [],
      sales: Array.isArray(res.peg?.sales) ? res.peg.sales : []
    }
  };
}

// 3. Load peg history list
export async function loadHistory(capacity) {
  const res = await safeFetch(`/api/load_history.php?capacity=${encodeURIComponent(capacity)}`);

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
      inventory_mode: h.inventory_mode,
      saved_at: h.saved_at
    }))
  };
}

// 4. Load peg point history
export async function loadPointHistory(pointId, days = 30) {
  const res = await safeFetch(`/api/load_point_history.php?point_id=${pointId}&days=${days}`);

  if (!res || res.status !== "success" || !Array.isArray(res.history)) {
    return { history: [] };
  }

  return {
    history: res.history.map(h => ({
      date: h.date,
      price: Number(h.price) || 0
    }))
  };
}

// 5. Save peg
export async function savePeg(payload) {
  return await safeFetch("/api/save_peg.php", {
    method: "POST",
    body: JSON.stringify(payload)
  });
}

// 6. Delete history
export async function deleteHistory(id) {
  return await safeFetch("/api/delete_history.php", {
    method: "POST",
    body: JSON.stringify({ id })
  });
}

// 7. Add capacity
export async function saveCapacity(capacity) {
  return await safeFetch("/api/save_capacity.php", {
    method: "POST",
    body: JSON.stringify({ capacity })
  });
}

// 8. Load config by ID
export async function fetchPegDataByConfigId(configId) {
  return await safeFetch("/api/load_config.php", {
    method: "POST",
    body: JSON.stringify({ config_id: configId })
  });
}
