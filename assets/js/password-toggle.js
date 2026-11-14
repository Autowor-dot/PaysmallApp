/**
 * Adds a show/hide (eye) button to every password field on the page.
 * Include with <script src="assets/js/password-toggle.js" defer></script>.
 */
(function () {
    'use strict';
    function enhance(input) {
        if (input.dataset.pwToggle) { return; }
        input.dataset.pwToggle = '1';
        var wrap = document.createElement('div');
        wrap.style.cssText = 'position:relative;display:block;';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);
        var pad = window.getComputedStyle(input).paddingRight;
        input.style.paddingRight = 'calc(' + (pad || '14px') + ' + 32px)';
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.setAttribute('aria-label', 'Show password');
        btn.setAttribute('aria-pressed', 'false');
        btn.title = 'Show password';
        btn.style.cssText = 'position:absolute;right:8px;top:50%;transform:translateY(-50%);border:0;background:transparent;color:#64748b;cursor:pointer;padding:6px;line-height:1;font-size:0.95rem;';
        btn.innerHTML = '<i class="fa-solid fa-eye" aria-hidden="true"></i>';
        btn.addEventListener('click', function () {
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.innerHTML = show ? '<i class="fa-solid fa-eye-slash" aria-hidden="true"></i>' : '<i class="fa-solid fa-eye" aria-hidden="true"></i>';
            btn.setAttribute('aria-pressed', show ? 'true' : 'false');
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            btn.title = show ? 'Hide password' : 'Show password';
            input.focus({ preventScroll: true });
        });
        wrap.appendChild(btn);
    }
    function run() {
        Array.prototype.slice.call(document.querySelectorAll('input[type="password"]')).forEach(enhance);
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', run); } else { run(); }
})();
