/**
 * "Continue with Google" for login.html and register.html.
 *
 * Any element with data-google-auth="login" or data-google-auth="register" becomes a Google
 * sign-in trigger. Uses Google Identity Services' OAuth token flow (popup), then hands the
 * access token to api/google-auth.php, which verifies it with Google and starts the session.
 * New accounts need a phone number (Mobile Money), which is asked for in a small dialog.
 *
 * The OAuth client ID comes from api/site-status.php (set google_client_id in config.local.php).
 */
(function () {
    'use strict';

    var buttons = Array.prototype.slice.call(document.querySelectorAll('[data-google-auth]'));
    if (!buttons.length) { return; }

    var clientId = '';
    var tokenClient = null;
    var loading = false;

    var message = document.getElementById('googleAuthMessage');
    if (!message) {
        message = document.createElement('div');
        message.id = 'googleAuthMessage';
        message.setAttribute('role', 'status');
        message.style.cssText = 'display:none;margin:12px 0 0;padding:12px 14px;border-radius:10px;font-size:0.9rem;line-height:1.45;';
        buttons[0].insertAdjacentElement('afterend', message);
    }

    function say(type, text) {
        var styles = {
            error:   'background:#fef2f2;color:#991b1b;border:1px solid #fecaca;',
            success: 'background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;',
            info:    'background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;'
        };
        message.style.cssText = 'display:block;margin:12px 0 0;padding:12px 14px;border-radius:10px;font-size:0.9rem;line-height:1.45;' + (styles[type] || styles.info);
        message.textContent = text;
    }

    function setBusy(busy) {
        buttons.forEach(function (b) {
            b.disabled = busy;
            b.style.opacity = busy ? '0.6' : '';
            b.style.cursor = busy ? 'wait' : '';
        });
    }

    function redirectAfterLogin(mode) {
        var next = new URLSearchParams(window.location.search).get('next') || '';
        var safeNext = /^[a-z0-9-]+\.html(\?[^\s]*)?$/i.test(next) ? next : 'dashboard.html';
        setTimeout(function () { window.location.href = mode === 'register' ? 'dashboard.html' : safeNext; }, 900);
    }

    // --- Config + Google script -------------------------------------------------------------
    fetch('api/site-status.php', { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (s) {
            clientId = (s && s.google_client_id) ? String(s.google_client_id) : '';
            if (!clientId) { return; }
            var script = document.createElement('script');
            script.src = 'https://accounts.google.com/gsi/client';
            script.async = true;
            script.defer = true;
            script.onload = function () {
                if (!window.google || !google.accounts || !google.accounts.oauth2) { return; }
                tokenClient = google.accounts.oauth2.initTokenClient({
                    client_id: clientId,
                    scope: 'openid email profile',
                    callback: onToken,
                    error_callback: function (err) {
                        setBusy(false);
                        if (err && err.type === 'popup_closed') { say('info', 'Google sign-in was cancelled.'); return; }
                        say('error', 'Google sign-in could not start' + (err && err.message ? ': ' + err.message : '.'));
                    }
                });
            };
            document.head.appendChild(script);
        })
        .catch(function () { /* site-status unreachable: the button explains on click */ });

    buttons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!clientId) {
                say('error', 'Google sign-in is not configured on this site yet. Please use your email or phone number.');
                return;
            }
            if (!tokenClient) {
                say('info', 'Google sign-in is still loading. Please try again in a moment.');
                return;
            }
            currentMode = btn.getAttribute('data-google-auth') === 'register' ? 'register' : 'login';
            message.style.display = 'none';
            setBusy(true);
            tokenClient.requestAccessToken({ prompt: 'select_account' });
        });
    });

    var currentMode = 'login';

    function onToken(resp) {
        if (!resp || !resp.access_token) {
            setBusy(false);
            say('error', 'Google did not return a sign-in token. Please try again.');
            return;
        }
        exchange(resp.access_token, '');
    }

    // --- Server exchange ----------------------------------------------------------------------
    function exchange(accessToken, phone) {
        if (loading) { return; }
        loading = true;
        say('info', 'Checking your Google account…');
        fetch('api/google-auth.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ access_token: accessToken, phone: phone || '' })
        })
            .then(function (res) {
                return res.json().then(function (data) { return { ok: res.ok, data: data }; });
            })
            .then(function (r) {
                loading = false;
                if (r.data && r.data.needs_phone) {
                    askPhone(accessToken, r.data);
                    return;
                }
                if (!r.ok || !r.data || !r.data.success) {
                    throw new Error((r.data && r.data.message) || 'Google sign-in failed. Please try again.');
                }
                try { if (r.data.user) { sessionStorage.setItem("lipalink_user", JSON.stringify(r.data.user)); } } catch (_) { /* ignore storage errors */ }
                setBusy(false);
                say('success', r.data.message || 'Signed in with Google.');
                redirectAfterLogin(r.data.created ? 'register' : currentMode);
            })
            .catch(function (err) {
                loading = false;
                setBusy(false);
                say('error', err.message || 'Google sign-in failed. Please try again.');
            });
    }

    // --- Phone dialog for first-time Google users ---------------------------------------------
    function askPhone(accessToken, data) {
        var existing = document.getElementById('googlePhoneDialog');
        if (existing) { existing.remove(); }

        var overlay = document.createElement('div');
        overlay.id = 'googlePhoneDialog';
        overlay.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,0.55);display:flex;align-items:center;justify-content:center;z-index:9999;padding:16px;';
        overlay.innerHTML =
            '<form style="background:#fff;border-radius:16px;padding:28px 24px;max-width:420px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,0.25);font-family:inherit;">' +
            '  <h3 style="margin:0 0 6px;font-size:1.2rem;color:#0f172a;">One more step</h3>' +
            '  <p style="margin:0 0 16px;color:#475569;font-size:0.92rem;line-height:1.5;">Hi ' + escapeHtml(data.name || '') + '. We found no LipaLink account for <strong>' + escapeHtml(data.email || '') + '</strong>. Add the phone number you use for Mobile Money and we will create your account.</p>' +
            '  <label style="display:block;font-size:0.85rem;font-weight:600;color:#334155;margin-bottom:6px;">Phone number</label>' +
            '  <input type="tel" name="phone" required placeholder="0712 345 678" autocomplete="tel" style="width:100%;padding:12px 14px;border:1.5px solid #cbd5e1;border-radius:10px;font-size:1rem;box-sizing:border-box;">' +
            '  <div data-role="err" style="display:none;margin-top:10px;padding:10px 12px;border-radius:8px;background:#fef2f2;color:#991b1b;font-size:0.85rem;"></div>' +
            '  <div style="display:flex;gap:10px;margin-top:18px;">' +
            '    <button type="button" data-role="cancel" style="flex:1;padding:12px;border:1.5px solid #cbd5e1;background:#fff;border-radius:10px;font-weight:600;cursor:pointer;">Cancel</button>' +
            '    <button type="submit" style="flex:2;padding:12px;border:0;background:#1e40af;color:#fff;border-radius:10px;font-weight:700;cursor:pointer;">Create my account</button>' +
            '  </div>' +
            '</form>';
        document.body.appendChild(overlay);

        var form = overlay.querySelector('form');
        var input = form.querySelector('input');
        var err = form.querySelector('[data-role="err"]');
        setTimeout(function () { input.focus(); }, 50);

        form.querySelector('[data-role="cancel"]').addEventListener('click', function () {
            overlay.remove();
            setBusy(false);
            say('info', 'Sign-up cancelled. You can also register with the form below.');
        });
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var digits = input.value.replace(/\D+/g, '');
            if (!(/^(0[67]\d{8}|255[67]\d{8}|[67]\d{8})$/.test(digits))) {
                err.style.display = 'block';
                err.textContent = 'Please enter a valid Tanzanian mobile number, e.g. 0712 345 678.';
                return;
            }
            err.style.display = 'none';
            form.querySelector('button[type="submit"]').disabled = true;
            overlay.remove();
            exchange(accessToken, input.value);
        });
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
})();
