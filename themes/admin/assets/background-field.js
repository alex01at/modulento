// A block's background type select (data-background-toggle) shows only the
// fields that type needs (data-background-field, a space-separated list of
// the types it applies to). Without this script every field just stays
// visible - the server only ever saves the ones that match the chosen type
// anyway, so nothing breaks, it is only less tidy.
(function () {
    'use strict';

    function apply(select) {
        var scope = select.closest('fieldset') || select.parentNode;
        if (!scope) {
            return;
        }
        Array.prototype.forEach.call(scope.querySelectorAll('[data-background-field]'), function (field) {
            var types = field.getAttribute('data-background-field').split(/\s+/);
            field.hidden = types.indexOf(select.value) === -1;
        });
    }

    Array.prototype.forEach.call(document.querySelectorAll('[data-background-toggle]'), function (select) {
        apply(select);
        select.addEventListener('change', function () {
            apply(select);
        });
    });
}());
