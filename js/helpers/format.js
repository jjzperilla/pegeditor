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
