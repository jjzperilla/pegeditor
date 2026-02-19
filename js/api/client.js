// js/api/client.js
import { waitForAlertOk } from '../ui/modals.js';

const overlay = document.getElementById("pageOverlay");
const loaderBar = document.querySelector(".page-loader-bar");

let activeRequests = 0;
let progressTimer = null;

function startPageLoading() {
  activeRequests++;

  // overlay on
  if (overlay) overlay.classList.add("active");

  // bar on + start fake progress only once
  if (loaderBar) {
    loaderBar.style.opacity = "1";
    if (!progressTimer) {
      loaderBar.style.width = "15%";

      progressTimer = setInterval(() => {
        const current = parseFloat(loaderBar.style.width) || 0;
        if (current < 90) {
          loaderBar.style.width = (current + Math.random() * 10) + "%";
        }
      }, 250);
    }
  }
}

function finishPageLoading() {
  activeRequests = Math.max(0, activeRequests - 1);
  if (activeRequests > 0) return;

  // stop progress timer
  if (progressTimer) {
    clearInterval(progressTimer);
    progressTimer = null;
  }

  // finish bar
  if (loaderBar) {
    loaderBar.style.width = "100%";
    setTimeout(() => {
      loaderBar.style.opacity = "0";
      loaderBar.style.width = "0%";
    }, 300);
  }

  // hide overlay
  if (overlay) {
    setTimeout(() => overlay.classList.remove("active"), 250);
  }
}

function waitForElement(selector, timeoutMs = 5000) {
  return new Promise((resolve) => {
    const el = document.querySelector(selector);
    if (el) return resolve(el);

    const obs = new MutationObserver(() => {
      const found = document.querySelector(selector);
      if (found) {
        obs.disconnect();
        resolve(found);
      }
    });

    obs.observe(document.documentElement, { childList: true, subtree: true });

    // timeout fallback
    setTimeout(() => {
      obs.disconnect();
      resolve(null);
    }, timeoutMs);
  });
}

async function showForbiddenThenReload(message) {
  // If modal is available, use it
  if (typeof window.appAlert === "function") {
    window.appAlert(message);

    // Wait until OK button exists (in case modal renders it later)
    const okBtn = await waitForElement("#appAlertOk", 8000);

    if (okBtn) {
      await new Promise((resolve) => {
        okBtn.addEventListener("click", resolve, { once: true });
      });
      window.location.reload();
      return;
    }

    // If OK never appeared, fallback
    alert(message);
    window.location.reload();
    return;
  }

  // If modal not ready yet, fallback to native alert (blocks until OK)
  alert(message);
  window.location.reload();
}



let handlingForbidden = false;

export async function safeFetch(
  url,
  options = {},
  ui = { loading: true, forbidden: "reload" } // default behavior
) {
  if (ui.loading) startPageLoading();

  try {
    const resp = await fetch(url, {
      headers: { "Content-Type": "application/json" },
      ...options
    });

    const text = await resp.text();

    let data = null;
    try {
      data = text ? JSON.parse(text) : null;
    } catch {
      throw new Error("Server returned invalid JSON");
    }

    const isForbidden = resp.status === 403 || data?.status === "forbidden";
    if (isForbidden) {
      const msg = data?.message || "Editor access required";

      //background calls should NOT reload the page
      if (ui.forbidden === "ignore") return data;

      if (ui.forbidden === "throw") {
        const err = new Error(msg);
        err.code = "FORBIDDEN";
        throw err;
      }

      // forbidden === "reload" (only for explicit actions)
      if (!handlingForbidden) {
        handlingForbidden = true;
        await showForbiddenThenReload(msg);
      }

      return new Promise(() => {});
    }

    if (!resp.ok) {
      throw new Error(data?.message || `HTTP ${resp.status}`);
    }

    return data;

  } finally {
    if (ui.loading) finishPageLoading();
  }
}