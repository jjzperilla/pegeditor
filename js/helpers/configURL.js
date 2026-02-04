export function getUrlConfigId() {
  const url = new URL(window.location.href);
  return Number(url.searchParams.get("config_id") || 0) || 0;
}

export function setUrlConfigId(configId) {
  const url = new URL(window.location.href);
  if (configId && Number(configId) > 0) url.searchParams.set("config_id", String(configId));
  else url.searchParams.delete("config_id");
  history.replaceState({ config_id: configId || null }, "", url.toString());
}

export function resetUrlAfterWorkspaceChange() {
  const url = new URL(window.location.href);
  url.searchParams.delete("config_id");
  window.history.replaceState({}, "", url.pathname);
}

