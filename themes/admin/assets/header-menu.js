// Administration theme, header layout: the menu button on narrow screens and
// the mega menu of each section. Hovering opens a mega menu on wide screens
// too; that is done in CSS, so this script only handles clicks and keys.
(function () {
    'use strict';

    var header = document.querySelector('[data-header]');
    if (!header) {
        return;
    }
    var menuToggle = header.querySelector('[data-menu-toggle]');
    var sections = Array.prototype.slice.call(header.querySelectorAll('[data-mega]'));

    function setMenu(open) {
        header.classList.toggle('menu-open', open);
        menuToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    function setSection(section, open) {
        section.classList.toggle('is-open', open);
        section.querySelector('[data-mega-toggle]').setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    function closeSections() {
        sections.forEach(function (section) {
            setSection(section, false);
        });
    }

    sections.forEach(function (section) {
        section.querySelector('.mega-link').addEventListener('click', function (event) {
            var mouseOnWide = window.matchMedia('(min-width: 1200px) and (hover: hover)').matches;
            if (mouseOnWide || section.classList.contains('is-open')) {
                return;
            }
            event.preventDefault();
            closeSections();
            setSection(section, true);
        });
    });

    menuToggle.addEventListener('click', function () {
        setMenu(!header.classList.contains('menu-open'));
    });

    sections.forEach(function (section) {
        section.querySelector('[data-mega-toggle]').addEventListener('click', function () {
            var open = !section.classList.contains('is-open');
            closeSections();
            setSection(section, open);
        });
    });

    document.addEventListener('click', function (event) {
        if (!header.contains(event.target)) {
            closeSections();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeSections();
            setMenu(false);
        }
    });

    // Back on a wide screen the bar shows everything; drop the narrow state.
    window.addEventListener('resize', function () {
        if (window.innerWidth >= 1200) {
            setMenu(false);
        }
    });
}());
