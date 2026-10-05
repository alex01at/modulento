// Asks the server every few seconds how many messages wait for the logged-in
// account, and shows the number on its picture. The page works without it:
// the messages are on their own pages. Nothing is asked while the tab is hidden.
(function () {
    'use strict';

    if (!window.fetch) {
        return;
    }

    Array.prototype.forEach.call(document.querySelectorAll('[data-unread]'), function (badge) {
        var seconds = parseInt(badge.getAttribute('data-poll'), 10);
        if (!(seconds > 0)) {
            return;
        }
        var url = badge.getAttribute('data-url');
        var label = badge.getAttribute('data-label') || '';

        function show(count) {
            badge.textContent = count > 99 ? '99+' : String(count);
            badge.setAttribute('aria-label', label.replace('{count}', String(count)));
            badge.hidden = count < 1;
        }

        function poll() {
            if (document.hidden) {
                return;
            }
            fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } })
                .then(function (response) {
                    return response.ok ? response.json() : null;
                })
                .then(function (data) {
                    if (data && typeof data.count === 'number') {
                        show(data.count);
                    }
                })
                .catch(function () {
                    // A failed ask is tried again with the next one.
                });
        }

        poll();
        window.setInterval(poll, seconds * 1000);
    });
}());
