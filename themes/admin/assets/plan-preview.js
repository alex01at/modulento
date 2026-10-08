// A live preview of the plan card next to its edit form, updated as the
// administrator types - what it shows is read entirely from the form's own
// fields (see each field's data-label), so it never drifts out of sync.
// Without this script the form still saves the plan exactly the same way.
(function () {
    'use strict';

    var form = document.getElementById('plan-form');
    if (!form) {
        return;
    }

    var nameEl = document.getElementById('preview-name');
    var badgeEl = document.getElementById('preview-badge');
    var priceEl = document.getElementById('preview-price');
    var featuresEl = document.getElementById('preview-features');

    function selectedLabel(select) {
        var option = select.options[select.selectedIndex];
        return option ? (option.dataset.label || option.textContent) : '';
    }

    function addFeature(text, muted) {
        var li = document.createElement('li');
        li.textContent = text;
        if (muted) {
            li.className = 'is-muted';
        }
        featuresEl.appendChild(li);
    }

    function update() {
        var name = form.querySelector('#plan-name').value.trim();
        var price = form.querySelector('#plan-price').value.trim();
        var currency = form.querySelector('#plan-currency').value.trim().toUpperCase();
        var period = form.querySelector('#plan-period');
        var active = form.querySelector('[name="active"]');

        nameEl.textContent = name || nameEl.dataset.placeholder;
        badgeEl.textContent = (active && active.checked) ? badgeEl.dataset.active : badgeEl.dataset.inactive;
        badgeEl.className = 'badge ' + ((active && active.checked) ? 'badge-active' : 'badge-disabled');
        priceEl.textContent = (price || '0') + ' ' + currency + ' / ' + selectedLabel(period);

        featuresEl.innerHTML = '';
        var any = false;
        form.querySelectorAll('[name="features[]"]:checked').forEach(function (box) {
            addFeature(box.dataset.label || box.value, false);
            any = true;
        });
        form.querySelectorAll('.custom-feature').forEach(function (input) {
            if (input.value.trim() !== '') {
                addFeature(input.value.trim(), false);
                any = true;
            }
        });
        form.querySelectorAll('[name^="offer_limits"]').forEach(function (input) {
            if (input.value.trim() !== '') {
                addFeature(input.dataset.label + ': ' + input.value.trim(), false);
                any = true;
            }
        });
        var images = form.querySelector('#plan-max-images').value.trim();
        if (images !== '') {
            addFeature(featuresEl.dataset.imagesLabel.replace('{count}', images), false);
            any = true;
        }
        if (!any) {
            addFeature(featuresEl.dataset.noneLabel, true);
        }
    }

    form.addEventListener('input', update);
    form.addEventListener('change', update);
    update();
})();
