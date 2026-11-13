/* Mobile navigation toggle shared by the public pages */
(function () {
    'use strict';
    const navbar = document.querySelector('.navbar');
    const toggle = document.querySelector('.mobile-toggle');
    if (!navbar || !toggle) return;

    toggle.addEventListener('click', function () {
        const open = navbar.classList.toggle('menu-open');
        toggle.setAttribute('aria-expanded', String(open));
        toggle.innerHTML = open ? '<i class="fa-solid fa-xmark"></i>' : '<i class="fa-solid fa-bars"></i>';
    });

    // Close the menu after choosing a link on small screens
    navbar.querySelectorAll('.nav-panel a').forEach(function (link) {
        link.addEventListener('click', function () {
            navbar.classList.remove('menu-open');
            toggle.setAttribute('aria-expanded', 'false');
            toggle.innerHTML = '<i class="fa-solid fa-bars"></i>';
        });
    });
})();
