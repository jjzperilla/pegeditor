// js/api/peg.api.js
import { safeFetch } from './client.js';

  /* -------------------------------
     2. Load peg data
  -------------------------------- */
export async function fetchPegData(capacity, iface, condition, driveType) {
  const url =
    `./api/load_peg_data.php` +
    `?capacity=${encodeURIComponent(capacity)}` +
    `&interface=${encodeURIComponent(iface)}` +
    `&condition=${encodeURIComponent(condition)}` +
    `&drive_type=${encodeURIComponent(driveType)}`;

  const res = await safeFetch(url);

  if (res.status === "not_found") {
    return { status: "not_found" };
  }

  const margin =
    Number.isFinite(Number(res.margin_percent))
      ? Number(res.margin_percent)
      : undefined;

  return {
    status: "success",
    config_id: res.config_id ?? null,
    peg_name: res.peg_name ?? null,
    margin_percent: margin,
    inventoryMode: res.inventoryMode ?? "balanced",
    peg: {
      points: Array.isArray(res.peg?.points) ? res.peg.points : [],
      modifiers: Array.isArray(res.peg?.modifiers) ? res.peg.modifiers : [],
      sales: Array.isArray(res.peg?.sales) ? res.peg.sales : []
    }
  };
}