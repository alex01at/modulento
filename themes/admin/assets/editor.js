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

        // Pasted text arrives without the formatting of where it came from.
        area.addEventListener('paste', function (event) {
            event.preventDefault();
            var text = (event.clipboardData || window.clipboardData).getData('text/plain');
            document.execCommand('insertText', false, text);
        });
        area.addEventListener('input', sync);
        textarea.form && textarea.form.addEventListener('submit', sync);

        document.execCommand('defaultParagraphSeparator', false, 'p');
        textarea.parentNode.insertBefore(wrap, textarea);
        wrap.appendChild(bar);
        wrap.appendChild(area);
        wrap.appendChild(textarea);
    }

    document.querySelectorAll('textarea[data-editor]').forEach(setUp);
}());
