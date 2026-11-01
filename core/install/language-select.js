document.addEventListener('change', function (event) {
    if (event.target && event.target.matches('[data-auto-submit]') && event.target.form) {
        event.target.form.submit();
    }
});
