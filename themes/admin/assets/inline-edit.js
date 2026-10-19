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

    // Mirrors Modulento\Core\Support\Design::FONTS's keys and
    // Modulento\Core\Support\HtmlSanitizer::SPAN_CLASSES, the same as editor.js (the plain
    // form's own editor) - a span with one of these classes, or just a colour, is the only
    // font/size/colour formatting the server keeps.
    var FONTS = ['system', 'serif', 'humanist', 'mono'];
    var SIZES = ['sm', 'md', 'lg', 'xl'];

    // Wraps the current selection (inside el) in a new span - font/size/colour formatting,
    // which execCommand cannot produce with an arbitrary class. Does nothing to a collapsed
    // selection (nothing picked to format) - see editor.js, which this mirrors exactly.
    function wrapSelection(el, attrs) {
        var selection = window.getSelection();
        if (!selection.rangeCount || selection.isCollapsed) {
            return;
        }
        var range = selection.getRangeAt(0);
        if (!el.contains(range.commonAncestorContainer)) {
            return;
        }
        var span = document.createElement('span');
        if (attrs.className) {
            span.className = attrs.className;
        }
        if (attrs.style) {
            span.setAttribute('style', attrs.style);
        }
        span.appendChild(range.extractContents());
        range.insertNode(span);
        selection.removeAllRanges();
        var after = document.createRange();
        after.selectNodeContents(span);
        selection.addRange(after);
    }

    // Removes every text-formatting span (font/size/colour) from the selection, keeping its
    // text - the same "unwrap" rule the server's sanitizer applies to a span that ends up with
    // neither a known class nor a colour.
    function unwrapFormatting(node) {
        Array.prototype.slice.call(node.childNodes).forEach(function (child) {
            if (child.nodeType !== Node.ELEMENT_NODE) {
                return;
            }
            unwrapFormatting(child);
            if (child.nodeName.toLowerCase() === 'span' && (child.className || child.getAttribute('style'))) {
                while (child.firstChild) {
                    node.insertBefore(child.firstChild, child);
                }
                node.removeChild(child);
            }
        });
    }

    function stripSelectionFormatting(el) {
        var selection = window.getSelection();
        if (!selection.rangeCount || selection.isCollapsed) {
            return;
        }
        var range = selection.getRangeAt(0);
        if (!el.contains(range.commonAncestorContainer)) {
            return;
        }
        var fragment = range.extractContents();
        unwrapFormatting(fragment);
        range.insertNode(fragment);
    }

    // The toolbar of a rich text: paragraph and headings, bold, italic, lists, quote, link,
    // font, size, colour.
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

        // Font and size: one button per value, previewed on the button's own text - the same
        // class the server keeps (HtmlSanitizer::SPAN_CLASSES) and the design stylesheet
        // styles (Design::textFormatting()), so the preview matches the result.
        FONTS.forEach(function (font) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'inline-wysiwyg-font text-font-' + font;
            button.textContent = 'Aa';
            button.setAttribute('aria-label', labels['font_' + font] || font);
            button.title = labels['font_' + font] || font;
            button.addEventListener('mousedown', function (event) {
                event.preventDefault();
            });
            button.addEventListener('click', function () {
                el.focus();
                wrapSelection(el, {className: 'text-font-' + font});
            });
            bar.appendChild(button);
        });
        SIZES.forEach(function (size) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'inline-wysiwyg-size text-size-' + size;
            button.textContent = 'A';
            button.setAttribute('aria-label', labels['size_' + size] || size);
            button.title = labels['size_' + size] || size;
            button.addEventListener('mousedown', function (event) {
                event.preventDefault();
            });
            button.addEventListener('click', function () {
                el.focus();
                wrapSelection(el, {className: 'text-size-' + size});
            });
            bar.appendChild(button);
        });

        // Colour needs its own native picker, so it cannot keep the selection the mousedown
        // trick's way above - the selection is saved on its own and restored right before
        // wrapping it, exactly like editor.js (the plain form's own editor) already does.
        var savedRange = null;
        el.addEventListener('mouseup', saveRange);
        el.addEventListener('keyup', saveRange);
        function saveRange() {
            var selection = window.getSelection();
            if (selection.rangeCount && !selection.isCollapsed && el.contains(selection.getRangeAt(0).commonAncestorContainer)) {
                savedRange = selection.getRangeAt(0).cloneRange();
            }
        }
        var colour = document.createElement('input');
        colour.type = 'color';
        colour.className = 'inline-wysiwyg-color';
        colour.setAttribute('aria-label', labels.format_color || 'Colour');
        colour.title = labels.format_color || 'Colour';
        colour.addEventListener('mousedown', function (event) {
            event.preventDefault();
        });
        colour.addEventListener('input', function () {
            if (!savedRange) {
                return;
            }
            var selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(savedRange);
            wrapSelection(el, {style: 'color: ' + colour.value});
        });
        bar.appendChild(colour);

        var clearButton = document.createElement('button');
        clearButton.type = 'button';
        clearButton.className = 'inline-wysiwyg-clear';
        clearButton.textContent = '✕';
        clearButton.setAttribute('aria-label', labels.format_clear || 'Clear formatting');
        clearButton.title = labels.format_clear || 'Clear formatting';
        clearButton.addEventListener('mousedown', function (event) {
            event.preventDefault();
        });
        clearButton.addEventListener('click', function () {
            el.focus();
            stripSelectionFormatting(el);
        });
        bar.appendChild(clearButton);

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
        var details = link.closest('.inline-block').querySelector('details.inline-edit');
        if (!details) {
            return;
        }
        var input;
        if (details.editDialog) {
            details.editDialog.showModal();
            input = details.editDialog.querySelector('input[name$="[button_url]"]') || details.editDialog.querySelector('input, textarea');
        } else {
            details.open = true;
            input = details.querySelector('input[name$="[button_url]"]') || details.querySelector('input, textarea');
        }
        if (input) {
            input.focus();
        }
    }, true);

    // "Text und Einstellungen bearbeiten" opens as a dialog (a floating box
    // over the page) instead of unfolding inline at the full width of the
    // page - the form can be long (every text field, the background
    // settings) and the page underneath should not jump around while it is
    // open. Without a browser that has <dialog>, the details element below
    // still works exactly as it always did, unfolding in place.
    if (typeof window.HTMLDialogElement === 'function') {
        Array.prototype.forEach.call(document.querySelectorAll('details.inline-edit'), function (details) {
            var summary = details.querySelector('summary');
            var form = details.querySelector('form');
            if (!summary || !form) {
                return;
            }

            var dialog = document.createElement('dialog');
            dialog.className = 'inline-edit-dialog';
            var head = document.createElement('div');
            head.className = 'inline-edit-dialog-head';
            var heading = document.createElement('p');
            heading.textContent = summary.textContent;
            var closeButton = document.createElement('button');
            closeButton.type = 'button';
            closeButton.className = 'inline-edit-dialog-close';
            closeButton.setAttribute('aria-label', labels.close || 'Close');
            closeButton.innerHTML = '&times;';
            closeButton.addEventListener('click', function () {
                dialog.close();
            });
            head.appendChild(heading);
            head.appendChild(closeButton);
            dialog.appendChild(head);
            dialog.appendChild(form);
            document.body.appendChild(dialog);
            details.editDialog = dialog;

            summary.addEventListener('click', function (event) {
                event.preventDefault();
                dialog.showModal();
            });
            // A click on the backdrop (the dialog element itself, outside its content) closes it.
            dialog.addEventListener('click', function (event) {
                if (event.target === dialog) {
                    dialog.close();
                }
            });
            dialog.addEventListener('close', function () {
                details.open = false;
            });
        });
    }
}());
