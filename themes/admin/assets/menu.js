// Administration -> Content -> Menu: dragging an entry to a new place.
// Without this script the list still works exactly as before, through the
// Up/Down buttons inside each entry - see menu_edit.twig.
(function () {
    'use strict';

    var list = document.querySelector('[data-menu-list]');
    if (!list || !window.fetch) {
        return;
    }

    var reorderUrl = list.getAttribute('data-reorder-url');
    var csrfField = document.querySelector('[name="_csrf"]');
    var dragged = null;

    function items() {
        return Array.prototype.slice.call(list.querySelectorAll('[data-menu-item]'));
    }

    function closestItem(node) {
        return node && node.closest ? node.closest('[data-menu-item]') : null;
    }

    Array.prototype.forEach.call(list.querySelectorAll('.menu-item-handle'), function (handle) {
        handle.setAttribute('draggable', 'true');

        handle.addEventListener('dragstart', function (event) {
            dragged = closestItem(handle);
            if (!dragged) {
                return;
            }
            dragged.classList.add('is-dragging');
            event.dataTransfer.effectAllowed = 'move';
            // Firefox needs data set on the transfer to start a drag at all.
            event.dataTransfer.setData('text/plain', dragged.getAttribute('data-id') || '');
        });

        handle.addEventListener('dragend', function () {
            if (dragged) {
                dragged.classList.remove('is-dragging');
            }
            items().forEach(function (item) {
                item.classList.remove('drag-over');
            });
            dragged = null;
        });
    });

    list.addEventListener('dragover', function (event) {
        if (!dragged) {
            return;
        }
        var over = closestItem(event.target);
        if (!over || over === dragged) {
            return;
        }
        event.preventDefault();

        items().forEach(function (item) {
            item.classList.toggle('drag-over', item === over);
        });

        var rect = over.getBoundingClientRect();
        var before = event.clientY - rect.top < rect.height / 2;
        over.parentNode.insertBefore(dragged, before ? over : over.nextSibling);
    });

    list.addEventListener('drop', function (event) {
        if (!dragged) {
            return;
        }
        event.preventDefault();
        save();
    });

    function save() {
        if (!reorderUrl) {
            return;
        }
        var body = new URLSearchParams();
        items().forEach(function (item) {
            body.append('order[]', item.getAttribute('data-id') || '');
        });

        fetch(reorderUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': csrfField ? csrfField.value : ''
            },
            body: body.toString()
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('reorder failed');
            }
        }).catch(function () {
            window.alert(list.getAttribute('data-label-failed'));
            window.location.reload();
        });
    }
})();
