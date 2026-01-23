// js/pegEditLock.js

const editSessionId =
  (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : `${Date.now()}-${Math.random()}`;

let lockTimer = null;
let lockedConfigId = null;
let isViewMode = false;
let prevViewMode = false;
let lockPending = false;


function setViewMode(on) {
  isViewMode = !!on;
  lockPending = false;
  applyLocks();

  const banner = document.getElementById("viewOnlyBanner");
  if (banner) banner.style.display = isViewMode ? "block" : "none";

  const roots = [
    document.getElementById("pegInputsRoot"),
    document.getElementById("salesTableBody")
  ].filter(Boolean);

  roots.forEach(root => {
    root.querySelectorAll("input, select, textarea, button").forEach(el => {
      if (el.id === "clearPegSelectBtn") return;
      el.disabled = isViewMode;
    });
  });

  const saveBtn = document.getElementById("savePegBtn");
  if (saveBtn) saveBtn.disabled = isViewMode;
}

async function lockHeartbeat(configId) {
  const cid = Number(configId || window.currentConfigId || 0);
  if (!cid || !editSessionId) return;

  const res = await fetch("./api/peg_edit_lock.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ config_id: cid, session_id: editSessionId })
  });

  const json = await res.json();
  if (json.status !== "ok") throw new Error(json.message || "Lock error");

  const nowViewMode = json.mode === "view";
  setViewMode(nowViewMode);
    
if (nowViewMode) {
  // refresh at most once every 8 seconds to avoid spam
  if (!lockHeartbeat._lastRefresh || Date.now() - lockHeartbeat._lastRefresh > 8000) {
    lockHeartbeat._lastRefresh = Date.now();
  }
}
    
  if (prevViewMode && !nowViewMode) {
      window.dispatchEvent(new CustomEvent("peg:refreshRequested"));
  }

  prevViewMode = nowViewMode;
}


export function startPegLock(configId) {
  const cid = Number(configId || window.currentConfigId || 0);
  if (!cid || !editSessionId) return;
  if (lockedConfigId && Number(lockedConfigId) === cid) return;
  stopPegLock();
  lockedConfigId = cid;
  lockPending = true;
  applyLocks();
  lockHeartbeat(cid).catch(console.warn);
  setTimeout(() => {
    if (lockedConfigId === cid) lockHeartbeat(cid).catch(console.warn);
  }, 10);
  lockTimer = setInterval(() => {
    lockHeartbeat(cid).catch(console.warn);
  }, 12000);
}

export function stopPegLock() {
  if (lockTimer) clearInterval(lockTimer);
  lockTimer = null;
  const cid = lockedConfigId;
  lockedConfigId = null;
  setViewMode(false);
  if (!cid) return;
  const payload = JSON.stringify({ config_id: cid, session_id: editSessionId });
  if (navigator.sendBeacon) {
    navigator.sendBeacon(
      "./api/peg_edit_unlock.php",
      new Blob([payload], { type: "application/json" })
    );
    return;
  }
  fetch("./api/peg_edit_unlock.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: payload,
    keepalive: true
  }).catch(() => {});
}


export function applyLocks() {
  const locked = isViewMode || lockPending;

  const banner = document.getElementById("viewOnlyBanner");
  if (banner) banner.style.display = isViewMode ? "block" : "none";

  const roots = [
    document.getElementById("pegInputsRoot"),
    document.getElementById("salesTableBody")
  ].filter(Boolean);

  roots.forEach(root => {
    root.querySelectorAll("input, select, textarea, button").forEach(el => {
      if (el.id === "clearPegSelectBtn") return;
      el.disabled = locked;
    });
  });

  const saveBtn = document.getElementById("savePegBtn");
  if (saveBtn) saveBtn.disabled = locked;
}

export function getIsViewMode() {
  return isViewMode;
}

window.addEventListener("beforeunload", () => {
  if (!lockedConfigId) return;
  navigator.sendBeacon?.(
    "./api/peg_edit_unlock.php",
    new Blob(
      [JSON.stringify({ config_id: lockedConfigId, session_id: editSessionId })],
      { type: "application/json" }
    )
  );
});

