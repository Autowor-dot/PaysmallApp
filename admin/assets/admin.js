/* LipaLink admin console behaviour: navigation, theme, confirmations, charts. */
(function () {
    'use strict';

    var root = document.documentElement;

    // ---- Sidebar (mobile) ----
    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-toggle-sidebar]')) {
            document.body.classList.toggle('sidebar-open');
        } else if (e.target.closest('[data-close-sidebar]')) {
            document.body.classList.remove('sidebar-open');
        }
    });

    // ---- Profile dropdown ----
    document.addEventListener('click', function (e) {
        var toggle = e.target.closest('[data-dropdown-toggle]');
        document.querySelectorAll('[data-dropdown].open').forEach(function (d) {
            if (!toggle || !d.contains(toggle)) d.classList.remove('open');
        });
        if (toggle) toggle.closest('[data-dropdown]').classList.toggle('open');
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('[data-dropdown].open').forEach(function (d) { d.classList.remove('open'); });
            document.body.classList.remove('sidebar-open');
        }
    });

    // ---- Theme ----
    function currentTheme() {
        var t = root.getAttribute('data-theme');
        if (t) return t;
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }
    function syncThemeIcon() {
        document.querySelectorAll('[data-toggle-theme] i').forEach(function (i) {
            i.className = currentTheme() === 'dark' ? 'fa-solid fa-sun' : 'fa-solid fa-moon';
        });
    }
    document.addEventListener('click', function (e) {
        if (!e.target.closest('[data-toggle-theme]')) return;
        var next = currentTheme() === 'dark' ? 'light' : 'dark';
        root.setAttribute('data-theme', next);
        try { localStorage.setItem('lipalink_admin_theme', next); } catch (err) { /* ignore */ }
        syncThemeIcon();
        renderCharts();
    });
    syncThemeIcon();

    // ---- Confirmation for dangerous actions ----
    document.addEventListener('click', function (e) {
        var el = e.target.closest('[data-confirm]');
        if (!el) return;
        var form = el.form || el.closest('form');
        // Let the browser show required-field messages first
        if (form && typeof form.checkValidity === 'function' && !form.checkValidity()) return;
        if (!window.confirm(el.getAttribute('data-confirm'))) {
            e.preventDefault();
            e.stopPropagation();
        }
    }, true);

    // Prevent double submits
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (form.method && form.method.toLowerCase() === 'post') {
            if (form.dataset.submitted === '1') { e.preventDefault(); return; }
            form.dataset.submitted = '1';
            setTimeout(function () { form.dataset.submitted = ''; }, 4000);
        }
    });

    // ---- Password generator ----
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-generate-password]');
        if (!btn) return;
        var input = btn.parentElement.querySelector('[data-password-target]');
        var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        var out = '';
        var buf = new Uint32Array(16);
        window.crypto.getRandomValues(buf);
        for (var i = 0; i < buf.length; i++) out += chars[buf[i] % chars.length];
        out = out.slice(0, 13) + (buf[0] % 10) + out.slice(13); // guarantee a digit
        input.value = out;
        input.select();
    });

    // ---- Charts ----
    var charts = [];
    function cssVar(name) { return getComputedStyle(root).getPropertyValue(name).trim(); }
    function palette() {
        return [1, 2, 3, 4, 5, 6, 7].map(function (n) { return cssVar('--chart-' + n); });
    }
    function alpha(hex, a) {
        var h = hex.replace('#', '');
        if (h.length === 3) h = h.split('').map(function (c) { return c + c; }).join('');
        var n = parseInt(h, 16);
        return 'rgba(' + ((n >> 16) & 255) + ',' + ((n >> 8) & 255) + ',' + (n & 255) + ',' + a + ')';
    }
    function money(v) {
        var n = Number(v) || 0;
        if (Math.abs(n) >= 1e9) return (n / 1e9).toFixed(1) + 'B';
        if (Math.abs(n) >= 1e6) return (n / 1e6).toFixed(1) + 'M';
        if (Math.abs(n) >= 1e3) return (n / 1e3).toFixed(0) + 'K';
        return String(n);
    }

    function renderCharts() {
        var dataEl = document.getElementById('chart-data');
        if (!dataEl || typeof window.Chart === 'undefined') return;
        var specs;
        try { specs = JSON.parse(dataEl.textContent); } catch (err) { return; }
        charts.forEach(function (c) { c.destroy(); });
        charts = [];

        var pal = palette();
        var text = cssVar('--text-muted');
        var grid = cssVar('--border');
        Chart.defaults.font.family = cssVar('--font');
        Chart.defaults.color = text;

        document.querySelectorAll('canvas[data-chart]').forEach(function (canvas) {
            var spec = specs[canvas.getAttribute('data-chart')];
            if (!spec) return;
            var isDoughnut = spec.type === 'doughnut';
            var datasets = spec.datasets.map(function (d) {
                if (isDoughnut) {
                    var cols = (d.colors || []).map(function (i) { return pal[i % pal.length]; });
                    return { label: d.label, data: d.data, backgroundColor: cols, borderColor: cssVar('--surface'), borderWidth: 2 };
                }
                var col = pal[(d.color || 0) % pal.length];
                return {
                    label: d.label, data: d.data,
                    borderColor: col,
                    backgroundColor: spec.type === 'line' ? alpha(col, 0.12) : col,
                    fill: spec.type === 'line',
                    tension: 0.35, borderWidth: 2, pointRadius: 2, pointHoverRadius: 5,
                    borderRadius: spec.type === 'bar' ? 4 : 0, maxBarThickness: 28
                };
            });
            var total = isDoughnut ? spec.datasets[0].data.reduce(function (a, b) { return a + b; }, 0) : 0;
            var options = {
                responsive: true, maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: isDoughnut ? 'right' : 'bottom', labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true } },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                var v = isDoughnut ? ctx.parsed : ctx.parsed.y;
                                var s = spec.money ? 'TZS ' + Number(v).toLocaleString() : Number(v).toLocaleString();
                                if (isDoughnut && total > 0) s += ' (' + Math.round(v / total * 100) + '%)';
                                return ' ' + (isDoughnut ? ctx.label : ctx.dataset.label) + ': ' + s;
                            }
                        }
                    }
                }
            };
            if (!isDoughnut) {
                options.scales = {
                    x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true } },
                    y: { beginAtZero: true, grid: { color: grid }, border: { display: false },
                         ticks: { precision: 0, callback: function (v) { return spec.money ? money(v) : v; } } }
                };
            } else {
                options.cutout = '68%';
                if (total === 0) {
                    datasets[0].data = [1];
                    datasets[0].backgroundColor = [grid];
                    options.plugins.tooltip.enabled = false;
                }
            }
            charts.push(new Chart(canvas, { type: spec.type, data: { labels: total === 0 && isDoughnut ? ['No agreements yet'] : spec.labels, datasets: datasets }, options: options }));
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', renderCharts);
    } else {
        renderCharts();
    }
    if (window.matchMedia) {
        var mq = window.matchMedia('(prefers-color-scheme: dark)');
        if (mq.addEventListener) mq.addEventListener('change', function () { if (!root.getAttribute('data-theme')) { syncThemeIcon(); renderCharts(); } });
    }
})();
