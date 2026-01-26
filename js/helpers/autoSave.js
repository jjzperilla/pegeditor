// autoSave.js
import { formatSaveTime } from './date.js';

let autosavePaused = false;
let autosaveTimer = null;
const AUTOSAVE_DELAY = 10000;
let autosaveToken = 0;
let isAutosaving = false;
let lastSavedAt = null;

// ✅ injected dependencies from app.js
let deps = null;
/**
 * deps = {
 *   getContext: () => ({ capacity, iface, cond, drive, configId }),
 *   getHasUnsaved: () => boolean,
 *   setHasUnsaved: (bool) => void,
 *   getIsViewingHistory: () => boolean,
 *   setIsViewingHistory: (bool) => void,
 *   saveCurrentPegData: async ({silent}) => void,
 *   appConfirm: async (msg, title) => boolean
 * }
 */
export function initAutosave(d) {
  deps = d;
}

// keep your exports
export function getAutosaveContext() {
  if (!deps) return null;
  return deps.getContext();
}

export function cancelAutosave() {
  autosaveToken++;
  if (autosaveTimer) {
    clearTimeout(autosaveTimer);
    autosaveTimer = null;
  }
}

export function scheduleAutosave(reason = "") {
  if (!deps) {
    console.warn("[AUTOSAVE] not initialized");
    return;
  }
  if (autosavePaused) return;

  deps.setHasUnsaved(true);
  markUnsaved();

  const ctx = deps.getContext();
  const snapshot = {
    token: ++autosaveToken,
    capacity: ctx.capacity,
    iface: ctx.iface,
    cond: ctx.cond,
    drive: ctx.drive,
    configId: Number(ctx.configId || 0)
  };

  if (autosaveTimer) clearTimeout(autosaveTimer);
  autosaveTimer = setTimeout(() => runAutosave(snapshot), AUTOSAVE_DELAY);
}

export async function runAutosave(snapshot) {
  if (!deps) return;

  const ctx = deps.getContext();

  const stillSame =
    snapshot.token === autosaveToken &&
    snapshot.capacity === ctx.capacity &&
    snapshot.iface === ctx.iface &&
    snapshot.cond === ctx.cond &&
    snapshot.drive === ctx.drive &&
    Number(snapshot.configId || 0) === Number(ctx.configId || 0);

  if (!stillSame) return;

  if (autosaveTimer) clearTimeout(autosaveTimer);
  autosaveTimer = null;

  if (autosavePaused) return;

  try {
    isAutosaving = true;
    markSaving();

    // ✅ use injected save function
    await deps.saveCurrentPegData({ silent: true });

    deps.setHasUnsaved(false);
    markSaved(false);
  } catch (err) {
    markUnsaved();
  } finally {
    isAutosaving = false;
  }
}

export function markSaving() {
  const el = document.getElementById("changeIndicator");
  if (!el) return;
  el.textContent = "Saving…";
  el.classList.remove("unsaved", "saved");
}

export function markUnsaved() {
  const el = document.getElementById("changeIndicator");
  if (!el) return;
  el.textContent = "Unsaved changes";
  el.classList.add("unsaved");
  el.classList.remove("saved");
}

export function markSaved(isManual = false) {
  const el = document.getElementById("changeIndicator");
  if (!el) return;

  lastSavedAt = new Date();
  const label = `Saved at ${formatSaveTime(lastSavedAt)}`;

  el.textContent = label;
  el.classList.add("saved");
  el.classList.remove("unsaved");
}

export function clearChangeIndicator() {
  const el = document.getElementById("changeIndicator");
  if (!el) return;
  el.textContent = "";
  el.classList.remove("unsaved", "saved");
}

export function exitHistoryModeIfNeeded() {
  if (!deps) return;
  if (deps.getIsViewingHistory()) {
    deps.setIsViewingHistory(false);
    clearChangeIndicator();
  }
}

// ✅ keep confirmIfUnsaved export too
let isConfirmingUnsaved = false;

export async function confirmIfUnsaved(
  message = "You have unsaved changes. Continue without saving?"
) {
  if (!deps) return true;
  if (!deps.getHasUnsaved()) return true;
  if (isConfirmingUnsaved) return false;

  isConfirmingUnsaved = true;
  pauseAutosave();

  const ok = await deps.appConfirm(message, "Unsaved Changes");

  isConfirmingUnsaved = false;
  resumeAutosave();

  if (!ok && deps.getHasUnsaved()) {
    scheduleAutosave("resume after cancel");
  }

  return ok;
}

export function resumeAutosaveAfterUnloadCancel() {
  setTimeout(() => {
    if (!deps) return;
    if (!deps.getHasUnsaved()) return;
    if (isAutosaving) return;
    if (isConfirmingUnsaved) return;
    if (deps.getIsViewingHistory()) return;

    resumeAutosave();
    scheduleAutosave("after unload cancel");
  }, 500);
}

export function pauseAutosave() {
  autosavePaused = true;
  if (autosaveTimer) {
    clearTimeout(autosaveTimer);
    autosaveTimer = null;
  }
}

export function resumeAutosave() {
  autosavePaused = false;
}
