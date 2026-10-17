// Administration -> Extensions and themes: filtering the tiles by type, and
// switching one on/off, both without a page reload. Without this script the
// page still works: the filter buttons just do nothing (every tile stays
// shown) and each toggle is a plain form that posts and reloads the page -
// see extensions.twig.
(function () {
    'use strict';

    var grid = document.querySelector('[data-extensions-grid]');
    if (!grid) {
        return;
    }

    var filterBar = document.querySelector('[data-extensions-filter]');
    if (filterBar) {
        var filterButtons = Array.prototype.slice.call(filterBar.querySelectorAll('button'));
        filterButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                var type = button.getAttribute('data-filter');
                filterButtons.forEach(function (b) {
                    b.setAttribute('aria-pressed', b === button ? 'true' : 'false');
                });
                Array.prototype.forEach.call(grid.querySelectorAll('[data-extension-id]'), function (tile) {
                    tile.hidden = type !== 'all' && tile.getAttribute('data-extension-type') !== type;
                });
            });
        });
    }

    if (!window.fetch) {
        return;
    }

    function tileFor(id) {
        return grid.querySelector('[data-extension-id="' + id + '"]');
    }

    function setTileState(id, enabled) {
        var tile = tileFor(id);
        if (!tile) {
            return;
        }
        var form = tile.querySelector('.ext-toggle-form');
        var button = form.querySelector('.toggle');
        var badge = tile.querySelector('[data-status-badge]');
        form.action = enabled ? form.getAttribute('data-disable-url') : form.getAttribute('data-enable-url');
        button.classList.toggle('is-on', enabled);
        button.setAttribute('aria-pressed', enabled ? 'true' : 'false');
        if (badge) {
            badge.className = 'badge ' + (enabled ? 'badge-enabled' : 'badge-disabled');
            badge.textContent = enabled ? grid.getAttribute('data-label-enabled') : grid.getAttribute('data-label-disabled');
        }
    }

    Array.prototype.forEach.call(grid.querySelectorAll('.ext-toggle-form'), function (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();

            var tile = form.closest('[data-extension-id]');
            var id = tile ? tile.getAttribute('data-extension-id') : '';
            // "extension:faq" / "theme:indigo" - the part before the colon
            // is shared by whatever this toggle switches off too (an App
            // switches off another App, a theme switches off another
            // theme, never the other kind), so the same prefix finds those
            // tiles from the plain id the server answers with.
            var kindPrefix = id.indexOf(':') !== -1 ? id.slice(0, id.indexOf(':') + 1) : '';
            var csrfField = form.querySelector('[name="_csrf"]');
            var goingToEnable = form.action === form.getAttribute('data-enable-url');
            var button = form.querySelector('.toggle');
            button.disabled = true;

            fetch(form.action, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Accept': 'application/json', 'X-CSRF-Token': csrfField ? csrfField.value : ''}
            }).then(function (response) {
                return response.json().then(function (data) {
                    return {ok: response.ok && data.ok === true, data: data};
                });
            }).then(function (result) {
                button.disabled = false;
                if (!result.ok) {
                    window.alert((result.data && result.data.error) || grid.getAttribute('data-label-failed'));
                    return;
                }
                setTileState(id, goingToEnable);
                var switchedOff = (result.data && result.data.switched_off) || [];
                switchedOff.forEach(function (otherId) {
                    setTileState(kindPrefix + otherId, false);
                });
            }).catch(function () {
                button.disabled = false;
                window.alert(grid.getAttribute('data-label-failed'));
            });
        });
    });
})();
