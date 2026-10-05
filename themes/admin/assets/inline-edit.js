// Editing the texts of a page on the spot (administrators, while the page is in edit mode).
// A text marked with data-field becomes editable on click; leaving it saves it, Escape undoes.
// Rich texts (data-html) get a small toolbar. Links and buttons inside a block do not navigate
// in edit mode: a link in a rich text becomes editable, any other opens the block's form.
// Without this script the page still works: the tools and the forms under each block do the same.
(function () {
    'use strict';

    if (!document.querySelector('.inline-block[data-block-id]') || !window.fetch) {
        return;
    }
    var bar = document.querySelector('.inline-bar');
    var labels = {};
    try {
        labels = JSON.parse((bar && bar.dataset.editorLabels) || '{}');
    } catch (e) {
        labels = {};
    }
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

    // The toolbar of a rich text: paragraph and headings, bold, italic, lists, quote, link.
    function toolbar(el) {
        var bar = document.createElement('div');
        bar.className = 'inline-wysiwyg';
        bar.setAttribute('role', 'toolbar');
        bar.setAttribute('aria-label', labels.toolbar || 'Text');
        var buttons = [
            ['p', labels.p || 'Text', function () { document.execCommand('formatBlock', false, 'p'); }],
            ['h2', labels.h2 || 'H2', function () { document.execCommand('formatBlock', false, 'h2'); }],
            ['h3', labels.h3 || 'H3', function () { document.execCommand('formatBlock', false, 'h3'); }],
            ['bold', labels.bold || 'B', function () { document.execCommand('bold'); }],
            ['italic', labels.italic || 'I', function () { document.execCommand('italic'); }],
            ['ul', labels.ul || '•', function () { document.execCommand('insertUnorderedList'); }],
            ['ol', labels.ol || '1.', function () { document.execCommand('insertOrderedList'); }],
            ['quote', labels.quote || '“', function () { document.execCommand('formatBlock', false, 'blockquote'); }],
            ['link', labels.link || 'Link', function () {
                var url = window.prompt(labels.link_prompt || 'https://', 'https://');
                if (url && /^(https?:\/\/|\/)/.test(url.trim())) {
                    document.execCommand('createLink', false, url.trim());
                }
            }],
            ['unlink', labels.unlink || 'Link entfernen', function () { document.execCommand('unlink'); }]
        ];
        buttons.forEach(function (item) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'inline-wysiwyg-' + item[0];
            button.textContent = item[1];
            // Keeps the selection of the text while the button is pressed.
            button.addEventListener('mousedown', function (event) {
                event.preventDefault();
            });
            button.addEventListener('click', function () {
                el.focus();
                item[2]();
            });
            bar.appendChild(button);
        });
        el.parentNode.insertBefore(bar, el);

        return bar;
    }

    function start(el) {
        if (el.classList.contains('is-editing')) {
            return;
        }
        el.dataset.original = current(el);
        el.classList.add('is-editing');
        el.contentEditable = 'true';
        if (isHtml(el)) {
            el.inlineToolbar = toolbar(el);
        }
        el.focus();
    }

    function finish(el) {
        el.contentEditable = 'false';
        el.classList.remove('is-editing');
        if (el.inlineToolbar) {
            el.inlineToolbar.parentNode.removeChild(el.inlineToolbar);
            el.inlineToolbar = null;
        }
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
        el.addEventListener('blur', function (event) {
            // A press on the toolbar keeps the text in edit mode.
            if (event.relatedTarget && el.inlineToolbar && el.inlineToolbar.contains(event.relatedTarget)) {
                return;
            }
            if (el.classList.contains('is-editing')) {
                finish(el);
            }
        });
    });

    // Blocks are moved by dragging their handle (⠿). The order is saved when the drop ends;
    // on a phone the arrows of each block do the same. A failed save reloads the page.
    function orderOf(container) {
        return Array.prototype.map.call(container.querySelectorAll(':scope > .inline-block[data-block-id]'), function (block) {
            return block.dataset.blockId;
        });
    }

    var dragged = null;
    var startOrder = '';

    Array.prototype.forEach.call(document.querySelectorAll('.inline-block[data-block-id]'), function (block) {
        var grip = block.querySelector('.inline-grip');
        if (!grip) {
            return;
        }
        grip.addEventListener('mousedown', function () {
            block.draggable = true;
        });
        grip.addEventListener('mouseup', function () {
            block.draggable = false;
        });
        block.addEventListener('dragstart', function (event) {
            dragged = block;
            startOrder = orderOf(block.parentNode).join(',');
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', block.dataset.blockId);
            block.classList.add('is-dragging');
        });
        block.addEventListener('dragover', function (event) {
            if (!dragged || dragged === block || dragged.parentNode !== block.parentNode) {
                return;
            }
            event.preventDefault();
            var box = block.getBoundingClientRect();
            var before = event.clientY < box.top + box.height / 2;
            block.parentNode.insertBefore(dragged, before ? block : block.nextSibling);
        });
        block.addEventListener('dragend', function () {
            block.draggable = false;
            block.classList.remove('is-dragging');
            if (!dragged) {
                return;
            }
            var container = dragged.parentNode;
            dragged = null;
            var order = orderOf(container);
            if (order.join(',') === startOrder) {
                return;
            }
            say(bar && bar.dataset.saving ? bar.dataset.saving : '…', true);
            fetch(block.dataset.orderUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': block.dataset.csrf},
                body: JSON.stringify({order: order})
            }).then(function (response) {
                if (!response.ok) {
                    throw new Error('order');
                }
                say(bar && bar.dataset.saved ? bar.dataset.saved : 'Saved.', true);
            }).catch(function () {
                say(bar && bar.dataset.failed ? bar.dataset.failed : 'Not saved.', false);
                window.setTimeout(function () {
                    window.location.reload();
                }, 1200);
            });
        });
    });

    // In edit mode a link or a button of a block is not followed.
    document.addEventListener('click', function (event) {
        var link = event.target.closest ? event.target.closest('.inline-block a') : null;
        if (!link) {
            return;
        }
        event.preventDefault();
        var field = link.closest('[data-field]');
        if (field && isHtml(field)) {
            start(field);
            return;
        }
        var form = link.closest('.inline-block').querySelector('details.inline-edit');
        if (form) {
            form.open = true;
            var input = form.querySelector('input[name$="[button_url]"]') || form.querySelector('input, textarea');
            if (input) {
                input.focus();
            }
        }
    }, true);
}());
