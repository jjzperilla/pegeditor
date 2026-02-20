// manageApp.js
// Manage App Modal: Workspaces (Add/Rename/Delete + Members + Roles) + Users (Add/Edit/Delete)

let workspaceUsersCache = []; // filled by api/users_list.php

document.addEventListener("DOMContentLoaded", () => {
  const ALLOWED_ROLES = ["editor", "viewer"];

  // ---------------- DOM ----------------
  const modal = document.getElementById("manageAppModal");
  const btnOpen = document.getElementById("manageAppBtn");
  const btnClose = document.getElementById("manageAppClose");

  // Tabs (match your HTML)
  const tabBtns = Array.from(document.querySelectorAll(".manage-tab-btn"));
  const tabPanels = Array.from(document.querySelectorAll(".manage-tab-panel"));

  // Workspaces
  const wsListEl = document.getElementById("workspaceAdminList");
  const wsMsgEl = document.getElementById("workspaceModalMsg");
  const wsNewNameEl = document.getElementById("newWorkspaceName");
  const wsAddBtn = document.getElementById("btnAddWorkspace");

  // Users (Manage Users tab)
  const userListEl = document.getElementById("userAdminList");
  const userMsgEl = document.getElementById("userModalMsg");
  const newUseruser = document.getElementById("newUseruser");
  const newUserPass = document.getElementById("newUserPassword");
  const addUserBtn = document.getElementById("btnAddUser");
  const newUserEmail = document.getElementById("newUserEmail"); 
  
  // EMAIL NOTIFICATIONS
const oosEmailList = document.getElementById("oosEmailList");
const priceEmailList = document.getElementById("priceEmailList");

const oosEmailMsg = document.getElementById("oosEmailMsg");
const priceEmailMsg = document.getElementById("priceEmailMsg");

const newOosEmail = document.getElementById("newOosEmail");
const newOosName = document.getElementById("newOosName");
const btnAddOosEmail = document.getElementById("btnAddOosEmail");

const newPriceEmail = document.getElementById("newPriceEmail");
const newPriceName = document.getElementById("newPriceName");
const btnAddPriceEmail = document.getElementById("btnAddPriceEmail");


  if (!modal || !btnOpen || !btnClose) {
    console.warn("[manageApp] Missing modal elements:", {
      manageAppModal: !!modal,
      manageAppBtn: !!btnOpen,
      manageAppClose: !!btnClose,
    });
    return;
  }

  // --------------- helpers ---------------
  function showMsg(el, text, isError = false) {
    if (!el) {
      console.warn("[manageApp] showMsg missing target:", text);
      return;
    }
    el.style.display = "block";
    el.textContent = text;
    el.style.background = isError ? "#ffe8e8" : "#f6f6f6";
    el.style.border = isError ? "1px solid #ffb6b6" : "1px solid #eee";
    clearTimeout(el._t);
    el._t = setTimeout(() => (el.style.display = "none"), 2500);
  }

  function escapeHTML(str) {
    return String(str ?? "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  async function fetchJSON(url, options = {}) {
    const res = await fetch(url, {
      credentials: "same-origin",
      headers: { "Content-Type": "application/json" },
      ...options,
    });

    const data = await res.json().catch(() => ({}));

    if (!res.ok) throw new Error(data.message || `Request failed (${res.status})`);
    if (data.status === "unauthorized") throw new Error("Unauthorized. Please login again.");
    if (data.status === "error") throw new Error(data.message || "Request error");

    return data;
  }

  function roleSelectHTML(selected) {
    const sel = selected || "viewer";
    return `
      <select class="role-select">
        ${ALLOWED_ROLES.map((r) => `<option value="${r}" ${r === sel ? "selected" : ""}>${r}</option>`).join("")}
      </select>
    `;
  }

  function userOptionsHTML() {
    const opts = workspaceUsersCache
      .map((u) => `<option value="${u.id}">${escapeHTML(u.label || u.user || ("User #" + u.id))}</option>`)
      .join("");
    return `<option value="">Select user...</option>${opts}`;
  }

  // --------------- modal open/close ---------------
  function openModal() {
    modal.style.display = "flex";
    switchTab("tab-workspaces");
    // Load everything needed
    loadUsersCache().finally(() => {
      loadWorkspaces();
      // Users list will load when tab is clicked (or you can load now)
    });
  }

  function closeModal() {
    modal.style.display = "none";
  }

  modal.addEventListener("click", (e) => {
    if (e.target === modal) closeModal();
  });

  btnOpen.addEventListener("click", openModal);
  btnClose.addEventListener("click", closeModal);

  // --------------- tabs ---------------
  function switchTab(tabId) {
    tabBtns.forEach((b) => b.classList.toggle("active", b.dataset.tab === tabId));
    tabPanels.forEach((p) => p.classList.toggle("active", p.id === tabId));
  }

  tabBtns.forEach((btn) => {
    btn.addEventListener("click", () => {
      const tabId = btn.dataset.tab;
      switchTab(tabId);
      if (tabId === "tab-workspaces") loadWorkspaces();
      if (tabId === "tab-users") loadUsers();
      if (tabId === "tab-email") {loadEmailRecipients("OOS");loadEmailRecipients("PRICE");}
    });
  });

  // --------------- load users cache (for member dropdown) ---------------
  async function loadUsersCache() {
    try {
      const data = await fetchJSON("api/users_list.php");
      workspaceUsersCache = Array.isArray(data.users) ? data.users : [];
    } catch (e) {
      workspaceUsersCache = [];
      // don't block modal; only member-add dropdown will be empty
      showMsg(wsMsgEl, e.message || "Failed to load users list", true);
    }
  }

  // =========================================================
  // WORKSPACES (Add/Rename/Delete) + MEMBERS + ROLES
  // =========================================================
  async function loadWorkspaces() {
    if (!wsListEl) return;
    wsListEl.innerHTML = "Loading...";
    try {
      const data = await fetchJSON("api/workspaces.php");
      const workspaces = Array.isArray(data.workspaces) ? data.workspaces : [];
      renderWorkspaces(workspaces);
    } catch (e) {
      wsListEl.innerHTML = "";
      showMsg(wsMsgEl, e.message || "Failed to load workspaces", true);
    }
  }

  function renderWorkspaces(workspaces) {
    if (!wsListEl) return;

    if (!workspaces.length) {
      wsListEl.innerHTML = "<div class='ws-msg'>No workspaces found.</div>";
      return;
    }

    wsListEl.innerHTML = "";

    workspaces.forEach((ws) => {
      const wid = Number(ws.id || 0);
      const active = !!ws.active;

      const row = document.createElement("div");
      row.className = "ws-row";
      row.dataset.id = String(wid);

      row.innerHTML = `
        <div>
          <input class="ws-name" value="${escapeHTML(ws.name)}" />
          <div class="ws-meta" >
            ID: ${wid}
            ${active ? " • Active" : ""}
            ${ws.role ? " • Your role: " + escapeHTML(ws.role) : ""}
          </div>
        </div>

        <div class="ws-actions">
          <button class="ws-save" type="button">Save</button>
          <button class="ws-delete" type="button">Delete</button>
          <button class="ws-members-toggle" type="button" data-action="toggleMembers">
          <span>Members</span>
          <span class="chevron">▼</span>
          </button>
        </div>

        <div class="ws-members" style="grid-column: 1 / -1; display:none;"></div>
      `;

      
      // rename
      const btnSave = row.querySelector(".ws-save");
      if (btnSave) {
        btnSave.addEventListener("click", async () => {
          const name = (row.querySelector(".ws-name")?.value || "").trim();
          if (!name) return showMsg(wsMsgEl, "Workspace name cannot be empty", true);

          try {
            await fetchJSON("api/workspace_update.php", {
              method: "POST",
              body: JSON.stringify({ workspace_id: wid, name }),
            });
            showMsg(wsMsgEl, "Workspace updated");
            loadWorkspaces();
          } catch (e) {
            showMsg(wsMsgEl, e.message || "Update failed", true);
          }
        });
      }

      // delete
      const btnDel = row.querySelector(".ws-delete");
      if (btnDel) {
        btnDel.addEventListener("click", async () => {
          if (!confirm(`Delete workspace "${ws.name}"?`)) return;

          try {
            await fetchJSON("api/workspace_delete.php", {
              method: "POST",
              body: JSON.stringify({ workspace_id: wid }),
            });
            showMsg(wsMsgEl, "Workspace deleted");
            loadWorkspaces();
          } catch (e) {
            showMsg(wsMsgEl, e.message || "Delete failed", true);
          }
        });
      }

      // members toggle
      // members toggle
const membersBox = row.querySelector(".ws-members");
const btnMembers = row.querySelector(".ws-members-toggle");

if (btnMembers && membersBox) {
  // ensure initial state is closed (optional)
  if (!membersBox.style.display) membersBox.style.display = "none";
  btnMembers.setAttribute("aria-expanded", "false");
  const chevInit = btnMembers.querySelector(".chevron");
  if (chevInit) chevInit.textContent = "▼";

  btnMembers.addEventListener("click", async () => {
    const wasOpen = membersBox.style.display === "block";

    // toggle panel
    membersBox.style.display = wasOpen ? "none" : "block";

    // state AFTER toggle
    const nowOpen = membersBox.style.display === "block";

    // update chevron + aria
    btnMembers.setAttribute("aria-expanded", String(nowOpen));
    const chev = btnMembers.querySelector(".chevron");
    if (chev) chev.textContent = nowOpen ? "▲" : "▼";

    // load members only when opening
    if (nowOpen) {
      await loadWorkspaceMembers(wid, membersBox);
    }
  });
}

      wsListEl.appendChild(row);
    });
  }

  async function loadWorkspaceMembers(workspaceId, container) {
    container.innerHTML = "Loading members...";
    try {
      const data = await fetchJSON("api/workspace_members.php", {
        method: "POST",
        body: JSON.stringify({ workspace_id: workspaceId }),
      });

      const members = Array.isArray(data.members) ? data.members : [];

      // user dropdown uses cache (if empty, still shows Select user...)
      container.innerHTML = `
        <div style="display:flex; align-items:center; justify-content:space-between; gap:10px;">
          <div><b>Members</b></div>
          <button class="member-refresh" type="button">Refresh</button>
        </div>

        <div class="member-list" style="margin-top:8px;"></div>
<span style="display: inline-block;margin-top: 5px;"><b>Add New User</b></span>
        <div class="member-add">
          <select class="member-user-select">
            ${userOptionsHTML()}
          </select>
          ${roleSelectHTML("viewer")}
          
          <button class="member-add-btn" type="button">Add</button>
        </div>
      `;

      const btnRefresh = container.querySelector(".member-refresh");
      if (btnRefresh) {
        btnRefresh.addEventListener("click", async () => {
          await loadWorkspaceMembers(workspaceId, container);
        });
      }

      const list = container.querySelector(".member-list");

      if (!members.length) {
        if (list) list.innerHTML = `<div class="ws-msg">No members yet.</div>`;
      } else if (list) {
        list.innerHTML = "";

        members.forEach((m) => {
          const userId = Number(m.user_id || 0);
          const displayName = m.display_name || `User #${userId}`;
          const memberRole = m.role || "viewer";

          const div = document.createElement("div");
          div.className = "member-row";

          div.innerHTML = `
            <div>
              <div><b>${escapeHTML(displayName)}</b></div>
              <div class="ws-meta">User ID: ${userId}</div>
            </div>

            <div>${roleSelectHTML(memberRole)}</div>

            <div class="member-actions" style="display:flex; gap:8px;">
              <button class="member-save" type="button">Save</button>
              <button class="member-remove" type="button">Remove</button>
            </div>
          `;

          // save role
          div.querySelector(".member-save")?.addEventListener("click", async () => {
            const newRole = div.querySelector(".role-select")?.value || "viewer";
            try {
              await fetchJSON("api/workspace_member_update_role.php", {
                method: "POST",
                body: JSON.stringify({
                  workspace_id: workspaceId,
                  user_id: userId,
                  role: newRole,
                }),
              });
              showMsg(wsMsgEl, "Role updated");
              await loadWorkspaceMembers(workspaceId, container);
            } catch (e) {
              showMsg(wsMsgEl, e.message || "Failed to update role", true);
            }
          });

          // remove member
          div.querySelector(".member-remove")?.addEventListener("click", async () => {
            if (!confirm(`Remove ${displayName} from this workspace?`)) return;
            try {
              await fetchJSON("api/workspace_member_remove.php", {
                method: "POST",
                body: JSON.stringify({
                  workspace_id: workspaceId,
                  user_id: userId,
                }),
              });
              showMsg(wsMsgEl, "Member removed");
              await loadWorkspaceMembers(workspaceId, container);
            } catch (e) {
              showMsg(wsMsgEl, e.message || "Failed to remove member", true);
            }
          });

          list.appendChild(div);
        });
      }

      // add member (dropdown)
      container.querySelector(".member-add-btn")?.addEventListener("click", async () => {
        const select = container.querySelector(".member-user-select");
        const uid = parseInt(select?.value || "", 10) || 0;
        const role = container.querySelector(".member-add .role-select")?.value || "viewer";

        if (!uid) {
          showMsg(wsMsgEl, "Please select a user first", true);
          return;
        }

        try {
          await fetchJSON("api/workspace_member_add.php", {
            method: "POST",
            body: JSON.stringify({
              workspace_id: workspaceId,
              user_id: uid,
              role,
            }),
          });
          showMsg(wsMsgEl, "Member added");
          await loadWorkspaceMembers(workspaceId, container);
        } catch (e) {
          showMsg(wsMsgEl, e.message || "Failed to add member", true);
        }
      });
    } catch (e) {
      container.innerHTML = "";
      showMsg(wsMsgEl, e.message || "Failed to load members", true);
    }
  }

  // add workspace
  if (wsAddBtn) {
    wsAddBtn.addEventListener("click", async () => {
      const name = (wsNewNameEl?.value || "").trim();
      if (!name) return showMsg(wsMsgEl, "Enter a workspace name", true);

      try {
        await fetchJSON("api/workspace_create.php", {
          method: "POST",
          body: JSON.stringify({ name }),
        });
        if (wsNewNameEl) wsNewNameEl.value = "";
        showMsg(wsMsgEl, "Workspace added");
        loadWorkspaces();
      } catch (e) {
        showMsg(wsMsgEl, e.message || "Add failed", true);
      }
    });
  } else {
    console.warn("[manageApp] Missing #btnAddWorkspace (no handler attached)");
  }

  // =========================================================
  // USERS TAB (Add/Edit/Delete)
  // =========================================================
  async function loadUsers() {
    if (!userListEl) return;
    userListEl.innerHTML = "Loading...";
    try {
      const data = await fetchJSON("api/users_list.php");
      const users = Array.isArray(data.users) ? data.users : [];
      renderUsers(users);
    } catch (e) {
      userListEl.innerHTML = "";
      showMsg(userMsgEl, e.message || "Failed to load users", true);
    }
  }

function renderUsers(users) {
  if (!userListEl) return;

  if (!users.length) {
    userListEl.innerHTML = "<div class='ws-msg'>No users found.</div>";
    return;
  }

  userListEl.innerHTML = "";

  users.forEach((u) => {
    const row = document.createElement("div");
    row.className = "user-list";

    row.innerHTML = `
      <div class="user-wrapper" style="display:flex; gap:8px;">
        <input class="user-name" value="${escapeHTML(u.user_name || u.user || u.label || "")}" />

        <input class="user-email" type="email" placeholder="Email (optional)"
               value="${escapeHTML(u.email || "")}" />

        <div style="display:flex; gap:8px; align-items:center;">
          <input class="user-newpass" type="password" placeholder="New password (optional)" />
          <button class="user-togglepass" type="button">Show</button>
        </div>
      </div>

      <div class="ws-actions-user">
        <button class="user-save" type="button">Save</button>
        <button class="user-delete" type="button">Delete</button>
      </div>

      <div class="ws-meta" hidden>ID: ${u.id}</div>
    `;

    // Show/Hide password (ONLY for the "new password" field)
    const passInput = row.querySelector(".user-newpass");
    const btnToggle = row.querySelector(".user-togglepass");
    if (btnToggle && passInput) {
      btnToggle.addEventListener("click", () => {
        const isHidden = passInput.type === "password";
        passInput.type = isHidden ? "text" : "password";
        btnToggle.textContent = isHidden ? "Hide" : "Show";
      });
    }

    // Save (update name + optional email + optional password)
    row.querySelector(".user-save")?.addEventListener("click", async () => {
      const user_name = (row.querySelector(".user-name")?.value || "").trim();
      const email = (row.querySelector(".user-email")?.value || "").trim();
      const password = (row.querySelector(".user-newpass")?.value || "").trim();

      if (!user_name) return showMsg(userMsgEl, "User name required", true);

      try {
        await fetchJSON("api/user_update.php", {
          method: "POST",
          body: JSON.stringify({
            user_id: u.id,
            user_name,
            email,     // optional
            password   // optional
          })
        });

        // clear only the password box after save
        if (passInput) {
          passInput.value = "";
          passInput.type = "password";
          if (btnToggle) btnToggle.textContent = "Show";
        }

        showMsg(userMsgEl, "User saved");
        await loadUsersCache();
        loadUsers();
      } catch (e) {
        showMsg(userMsgEl, e.message || "Save failed", true);
      }
    });

    // Delete
    row.querySelector(".user-delete")?.addEventListener("click", async () => {
      if (!confirm(`Delete user ID ${u.id}?`)) return;

      try {
        await fetchJSON("api/user_delete.php", {
          method: "POST",
          body: JSON.stringify({ user_id: u.id })
        });

        showMsg(userMsgEl, "User deleted");
        await loadUsersCache();
        loadUsers();
      } catch (e) {
        showMsg(userMsgEl, e.message || "Delete failed", true);
      }
    });

    userListEl.appendChild(row);
  });
}




if (addUserBtn) {
  addUserBtn.addEventListener("click", async () => {
    const user = (newUseruser?.value || "").trim();
    const password = (newUserPass?.value || "").trim();
    const email = (newUserEmail?.value || "").trim(); // optional

    if (!user || !password) {
      return showMsg(userMsgEl, "user and password required", true);
    }

    try {
      await fetchJSON("api/user_create.php", {
        method: "POST",
        body: JSON.stringify({ user, password, email }), // include email
      });

      if (newUseruser) newUseruser.value = "";
      if (newUserPass) newUserPass.value = "";
      if (newUserEmail) newUserEmail.value = "";

      showMsg(userMsgEl, "User added");
      await loadUsersCache();
      loadUsers();
    } catch (e) {
      showMsg(userMsgEl, e.message || "Add failed", true);
    }
  });
}
 else {
    console.warn("[manageApp] Missing #btnAddUser (no handler attached)");
  }
  
 //email manage
async function loadEmailRecipients(type) {
  const listEl = type === "OOS" ? oosEmailList : priceEmailList;
  const msgEl = type === "OOS" ? oosEmailMsg : priceEmailMsg;

  if (!listEl) return;

  listEl.innerHTML = "Loading...";

  try {
    const data = await fetchJSON("api/email_notif_list.php", {
      method: "POST",
      body: JSON.stringify({ notif_type: type })
    });

    renderEmailRecipients(type, data.recipients || []);
  } catch (e) {
    listEl.innerHTML = "";
    showMsg(msgEl, e.message || "Failed to load emails", true);
  }
}

function renderEmailRecipients(type, rows) {
  const listEl = type === "OOS" ? oosEmailList : priceEmailList;
  const msgEl = type === "OOS" ? oosEmailMsg : priceEmailMsg;

  if (!listEl) return;

  if (!rows.length) {
    listEl.innerHTML = "<div class='ws-msg'>No recipients.</div>";
    return;
  }

  listEl.innerHTML = "";

  rows.forEach(r => {
    const row = document.createElement("div");
    row.className = "mail-list";

    row.innerHTML = `
      <div>
        <input class="er-email" value="${escapeHTML(r.email)}" />
        <input class="er-name" value="${escapeHTML(r.name || "")}" placeholder="Name (optional)" / hidden>
        <div class="ws-meta" hidden>ID: ${r.contact_id}</div>
      </div>

      <div class="ws-actions">
        <button class="er-save" type="button">Save</button>
        <button class="er-delete" type="button">Delete</button>
      </div>
    `;

    // SAVE
    row.querySelector(".er-save").addEventListener("click", async () => {
      const email = row.querySelector(".er-email").value.trim();
      const name = row.querySelector(".er-name").value.trim();

      try {
        await fetchJSON("api/email_notif_update_contact.php", {
          method: "POST",
          body: JSON.stringify({
            contact_id: r.contact_id,
            email,
            name
          })
        });

        showMsg(msgEl, "Saved");
        loadEmailRecipients(type);
      } catch (e) {
        showMsg(msgEl, e.message || "Update failed", true);
      }
    });

    // DELETE (remove only from this notification type)
    row.querySelector(".er-delete").addEventListener("click", async () => {
      if (!confirm(`Remove ${r.email}?`)) return;

      try {
        await fetchJSON("api/email_notif_remove.php", {
          method: "POST",
          body: JSON.stringify({
            subscription_id: r.subscription_id
          })
        });

        showMsg(msgEl, "Removed");
        loadEmailRecipients(type);
      } catch (e) {
        showMsg(msgEl, e.message || "Delete failed", true);
      }
    });

    listEl.appendChild(row);
  });
}

btnAddOosEmail?.addEventListener("click", async () => {
  if (!newOosEmail || !newOosName || !oosEmailMsg) {
    console.warn("[manageApp] Missing OOS email inputs", {
      newOosEmail: !!newOosEmail,
      newOosName: !!newOosName,
      oosEmailMsg: !!oosEmailMsg
    });
    return;
  }

  const email = (newOosEmail.value || "").trim();
  const name  = (newOosName.value || "").trim();

  if (!email) return showMsg(oosEmailMsg, "Email required", true);

  try {
    await fetchJSON("api/email_notif_add.php", {
      method: "POST",
      body: JSON.stringify({ notif_type: "OOS", email, name })
    });

    newOosEmail.value = "";
    newOosName.value = "";
    showMsg(oosEmailMsg, "Added");
    loadEmailRecipients("OOS");
  } catch (e) {
    showMsg(oosEmailMsg, e.message || "Add failed", true);
  }
});


btnAddPriceEmail?.addEventListener("click", async () => {
  if (!newPriceEmail || !newPriceName || !priceEmailMsg) {
    console.warn("[manageApp] Missing PRICE email inputs", {
      newPriceEmail: !!newPriceEmail,
      newPriceName: !!newPriceName,
      priceEmailMsg: !!priceEmailMsg
    });
    return;
  }

  const email = (newPriceEmail.value || "").trim();
  const name  = (newPriceName.value || "").trim();

  if (!email) return showMsg(priceEmailMsg, "Email required", true);

  try {
    await fetchJSON("api/email_notif_add.php", {
      method: "POST",
      body: JSON.stringify({ notif_type: "PRICE", email, name })
    });

    newPriceEmail.value = "";
    newPriceName.value = "";
    showMsg(priceEmailMsg, "Added");
    loadEmailRecipients("PRICE");
  } catch (e) {
    showMsg(priceEmailMsg, e.message || "Add failed", true);
  }
});

 
  
});

