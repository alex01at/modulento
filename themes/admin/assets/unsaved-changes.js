// Warns before leaving the administration with a form filled in and not
// saved: a click on a menu entry, the back button, closing the tab. Only a
// form that is sent with POST counts - a GET form (search, filter) is never
// something worth losing. The rich text editor (editor.js) has no field of
// its own to listen on, so its editable area counts the same way a field
// does.
(function () {
    'use strict';

    var dirty = false;

    function postForm(target) {
        var form = target.closest ? target.closest('form') : null;
        return form && form.method.toLowerCase() === 'post' ? form : null;
    }

    document.addEventListener('input', function (event) {
        if (postForm(event.target) || event.target.classList.contains('editor-area')) {
            dirty = true;
        }
    }, true);
    document.addEventListener('change', function (event) {
        if (postForm(event.target)) {
            dirty = true;
        }
    }, true);

    // Submitting the form is leaving on purpose, with everything on it kept.
    document.addEventListener('submit', function () {
        dirty = false;
    }, true);

    window.addEventListener('beforeunload', function (event) {
        if (!dirty) {
            return;
        }
        // The wording is the browser's own from here on: for years now,
        // every major one shows a fixed message of its own and ignores
        // whatever string a page sets, exactly so a page cannot fake its
        // own "are you sure" text.
        event.preventDefault();
        event.returnValue = '';
    });
}());
