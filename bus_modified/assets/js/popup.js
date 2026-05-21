/* ──────────────────────────────────────────────
   popup.js — Système de notifications global
   Types : success | error | warning | info
────────────────────────────────────────────── */

const _popupIcons = {
    success: `<svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>`,
    error:   `<svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>`,
    warning: `<svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>`,
    info:    `<svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>`,
};

const _popupColors = {
    success: { bg: '#16a34a', ring: 'rgba(22,163,74,.25)' },
    error:   { bg: '#dc2626', ring: 'rgba(220,38,38,.25)'  },
    warning: { bg: '#d97706', ring: 'rgba(217,119,6,.25)'  },
    info:    { bg: '#2563eb', ring: 'rgba(37,99,235,.25)'  },
};

let _popupContainer = null;

function _getContainer() {
    if (_popupContainer && _popupContainer.isConnected) return _popupContainer;
    _popupContainer = document.createElement('div');
    _popupContainer.style.cssText = 'position:fixed;top:1.5rem;right:1.5rem;z-index:99999;display:flex;flex-direction:column;gap:.75rem;max-width:22rem;width:100%;pointer-events:none;';
    document.body.appendChild(_popupContainer);
    return _popupContainer;
}

function showPopup(message, type = 'success') {
    const c = _popupColors[type] || _popupColors.info;
    const icon = _popupIcons[type] || _popupIcons.info;
    const duration = type === 'error' ? 6000 : type === 'warning' ? 5000 : 3500;

    const el = document.createElement('div');
    el.style.cssText = `
        display:flex;align-items:flex-start;gap:.75rem;
        padding:.875rem 1.125rem;
        background:${c.bg};
        color:#fff;
        border-radius:.875rem;
        box-shadow:0 4px 24px ${c.ring},0 2px 8px rgba(0,0,0,.2);
        font-family:'Outfit',sans-serif;
        font-size:.875rem;
        font-weight:500;
        line-height:1.4;
        pointer-events:auto;
        transform:translateX(calc(100% + 2rem));
        transition:transform .3s cubic-bezier(.175,.885,.32,1.275), opacity .25s;
        opacity:0;
        cursor:default;
    `;
    el.innerHTML = `
        <span style="flex-shrink:0;margin-top:.05rem">${icon}</span>
        <span style="flex:1">${message}</span>
        <button style="background:none;border:none;color:rgba(255,255,255,.8);cursor:pointer;font-size:1.25rem;line-height:1;padding:0;margin-top:-.1rem;flex-shrink:0;" onclick="this.closest('[style]').remove()">×</button>
    `;

    _getContainer().appendChild(el);
    requestAnimationFrame(() => {
        el.style.transform = 'translateX(0)';
        el.style.opacity   = '1';
    });

    const hide = () => {
        el.style.opacity   = '0';
        el.style.transform = 'translateX(calc(100% + 2rem))';
        setTimeout(() => el.remove(), 300);
    };
    const timer = setTimeout(hide, duration);
    el.querySelector('button').addEventListener('click', () => { clearTimeout(timer); hide(); });
}

function showConfirm(message, onConfirm, options = {}) {
    const {
        title       = 'Confirmation',
        confirmText = 'Confirmer',
        cancelText  = 'Annuler',
        danger      = true,
    } = options;

    const overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:99998;display:flex;align-items:center;justify-content:center;padding:1rem;backdrop-filter:blur(2px);';

    const isDark = document.documentElement.classList.contains('dark');
    const cardBg = isDark ? '#0f2942' : '#ffffff';
    const textC  = isDark ? '#e2e8f0' : '#1e293b';
    const subC   = isDark ? '#94a3b8' : '#64748b';
    const cancelBg = isDark ? '#1a3a5c' : '#f1f5f9';
    const cancelC  = isDark ? '#e2e8f0' : '#334155';

    overlay.innerHTML = `
        <div style="background:${cardBg};border-radius:1.25rem;padding:2rem;max-width:22rem;width:100%;box-shadow:0 25px 60px rgba(0,0,0,.35);font-family:'Outfit',sans-serif;text-align:center;">
            <div style="width:3.5rem;height:3.5rem;background:${danger?'rgba(220,38,38,.1)':'rgba(37,99,235,.1)'};border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1.25rem;">
                ${danger
                    ? `<svg style="width:1.75rem;height:1.75rem;color:#dc2626;stroke:#dc2626" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>`
                    : `<svg style="width:1.75rem;height:1.75rem;stroke:#2563eb" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>`}
            </div>
            <h3 style="font-size:1.125rem;font-weight:700;color:${textC};margin-bottom:.5rem;">${title}</h3>
            <p style="color:${subC};font-size:.9rem;line-height:1.5;margin-bottom:1.5rem;">${message}</p>
            <div style="display:flex;gap:.75rem;">
                <button class="_cfCancel" style="flex:1;padding:.75rem;background:${cancelBg};color:${cancelC};border:none;border-radius:.75rem;font-weight:600;cursor:pointer;font-size:.9rem;font-family:inherit;">${cancelText}</button>
                <button class="_cfOk" style="flex:1;padding:.75rem;background:${danger?'#dc2626':'#2563eb'};color:#fff;border:none;border-radius:.75rem;font-weight:600;cursor:pointer;font-size:.9rem;font-family:inherit;">${confirmText}</button>
            </div>
        </div>
    `;

    document.body.appendChild(overlay);
    overlay.querySelector('._cfCancel').onclick = () => overlay.remove();
    overlay.querySelector('._cfOk').onclick     = () => { overlay.remove(); onConfirm(); };
    overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });
}

/* ── Dark mode helpers (partagés entre toutes les pages) ── */
function toggleDark() {
    const html = document.documentElement;
    const isDark = html.classList.toggle('dark');
    localStorage.setItem('dark', isDark);
}

function initDarkMode() {
    if (localStorage.getItem('dark') === 'true') {
        document.documentElement.classList.add('dark');
    }
}
