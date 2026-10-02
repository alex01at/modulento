// Adds a "show password" button to every password field. Without scripts
// there is no button and the fields work as before. The same file is kept
// in core/install/ for the setup page, which runs before any theme.
(function () {
    'use strict';

    // The wording comes from the page: data-show and data-hide on the
    // script element that loads this file.
    var script = document.currentScript;
    var show = (script && script.getAttribute('data-show')) || 'Show password';
    var hide = (script && script.getAttribute('data-hide')) || 'Hide password';

    function enhance(input) {
        var wrap = document.createElement('span');
        wrap.className = 'password-field';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'password-toggle';
        button.textContent = show;
        button.setAttribute('aria-pressed', 'false');
        if (input.id) {
            button.setAttribute('aria-controls', input.id);
        }
        wrap.appendChild(button);

        function setVisible(visible) {
            input.type = visible ? 'text' : 'password';
            button.textContent = visible ? hide : show;
            button.setAttribute('aria-pressed', visible ? 'true' : 'false');
        }

        button.addEventListener('click', function () {
            setVisible(input.type === 'password');
        });

        // Sent as a password field again: browsers only offer to save, and
        // keep out of their form history, what is one at that moment.
        if (input.form) {
            input.form.addEventListener('submit', function () {
                setVisible(false);
            });
        }
    }

    function run() {
        Array.prototype.forEach.call(document.querySelectorAll('input[type="password"]'), enhance);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run);
    } else {
        run();
    }
}());
