//autosave
function scheduleAutosave() {
  hasUnsavedChanges = true;
  markUnsaved();

  clearTimeout(autosaveTimer);
  autosaveTimer = setTimeout(runAutosave, AUTOSAVE_DELAY);
}

async function runAutosave() {
  clearTimeout(autosaveTimer);
  autosaveTimer = null;

  try {
    isAutosaving = true;
    markSaving();

    await saveCurrentPegData({ silent: true });

    hasUnsavedChanges = false;
    markSaved(false);
  } catch (err) {
    markUnsaved();
  } finally {
    isAutosaving = false;
  }
}




function markSaving() {
  const el = document.getElementById("changeIndicator");
  if (!el) return;

  el.textContent = "Saving…";
  el.classList.remove("unsaved", "saved");
}

function markUnsaved() {
  const el = document.getElementById("changeIndicator");
  if (!el) return;

  el.textContent = "Unsaved changes";
  el.classList.add("unsaved");
  el.classList.remove("saved");
}

function markSaved(isManual = false) {
  const el = document.getElementById("changeIndicator");
  if (!el) return;

  lastSavedAt = new Date();

  const label = isManual
    ? `Saved at ${formatSaveTime(lastSavedAt)}`
    : `Saved at ${formatSaveTime(lastSavedAt)}`;

  el.textContent = label;
  el.classList.add("saved");
  el.classList.remove("unsaved");

  // optional fade after a few seconds
  setTimeout(() => {
    if (el.textContent === label) {
      el.textContent = label; // keep timestamp visible
    }
  }, 10000);
}

function clearChangeIndicator() {
  const el = document.getElementById("changeIndicator");
  if (!el) return;

  el.textContent = "";
  el.classList.remove("unsaved", "saved");
}
function exitHistoryModeIfNeeded() {
  if (isViewingHistory) {
    isViewingHistory = false;
    clearChangeIndicator();
  }
}

async function confirmIfUnsaved(
  message = "You have unsaved changes. Continue without saving?"
) {
  if (!hasUnsavedChanges) {
    return true;
  }

  if (isConfirmingUnsaved) {
    return false;
  }

  isConfirmingUnsaved = true;
  pauseAutosave();
  const ok = await appConfirm(message, "Unsaved Changes");


  isConfirmingUnsaved = false;
  resumeAutosave();

  if (!ok && hasUnsavedChanges) {
    scheduleAutosave();
  }

  return ok;
}


function resumeAutosaveAfterUnloadCancel() {
  setTimeout(() => {
    // If user is still on page, unload was canceled
    if (!hasUnsavedChanges) return;
    if (isAutosaving) return;
    if (isConfirmingUnsaved) return;
    if (isViewingHistory) return;

    resumeAutosave();
    scheduleAutosave();
  }, 500);
}

//pause autosave
function pauseAutosave() {
  autosavePaused = true;

  if (autosaveTimer) {
    clearTimeout(autosaveTimer);
    autosaveTimer = null;
  }
}

function resumeAutosave() {
  autosavePaused = false;
}