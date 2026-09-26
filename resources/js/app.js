document.querySelectorAll('[data-copy]').forEach((button) => {
    button.addEventListener('click', async () => {
        const target = document.querySelector(button.dataset.copy);
        if (!target) return;
        await navigator.clipboard.writeText(target.textContent.trim());
        const original = button.textContent;
        button.textContent = 'Tersalin';
        window.setTimeout(() => button.textContent = original, 1400);
    });
});

const refreshRoot = document.querySelector('[data-auto-refresh]');
if (refreshRoot) {
    const interval = Number(refreshRoot.dataset.autoRefresh || 30000);
    window.setTimeout(() => window.location.reload(), Math.max(interval, 10000));
}
