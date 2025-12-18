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
    const res = await safeFetch("API/load_capacities.php");

    // CASE 1: Backend returns array of strings → OK
    if (Array.isArray(res) && typeof res[0] === "string") {
        return res;
    }

    // CASE 2: Backend returns { capacities: [...] }
    if (res && Array.isArray(res.capacities)) {
        return res.capacities.map(c =>
            typeof c === "string" ? c : c.capacity
        );
    }

    // CASE 3: Backend returns array of objects
    if (Array.isArray(res)) {
        return res.map(c =>
            typeof c === "string" ? c : c.capacity
        );
    }

    // fallback
    return [];
}


// 2. Load peg data (points + modifiers + sales)
export async function fetchPegData(capacity, iface, condition) {
    const url = `API/load_peg_data.php?capacity=${encodeURIComponent(capacity)}&interface=${iface}&condition=${condition}`;
    const res = await safeFetch(url);

    // Standardize error response
    if (res.status === "not_found") {
        return { status: "not_found" };
    }

    // Normalize peg_name, points, modifiers, sales
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

// 3. Load Peg Data History — ALWAYS return array ONLY
export async function loadHistory(capacity) {
    const res = await safeFetch(`API/load_history.php?capacity=${encodeURIComponent(capacity)}`);

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

// 4. Load peg point price history (chart below)
export async function loadPointHistory(pointId, days = 30) {
    const res = await safeFetch(`API/load_point_history.php?point_id=${pointId}&days=${days}`);

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

// 5. Save peg configuration
export async function savePeg(payload) {
    const res = await safeFetch("API/save_peg.php", {
        method: "POST",
        body: JSON.stringify(payload)
    });

    return res;
}

// 6. Delete history entry
export async function deleteHistory(id) {
    const res = await safeFetch("API/delete_history.php", {
        method: "POST",
        body: JSON.stringify({ id })
    });

    return res;
}

// 7. Add capacity
export async function saveCapacity(capacity) {
    const res = await safeFetch("API/save_capacity.php", {
        method: "POST",
        body: JSON.stringify({ capacity })
    });

    return res;
}
export async function fetchPegDataByConfigId(configId) {
  return await safeFetch('/peg_editor_test/API/load_config.php', {
    method: 'POST',
    body: JSON.stringify({ config_id: configId })
  });
}
