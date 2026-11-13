/* ============================================================
   LipaLink application shell
   Shared by: profile.html, settings.html, transactions.html, pay.html
   Provides: formatting helpers, session guard, header rendering,
             sidebar / dropdown wiring, logout, modal, API fetch wrapper
   ============================================================ */
(function (global) {
    'use strict';

    const SESSION_KEY = 'lipalink_user';
    const SETTINGS_KEY = 'lipalink_settings';

    // ---------- Formatting helpers ----------
    function formatTZS(amount) {
        const numeric = Number(amount);
        if (!Number.isFinite(numeric)) return 'TZS 0';
        return 'TZS ' + Math.round(numeric).toLocaleString('en-US');
    }

    function formatDate(dateString) {
        if (!dateString) return '—';
        const parsed = parseDate(dateString);
        if (!parsed) return escapeHtml(String(dateString));
        return parsed.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
    }

    function formatDateTime(dateString) {
        if (!dateString) return '—';
        const parsed = parseDate(dateString);
        if (!parsed) return escapeHtml(String(dateString));
        return parsed.toLocaleString('en-GB', {
            day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit'
        });
    }

    // MySQL "YYYY-MM-DD HH:MM:SS" is not valid ISO for Safari; normalise it.
    function parseDate(value) {
        if (value instanceof Date) return Number.isNaN(value.getTime()) ? null : value;
        let str = String(value).trim();
        if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/.test(str)) str = str.replace(' ', 'T');
        const d = new Date(str);
        return Number.isNaN(d.getTime()) ? null : d;
    }

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function getInitials(name) {
        if (!name) return 'CU';
        const parts = String(name).trim().split(/\s+/);
        if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
        return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
    }

    function getQueryParam(name) {
        return new URLSearchParams(window.location.search).get(name);
    }

    // ---------- Local session cache ----------
    function readStoredUser() {
        try {
            return JSON.parse(sessionStorage.getItem(SESSION_KEY) || 'null');
        } catch (_) {
            return null;
        }
    }

    function storeUser(user) {
        try { sessionStorage.setItem(SESSION_KEY, JSON.stringify(user)); } catch (_) { /* ignore */ }
    }

    function clearUser() {
        try { sessionStorage.removeItem(SESSION_KEY); } catch (_) { /* ignore */ }
    }

    function readSettings() {
        const defaults = {
            sms_reminders: true,
            email_receipts: true,
            whatsapp_updates: false,
            seller_updates: true,
            marketing: false,
            language: 'en'
        };
        try {
            const saved = JSON.parse(localStorage.getItem(SETTINGS_KEY) || 'null');
            return Object.assign({}, defaults, saved || {});
        } catch (_) {
            return defaults;
        }
    }

    function storeSettings(settings) {
        try { localStorage.setItem(SETTINGS_KEY, JSON.stringify(settings)); } catch (_) { /* ignore */ }
    }

    // ---------- API wrapper ----------
    async function apiFetch(url, options) {
        const opts = Object.assign({ credentials: 'same-origin' }, options || {});
        opts.headers = Object.assign({ 'Accept': 'application/json' }, (options && options.headers) || {});

        const response = await fetch(url, opts);

        if (response.status === 401) {
            clearUser();
            window.location.href = 'login.html';
            throw new Error('Your session has expired. Please log in again.');
        }

        let data;
        try {
            data = await response.json();
        } catch (_) {
            throw new Error('The server did not return a valid response. Please try again later.');
        }

        if (!response.ok || data.success === false) {
            throw new Error(data.message || 'The request could not be completed.');
        }
        return data;
    }

    function apiPostJson(url, body) {
        return apiFetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body || {})
        });
    }

    // ---------- Header rendering ----------
    function setText(id, text) {
        const el = document.getElementById(id);
        if (el) el.textContent = text;
    }

    function renderUserHeader(user) {
        const fullName = (user && (user.full_name || user.name)) || '';
        setText('headerProfileName', fullName || 'Customer');
        setText('dropdownUserName', fullName || 'Customer Account');
        setText('dropdownUserEmail', (user && (user.email || user.phone)) || '—');

        const avatarEl = document.getElementById('headerAvatar');
        if (avatarEl) {
            if (user && user.avatar_url) {
                avatarEl.innerHTML = '<img src="' + escapeHtml(user.avatar_url) + '" alt="' + escapeHtml(fullName) + '">';
            } else {
                avatarEl.textContent = getInitials(fullName);
            }
        }
    }

    /**
     * Confirms the signed-in user. Prefers the server session (api/profile.php);
     * falls back to the sessionStorage copy written by login.html so pages still
     * render on hosts where the PHP backend is not yet deployed.
     * Returns { user, online } or redirects to login.html and returns null.
     */
    async function requireSession() {
        try {
            const response = await fetch('api/profile.php', {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin'
            });
            if (response.status === 401) {
                clearUser();
                window.location.href = 'login.html';
                return null;
            }
            if (response.ok) {
                const data = await response.json();
                const user = (data.data && data.data.user) || data.user || data.customer;
                if (data.success && user) {
                    storeUser(user);
                    renderUserHeader(user);
                    return { user: user, online: true };
                }
            }
        } catch (_) {
            // Backend unavailable: fall through to cached session
        }

        const stored = readStoredUser();
        if (!stored) {
            window.location.href = 'login.html';
            return null;
        }
        renderUserHeader(stored);
        return { user: stored, online: false };
    }

    async function logout(event) {
        if (event) event.preventDefault();
        try {
            await fetch('api/logout.php', { method: 'POST', credentials: 'same-origin' });
        } catch (_) { /* ignore */ }
        clearUser();
        window.location.href = 'login.html';
    }

    // ---------- Inline alerts ----------
    function showAlert(id, type, message) {
        const el = document.getElementById(id);
        if (!el) return;
        const icons = {
            success: 'fa-solid fa-circle-check',
            error: 'fa-solid fa-circle-exclamation',
            info: 'fa-solid fa-circle-info'
        };
        el.className = 'inline-alert visible ' + type;
        el.innerHTML = '<i class="' + (icons[type] || icons.info) + '"></i><span>' + escapeHtml(message) + '</span>';
    }

    function hideAlert(id) {
        const el = document.getElementById(id);
        if (el) el.className = 'inline-alert';
    }

    // ---------- Modal ----------
    function openModal(title, bodyHtml, footerHtml) {
        const overlay = document.getElementById('actionModal');
        if (!overlay) return;
        document.getElementById('modalTitle').textContent = title;
        document.getElementById('modalBody').innerHTML = bodyHtml;
        document.getElementById('modalFoot').innerHTML = footerHtml ||
            '<button type="button" class="btn btn-outline btn-sm" data-modal-close>Close</button>';
        overlay.classList.add('open');
        overlay.querySelectorAll('[data-modal-close]').forEach(function (btn) {
            btn.addEventListener('click', closeModal);
        });
    }

    function closeModal() {
        const overlay = document.getElementById('actionModal');
        if (overlay) overlay.classList.remove('open');
    }

    // ---------- Shell interactions (sidebar, dropdowns, logout, active nav) ----------
    function initShell() {
        const sidebar = document.getElementById('sidebar');
        const backdrop = document.getElementById('sidebarBackdrop');
        const menuBtn = document.getElementById('mobileMenuBtn');
        const closeBtn = document.getElementById('sidebarClose');

        function toggleSidebar(open) {
            if (sidebar) sidebar.classList.toggle('open', open);
            if (backdrop) backdrop.classList.toggle('open', open);
        }
        if (menuBtn) menuBtn.addEventListener('click', function () { toggleSidebar(true); });
        if (closeBtn) closeBtn.addEventListener('click', function () { toggleSidebar(false); });
        if (backdrop) backdrop.addEventListener('click', function () { toggleSidebar(false); });

        const profileTrigger = document.getElementById('profileTrigger');
        const profileDropdown = document.getElementById('profileDropdown');
        if (profileTrigger && profileDropdown) {
            profileTrigger.addEventListener('click', function (e) {
                e.stopPropagation();
                const willOpen = !profileDropdown.classList.contains('open');
                profileDropdown.classList.toggle('open', willOpen);
                profileTrigger.setAttribute('aria-expanded', String(willOpen));
            });
            document.addEventListener('click', function () {
                profileDropdown.classList.remove('open');
                profileTrigger.setAttribute('aria-expanded', 'false');
            });
        }

        document.querySelectorAll('[data-logout]').forEach(function (btn) {
            btn.addEventListener('click', logout);
        });

        // Highlight the nav item for the current page (ignores in-page anchors)
        const currentFile = (window.location.pathname.split('/').pop() || 'dashboard.html').toLowerCase();
        document.querySelectorAll('.sidebar .nav-item[href]').forEach(function (link) {
            const href = link.getAttribute('href') || '';
            if (href.includes('#') || link.hasAttribute('data-logout')) return;
            const file = href.split('/').pop().toLowerCase();
            link.classList.toggle('active', file === currentFile);
        });

        // Modal close bindings
        const overlay = document.getElementById('actionModal');
        if (overlay) {
            const closeX = document.getElementById('modalCloseBtn');
            if (closeX) closeX.addEventListener('click', closeModal);
            overlay.addEventListener('click', function (e) {
                if (e.target === overlay) closeModal();
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') closeModal();
            });
        }
    }

    document.addEventListener('DOMContentLoaded', initShell);

    global.LipaLink = {
        formatTZS: formatTZS,
        formatDate: formatDate,
        formatDateTime: formatDateTime,
        parseDate: parseDate,
        escapeHtml: escapeHtml,
        getInitials: getInitials,
        getQueryParam: getQueryParam,
        readStoredUser: readStoredUser,
        storeUser: storeUser,
        clearUser: clearUser,
        readSettings: readSettings,
        storeSettings: storeSettings,
        apiFetch: apiFetch,
        apiPostJson: apiPostJson,
        renderUserHeader: renderUserHeader,
        requireSession: requireSession,
        logout: logout,
        showAlert: showAlert,
        hideAlert: hideAlert,
        openModal: openModal,
        closeModal: closeModal
    };
})(window);
