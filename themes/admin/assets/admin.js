// Administration theme: opens and closes the menu on narrow screens.
(function () {
    'use strict';

    var root = document.documentElement;
    // Without this class the menu stays in the page flow, so it is reachable
    // when scripts do not run.
    root.classList.add('js');

    document.addEventListener('DOMContentLoaded', function () {
        var toggle = document.querySelector('[data-sidebar-toggle]');
        var sidebar = document.getElementById('sidebar');
        if (!toggle || !sidebar) {
            return;
        }

        function setOpen(open, moveFocus) {
            root.classList.toggle('sidebar-open', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (!moveFocus) {
                return;
            }
            if (open) {
                var first = sidebar.querySelector('a, button');
                if (first) {
                    first.focus();
                }
            } else {
                toggle.focus();
            }
        }

        toggle.addEventListener('click', function () {
            setOpen(!root.classList.contains('sidebar-open'), true);
        });

        Array.prototype.forEach.call(document.querySelectorAll('[data-sidebar-close]'), function (element) {
            element.addEventListener('click', function () {
                setOpen(false, true);
            });
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && root.classList.contains('sidebar-open')) {
                setOpen(false, true);
            }
        });

        // Back on a wide screen the menu is always visible; drop the state.
        window.addEventListener('resize', function () {
            if (window.innerWidth >= 1024 && root.classList.contains('sidebar-open')) {
                setOpen(false, false);
            }
        });
    });
}());
