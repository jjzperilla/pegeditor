export function formatMoney(amount) {
  if (amount == null || isNaN(Number(amount))) return "$0.00";
  return "$" + Number(amount).toFixed(2);
}

export function escapeHtml(s) {
  if (!s) return '';
  return String(s).replace(/[&<>"'\/]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;', '/': '&#47;' }[c]));
}

export function capitalize(s) { if (!s) return ''; return String(s).charAt(0).toUpperCase() + String(s).slice(1); }


export function breadcrumbHome() {
  document.getElementById("crumbHistoryWrap")?.classList.add("hidden");
  document.getElementById("crumbEditorWrap")?.classList.add("hidden");
}

export function breadcrumbHistory() {
  document.getElementById("crumbHistoryWrap")?.classList.remove("hidden");
  document.getElementById("crumbEditorWrap")?.classList.add("hidden");
}

export function breadcrumbEditor() {
  document.getElementById("crumbHistoryWrap")?.classList.remove("hidden");
  document.getElementById("crumbEditorWrap")?.classList.remove("hidden");
}


export function initWorkspaceDropdownUI() {
  const sel = document.getElementById("workspaceSelect");
  const dd = document.getElementById("workspaceDD");
  const btn = document.getElementById("workspaceDDBtn");
  const menu = document.getElementById("workspaceDDMenu");
  const text = document.getElementById("workspaceDDText");

  if (!sel || !dd || !btn || !menu || !text) return;

  function close() {
    dd.classList.remove("open");
    btn.setAttribute("aria-expanded", "false");
  }

  function openClose() {
    const isOpen = dd.classList.toggle("open");
    btn.setAttribute("aria-expanded", String(isOpen));
  }

  function renderFromSelect() {
    // build menu from <option>s
    menu.innerHTML = "";

    const selectedOpt = sel.options[sel.selectedIndex];
    text.textContent = selectedOpt ? selectedOpt.textContent : "Select workspace";

    [...sel.options].forEach((opt) => {
      const item = document.createElement("button");
      item.type = "button";
      item.className = "dd__item";
      item.dataset.value = opt.value;
      item.textContent = opt.textContent;

      if (opt.selected) item.classList.add("is-active");

      item.addEventListener("click", (e) => {
        e.stopPropagation();

        // update real select
        sel.value = opt.value;

        // trigger your existing onchange handler
        if (typeof sel.onchange === "function") sel.onchange();

        close();
      });

      menu.appendChild(item);
    });
  }

  // toggle
  btn.addEventListener("click", (e) => {
    e.stopPropagation();
    openClose();
  });

  // close on outside click + ESC
  document.addEventListener("click", close);
  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape") close();
  });

  // if select changes elsewhere, re-render UI
  sel.addEventListener("change", renderFromSelect);

  // initial render
  renderFromSelect();
}


//oos check
let oosByCapacity = {};

export function normCap(v) {
  return String(v || "").trim().toUpperCase().replace(/\s+/g, "");
}
function getActiveWorkspaceId() {
  const sel = document.getElementById("workspaceSelect");
  const id = sel ? parseInt(sel.value, 10) : 0;
  return Number.isFinite(id) ? id : 0;
}

export async function loadOOSByCapacity() {
  const wsId = getActiveWorkspaceId();

  if (!wsId) {
    console.warn("No workspace selected; skipping OOS load");
    window.oosByCapacity = {};
    return;
  }

  const res = await fetch(`api/get_capacity_oos_status.php?workspace_id=${encodeURIComponent(wsId)}`, {
    credentials: "same-origin"
  });

  const data = await res.json();

  if (!data || data.status !== "ok") {
    console.warn("Failed to load OOS status", data);
    window.oosByCapacity = {};
    return;
  }

  const raw = data.oosByCapacity || {};
  const map = {};
  for (const [k, v] of Object.entries(raw)) {
    map[normCap(k)] = Number(v) === 1 ? 1 : 0;
  }

  window.oosByCapacity = map;
  console.log("OOS MAP LOADED (WS " + wsId + "):", window.oosByCapacity);
}
