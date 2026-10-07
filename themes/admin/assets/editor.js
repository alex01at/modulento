// A small editor for HTML text fields: a textarea marked with data-editor
// gets a toolbar and an editable area; the textarea stays the field that is
// sent, and the server cleans what arrives (HtmlSanitizer) as before.
// Without this script the textarea is used as it is.
(function () {
    'use strict';

    var buttons = [
        ['p', 'formatBlock', 'p'],
        ['h2', 'formatBlock', 'h2'],
        ['h3', 'formatBlock', 'h3'],
        ['bold', 'bold'],
        ['italic', 'italic'],
        ['ul', 'insertUnorderedList'],
        ['ol', 'insertOrderedList'],
        ['quote', 'formatBlock', 'blockquote'],
        ['link', 'createLink'],
        ['unlink', 'unlink'],
        ['html', null]
    ];

    // Element => allowed attributes, kept when pasting - the same elements
    // core/src/Support/HtmlSanitizer.php keeps when the field is saved, minus
    // pictures (only the media library's picker inserts those, with a src the
    // server accepts; any other img would silently vanish again on save).
    var PASTE_ALLOWED = {
        p: [], br: [], hr: [],
        h2: [], h3: [], h4: [],
        strong: [], b: [], em: [], i: [], u: [], small: [], sub: [], sup: [],
        ul: [], ol: [], li: [],
        blockquote: [], pre: [], code: [],
        a: ['href'],
        table: [], thead: [], tbody: [], tr: [], th: ['colspan', 'rowspan'], td: ['colspan', 'rowspan']
    };

    // Keeps only PASTE_ALLOWED elements and attributes; an element not on the
    // list is unwrapped (its text and children stay, the tag itself goes) -
    // the same rule the server applies, so a span or a div from Word or a web
    // page loses its styling without losing its text.
    function cleanPastedNode(node) {
        Array.prototype.slice.call(node.childNodes).forEach(function (child) {
            if (child.nodeType === Node.COMMENT_NODE) {
                node.removeChild(child);
                return;
            }
            if (child.nodeType !== Node.ELEMENT_NODE) {
                return;
            }

            var name = child.nodeName.toLowerCase();
            if (name === 'script' || name === 'style') {
                node.removeChild(child);
                return;
            }

            cleanPastedNode(child);

            var allowed = PASTE_ALLOWED[name];
            if (allowed === undefined) {
                while (child.firstChild) {
                    node.insertBefore(child.firstChild, child);
                }
                node.removeChild(child);
                return;
            }

            Array.prototype.slice.call(child.attributes).forEach(function (attribute) {
                if (allowed.indexOf(attribute.name.toLowerCase()) === -1) {
                    child.removeAttribute(attribute.name);
                }
            });
            if (name === 'a') {
                var href = (child.getAttribute('href') || '').replace(/[\x00-\x20]+/g, '');
                if (!/^(https?:\/\/|mailto:|tel:|\/(?!\/)|#)/i.test(href)) {
                    child.removeAttribute('href');
                }
            }
        });
    }

    function cleanPastedHtml(html) {
        var holder = document.createElement('div');
        holder.innerHTML = html;
        cleanPastedNode(holder);
        return holder.innerHTML;
    }

    // Plain text (no markup on the clipboard at all) still becomes paragraphs:
    // a contenteditable area does not turn a bare newline into one on its own.
    function plainTextToHtml(text) {
        return text.split(/\r?\n\s*\r?\n/).map(function (paragraph) {
            var escaped = paragraph.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            return '<p>' + escaped.replace(/\r?\n/g, '<br>') + '</p>';
        }).join('');
    }

    // The editors on this page by the id of their textarea.
    var editors = {};

    function setUp(textarea) {
        var labels;
        try {
            labels = JSON.parse(textarea.getAttribute('data-editor'));
        } catch (e) {
            return;
        }

        var wrap = document.createElement('div');
        wrap.className = 'editor';
        var bar = document.createElement('div');
        bar.className = 'editor-bar';
        bar.setAttribute('role', 'toolbar');
        bar.setAttribute('aria-label', labels.toolbar);
        var area = document.createElement('div');
        area.className = 'editor-area';
        area.contentEditable = 'true';
        area.setAttribute('role', 'textbox');
        area.setAttribute('aria-multiline', 'true');
        area.setAttribute('aria-label', labels.area);
        area.innerHTML = textarea.value;

        var source = false;
        function sync() {
            if (!source) {
                textarea.value = area.innerHTML;
            }
        }

        buttons.forEach(function (definition) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'editor-button editor-' + definition[0];
            button.textContent = labels[definition[0]];
            button.addEventListener('mousedown', function (event) {
                // Keeps the selection in the editable area.
                event.preventDefault();
            });
            button.addEventListener('click', function () {
                if (definition[0] === 'html') {
                    source = !source;
                    if (source) {
                        textarea.value = area.innerHTML;
                    } else {
                        area.innerHTML = textarea.value;
                    }
                    wrap.classList.toggle('editor-source', source);
                    button.setAttribute('aria-pressed', source ? 'true' : 'false');
                    (source ? textarea : area).focus();
                    return;
                }
                if (source) {
                    return;
                }
                area.focus();
                if (definition[1] === 'createLink') {
                    var url = window.prompt(labels.link_prompt, 'https://');
                    if (!url) {
                        return;
                    }
                    document.execCommand('createLink', false, url);
                } else {
                    document.execCommand(definition[1], false, definition[2] ? '<' + definition[2] + '>' : null);
                }
                sync();
            });
            if (definition[0] === 'html') {
                button.setAttribute('aria-pressed', 'false');
            }
            bar.appendChild(button);
        });

        // Pasted text keeps paragraphs and the formatting this field allows (the same
        // elements HtmlSanitizer keeps on the server, so what is shown here matches
        // what is saved); everything else - styles, classes, spans, pictures that are
        // not from the media library - is removed, not just hidden.
        area.addEventListener('paste', function (event) {
            event.preventDefault();
            var clipboard = event.clipboardData || window.clipboardData;
            var html = clipboard.getData('text/html');
            document.execCommand('insertHTML', false, html ? cleanPastedHtml(html) : plainTextToHtml(clipboard.getData('text/plain')));
        });
        area.addEventListener('input', sync);
        textarea.form && textarea.form.addEventListener('submit', sync);

        // A picture from the media library, at the cursor of the editable area.
        editors[textarea.id] = function (url, alt) {
            var image = document.createElement('img');
            image.setAttribute('src', url);
            image.setAttribute('alt', alt || '');
            var holder = document.createElement('div');
            holder.appendChild(image);
            if (source) {
                textarea.value += holder.innerHTML;
                return;
            }
            area.focus();
            if (!document.execCommand('insertHTML', false, holder.innerHTML)) {
                area.insertAdjacentHTML('beforeend', holder.innerHTML);
            }
            sync();
        };

        document.execCommand('defaultParagraphSeparator', false, 'p');
        textarea.parentNode.insertBefore(wrap, textarea);
        wrap.appendChild(bar);
        wrap.appendChild(area);
        wrap.appendChild(textarea);
    }

    document.querySelectorAll('textarea[data-editor]').forEach(setUp);

    document.addEventListener('click', function (event) {
        var button = event.target.closest ? event.target.closest('[data-media-insert]') : null;
        var insert = button && editors[button.getAttribute('data-media-insert')];
        if (insert) {
            event.preventDefault();
            insert(button.getAttribute('data-media-url'), button.getAttribute('data-media-alt'));
        }
    });
}());
