// Keeps the time left on a lot's page running. Without this script the
// page shows the time as it was when it was loaded.
(function () {
    'use strict';

    var element = document.querySelector('[data-auction-ends]');
    if (!element) {
        return;
    }

    var ends = Date.parse(element.getAttribute('data-auction-ends'));
    var template = element.getAttribute('data-auction-template');
    if (isNaN(ends) || !template) {
        return;
    }

    // The server's clock decides when a lot ends; this one only counts.
    var offset = Date.parse(element.getAttribute('data-auction-now')) - Date.now();
    var reloaded = false;

    function tick() {
        var left = Math.max(0, Math.floor((ends - Date.now() - (isNaN(offset) ? 0 : offset)) / 1000));

        element.textContent = template
            .replace('{days}', Math.floor(left / 86400))
            .replace('{hours}', Math.floor(left % 86400 / 3600))
            .replace('{minutes}', Math.floor(left % 3600 / 60))
            .replace('{seconds}', left % 60);

        // At the end the page itself says what happened.
        if (left === 0 && !reloaded) {
            reloaded = true;
            window.setTimeout(function () { window.location.reload(); }, 3000);
        }
    }

    tick();
    window.setInterval(tick, 1000);
}());
