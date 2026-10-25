document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-auto-submit]').forEach(function (input) {
        input.addEventListener('change', function () { input.form.requestSubmit(); });
    });
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!window.confirm(form.dataset.confirm)) { event.preventDefault(); }
        });
    });
});
