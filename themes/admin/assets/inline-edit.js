// Editing the texts of a page on the spot (administrators, while the page is in edit mode).
// A text marked with data-field becomes editable on click; leaving it saves it, Escape undoes.
// Without this script the page still works: the tools and the forms under each block do the same.
(function () {
    'use strict';

    if (!document.querySelector('.inline-block[data-block-id]') || !window.fetch) {
        return;
    }
    var bar = document.querySelector('.inline-bar');
    var status = document.createElement('p');
    status.className = 'inline-status';
    status.setAttribute('role', 'status');
    document.body.appendChild(status);

    function say(text, ok) {
        status.textContent = text;
        status.classList.toggle('is-error', !ok);
    }

    function isHtml(el) {
        return el.hasAttribute('data-html');
    }

    function current(el) {
        return (isHtml(el) ? el.innerHTML : el.textContent).trim();
    }

    function show(el, value) {
        if (isHtml(el)) {
            el.innerHTML = value;
        } else {
            el.textContent = value;
        }
    }

    function start(el) {
        if (el.classList.contains('is-editing')) {
            return;
        }
        el.dataset.original = current(el);
        el.classList.add('is-editing');
        el.contentEditable = 'true';
        el.focus();
    }

    function finish(el) {
        el.contentEditable = 'false';
        el.classList.remove('is-editing');
        var value = current(el);
        if (value === el.dataset.original) {
            return;
        }
        var block = el.closest('.inline-block');
        say(bar && bar.dataset.saving ? bar.dataset.saving : '…', true);
        fetch(block.dataset.fieldUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': block.dataset.csrf},
            body: JSON.stringify({block: block.dataset.blockId, field: el.dataset.field, locale: document.documentElement.lang, value: value})
        }).then(function (response) {
            return response.json().then(function (data) {
                return {ok: response.ok && data.ok, data: data};
            });
        }).then(function (result) {
            if (result.ok) {
                show(el, result.data.value);
                say(bar && bar.dataset.saved ? bar.dataset.saved : 'Saved.', true);
            } else {
                show(el, el.dataset.original);
                say(bar && bar.dataset.failed ? bar.dataset.failed : 'Not saved.', false);
            }
        }).catch(function () {
            show(el, el.dataset.original);
            say(bar && bar.dataset.failed ? bar.dataset.failed : 'Not saved.', false);
        });
    }

    Array.prototype.forEach.call(document.querySelectorAll('.inline-block [data-field]'), function (el) {
        el.classList.add('inline-editable');
        el.setAttribute('tabindex', '0');
        el.addEventListener('click', function () {
            start(el);
        });
        el.addEventListener('keydown', function (event) {
            if (!el.classList.contains('is-editing')) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    start(el);
                }
                return;
            }
            if (event.key === 'Escape') {
                show(el, el.dataset.original);
                el.blur();
            } else if (event.key === 'Enter' && !isHtml(el) && !event.shiftKey) {
                event.preventDefault();
                el.blur();
            }
        });
        el.addEventListener('blur', function () {
            if (el.classList.contains('is-editing')) {
                finish(el);
            }
        });
    });
}());
