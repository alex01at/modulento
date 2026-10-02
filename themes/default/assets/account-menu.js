// The account menu in the header is a <details> element and works without
// this file. Here it only learns to close on Escape and on a click or a
// focus elsewhere, as people expect from a menu.
(function () {
    'use strict';

    function close(menu, moveFocus) {
        if (!menu.open) {
            return;
        }
        menu.open = false;
        if (moveFocus) {
            menu.querySelector('summary').focus();
        }
    }

    function run() {
        Array.prototype.forEach.call(document.querySelectorAll('details.account-menu'), function (menu) {
            document.addEventListener('click', function (event) {
                if (!menu.contains(event.target)) {
                    close(menu, false);
                }
            });
            menu.addEventListener('focusout', function (event) {
                if (event.relatedTarget && !menu.contains(event.relatedTarget)) {
                    close(menu, false);
                }
            });
            menu.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    close(menu, true);
                }
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run);
    } else {
        run();
    }
}());
