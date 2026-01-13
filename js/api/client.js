// js/api/client.js
export async function safeFetch(url, options = {}) {
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
}