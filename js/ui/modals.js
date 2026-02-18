// ui/modals.js

export function initConfirmModal() {
  const appConfirmModal = document.getElementById("appConfirmModal");
  const appConfirmTitle = document.getElementById("appConfirmTitle");
  const appConfirmMessage = document.getElementById("appConfirmMessage");
  const appConfirmOk = document.getElementById("appConfirmOk");
  const appConfirmCancel = document.getElementById("appConfirmCancel");

  if (!appConfirmModal || !appConfirmTitle || !appConfirmMessage || !appConfirmOk || !appConfirmCancel) {
    console.warn("[modals] Confirm modal elements missing.");
    return { appConfirm: async () => false };
  }

  // prevent double-bind
  if (appConfirmModal.dataset.bound === "1") {
    return { appConfirm };
  }
  appConfirmModal.dataset.bound = "1";

  let confirmResolver = null;

  function appConfirm(message, title = "Confirm") {
    appConfirmTitle.textContent = title;
    appConfirmMessage.textContent = message;
    appConfirmModal.classList.remove("hidden");

    return new Promise((resolve) => {
      confirmResolver = resolve;
    });
  }

  function closeConfirm(result) {
    appConfirmModal.classList.add("hidden");
    if (confirmResolver) {
      confirmResolver(result);
      confirmResolver = null;
    }
  }

  appConfirmOk.addEventListener("click", () => closeConfirm(true));
  appConfirmCancel.addEventListener("click", () => closeConfirm(false));
  appConfirmModal.addEventListener("click", (e) => {
    if (e.target === appConfirmModal) closeConfirm(false);
  });

  return { appConfirm };
}

export function initAlertModal() {
  const appAlertModal = document.getElementById("appAlertModal");
  const appAlertTitle = document.getElementById("appAlertTitle");
  const appAlertMessage = document.getElementById("appAlertMessage");
  const appAlertOk = document.getElementById("appAlertOk");

  if (!appAlertModal || !appAlertTitle || !appAlertMessage || !appAlertOk) {
    console.warn("[modals] Alert modal elements missing.");
    return { appAlert: () => {} };
  }

  // prevent double-bind
  if (appAlertModal.dataset.bound === "1") {
    return { appAlert };
  }
  appAlertModal.dataset.bound = "1";

  function appAlert(message, title = "Notice") {
    appAlertTitle.textContent = title;
    appAlertMessage.textContent = message;
    appAlertModal.classList.remove("hidden");
  }

  function closeAppAlert() {
    appAlertModal.classList.add("hidden");
  }

  appAlertOk.addEventListener("click", closeAppAlert);
  appAlertModal.addEventListener("click", (e) => {
    if (e.target === appAlertModal) closeAppAlert();
  });

  return { appAlert };
}


export function waitForAlertOk() {
  return new Promise((resolve) => {
    const okBtn = document.getElementById("appAlertOk");
    if (!okBtn) return resolve();

    // one-time handler
    const handler = () => {
      okBtn.removeEventListener("click", handler);
      resolve();
    };

    okBtn.addEventListener("click", handler, { once: true });
  });
}
