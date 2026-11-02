// Turns a bell/inbox link into a dropdown: its own panel, filled in from the
// server on first open. The link stays a real link to the full page the
// whole time - without this script (or without fetch), it just works as one.
(function () {
    'use strict';

    if (!window.fetch) {
        return;
    }

    Array.prototype.forEach.call(document.querySelectorAll('[data-dropdown]'), function (root) {
        var toggle = root.querySelector('[data-dropdown-toggle]');
        var panel = root.querySelector('[data-dropdown-panel]');
        if (!toggle || !panel) {
            return;
        }
        var url = panel.getAttribute('data-dropdown-url');
        var loaded = false;

        function close() {
            root.classList.remove('is-open');
            toggle.setAttribute('aria-expanded', 'false');
            panel.hidden = true;
        }

        function load() {
            if (loaded || !url) {
                return;
            }
            loaded = true;
            fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'text/html' } })
                .then(function (response) {
                    return response.ok ? response.text() : null;
                })
                .then(function (html) {
                    if (html !== null) {
                        panel.innerHTML = html;
                    } else {
                        loaded = false;
                    }
                })
                .catch(function () {
                    loaded = false;
                });
        }

        toggle.addEventListener('click', function (event) {
            event.preventDefault();
            if (root.classList.contains('is-open')) {
                close();
            } else {
                root.classList.add('is-open');
                toggle.setAttribute('aria-expanded', 'true');
                panel.hidden = false;
                load();
            }
        });

        document.addEventListener('click', function (event) {
            if (!root.contains(event.target)) {
                close();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                close();
            }
        });
    });
}());
