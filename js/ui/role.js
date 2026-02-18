//Apply roleUI
export function applyRoleUI(role) {
  const isEditor = role === "editor";

  // Disable buttons
  const disableIds = [
    "savePegBtn",
    "addRowBtn",
    "addModifierBtn",
    "addSaleModifierBtn",
    "addNewCapacityBtn",
    "addNewSSDCapacityBtn"
  ];

  disableIds.forEach(id => {
    const el = document.getElementById(id);
    if (el) el.disabled = !isEditor;
  });

  // Hide destructive actions
  document.querySelectorAll('[data-action="deleteHistory"]').forEach(btn => {
    btn.style.display = isEditor ? "" : "none";
  });

  // Make inputs read-only if viewer
  document.querySelectorAll("#chartsContainer input, #chartsContainer textarea, #chartsContainer select")
    .forEach(el => {
      // allow filters even for viewer
      const allow = el.id === "filterDriveType" || el.id === "filterInterface" || el.id === "filterCondition" || el.id === "historyRangeSelect" || el.id === "avgPegRange";
      if (allow) return;

      if (!isEditor) {
        if (el.tagName === "SELECT") el.disabled = true;
        else el.readOnly = true;
      } else {
        if (el.tagName === "SELECT") el.disabled = false;
        else el.readOnly = false;
      }
    });

  // Optional: show badge
  const roleBadge = document.getElementById("roleBadge");
  if (roleBadge) roleBadge.textContent = isEditor ? "Editor" : "Viewer";
}

export function updateUserBadge(data) {
  const userEl = document.getElementById("roleUser");
  const badgeEl = document.getElementById("roleBadge");

  if (userEl) {
    userEl.textContent = data?.username || "User";
  }

  if (badgeEl) {
    const role = data?.role || "viewer";

    badgeEl.textContent = role.charAt(0).toUpperCase() + role.slice(1);

    badgeEl.classList.remove("role-editor", "role-viewer");

    if (role === "editor") {
      badgeEl.classList.add("role-editor");
    } else {
      badgeEl.classList.add("role-viewer");
    }
  }
}

export function setUserRoleUI({ username = "User", role = "viewer" } = {}) {
  const userEl = document.getElementById("roleUser");
  const badgeEl = document.getElementById("roleBadge");

  const r = String(role || "viewer").toLowerCase();
  const isEditor = r === "editor";

  if (userEl) userEl.textContent = username;

  if (badgeEl) {
    badgeEl.textContent = isEditor ? "Editor" : "Viewer";
    badgeEl.classList.remove("is-editor", "is-viewer");
    badgeEl.classList.add(isEditor ? "is-editor" : "is-viewer");
  }
}

