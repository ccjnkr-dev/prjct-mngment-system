const storageKey = 'ccjnkr-theme';
const system = window.matchMedia('(prefers-color-scheme: dark)');
const readSaved = () => {
    try { return localStorage.getItem(storageKey); } catch { return null; }
};
function apply(theme) {
    document.documentElement.dataset.theme = theme;
    document.documentElement.style.colorScheme = theme;
    const action = theme === 'dark' ? 'Light mode' : 'Dark mode';
    document.querySelectorAll('[data-theme-toggle]').forEach(button => {
        button.setAttribute('aria-label', `Switch to ${action.toLowerCase()}`);
        button.title = `Switch to ${action.toLowerCase()}`;
        button.querySelector('[data-theme-label]').textContent = action;
    });
}
function preferred() {
    const saved = readSaved();
    return saved === 'light' || saved === 'dark' ? saved : (system.matches ? 'dark' : 'light');
}
apply(document.documentElement.dataset.theme || preferred());
document.addEventListener('click', event => {
    if (!event.target.closest('[data-theme-toggle]')) return;
    const theme = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
    try { localStorage.setItem(storageKey, theme); } catch {}
    apply(theme);
});
system.addEventListener('change', () => {
    if (!['light', 'dark'].includes(readSaved())) apply(preferred());
});
window.addEventListener('storage', event => {
    if (event.key === storageKey || event.key === null) apply(preferred());
});
window.addEventListener('pageshow', event => { if (event.persisted) apply(preferred()); });
