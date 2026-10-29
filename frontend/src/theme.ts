const storageKey = "salvadindin-theme";
export function preferredTheme(): string {
  try {const value = localStorage.getItem(storageKey); return value === "light" || value === "dark" ? value : "system";}
  catch {return "system";}
}
export function applyTheme(value: string, save = true) {
  if (value === "light" || value === "dark") document.documentElement.dataset.theme = value;
  else delete document.documentElement.dataset.theme;
  if (save) {try {localStorage.setItem(storageKey, value);} catch { /* Keep access usable when storage is unavailable. */ }}
}
