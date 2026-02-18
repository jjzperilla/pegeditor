// js/api/client.js
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



export async function safeFetch(url, options = {}) {
  startPageLoading();

  try {
    const resp = await fetch(url, {
      headers: { "Content-Type": "application/json" },
      ...options
    });

    const text = await resp.text();

    try {
      return JSON.parse(text);
    } catch {
      throw new Error("Server returned invalid JSON");
    }

  } finally {
    finishPageLoading();
  }
}
