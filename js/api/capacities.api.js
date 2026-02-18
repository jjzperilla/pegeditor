// js/api/capacities.api.js
import { safeFetch } from './client.js';

export async function fetchCapacities() {
    const res = await safeFetch("./api/load_capacities.php");

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
  }

export async function fetchCapacitiesSSD() {
    const res = await safeFetch("./api/load_capacities_ssd.php");

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
  }