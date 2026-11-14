(() => {
    let saved;
    try { saved = localStorage.getItem('ccjnkr-theme'); } catch {}
    const systemDark = window.matchMedia?.('(prefers-color-scheme: dark)').matches;
    const theme = saved === 'light' || saved === 'dark' ? saved : (systemDark ? 'dark' : 'light');
    document.documentElement.dataset.theme = theme;
    document.documentElement.style.colorScheme = theme;
})();
