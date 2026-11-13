// Apply the saved admin theme before first paint (external file: the admin CSP blocks inline scripts)
(function () {
    try {
        var t = localStorage.getItem('lipalink_admin_theme');
        if (t === 'dark' || t === 'light') document.documentElement.setAttribute('data-theme', t);
    } catch (e) { /* storage unavailable: follow the OS setting */ }
})();
