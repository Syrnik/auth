(function () {
    'use strict';

    /**
     * Handles forms with data-auth attribute by submitting them via fetch (AJAX).
     * JSON response protocol:
     *   {status: 'ok',              redirect: url}   → redirect to url
     *   {status: 'error',           error: message}  → show error message
     *   {status: 'step',            html: html}      → replace form content (OTP step etc.)
     *   {status: 'confirm_required'}                 → reload page
     *   {status: 'challenge',       redirect: url}   → redirect to challenge page
     */
    function submitAuthForm(form) {
        var formData = new FormData(form);
        var errorEl  = form.querySelector('.auth-error') || createErrorEl(form);

        fetch(form.action || window.location.href, {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.json();
        })
        .then(function (data) {
            errorEl.textContent = '';
            errorEl.style.display = 'none';

            if (data.status === 'ok' || data.status === 'challenge') {
                if (data.redirect) {
                    window.location.href = data.redirect;
                }
            } else if (data.status === 'error') {
                errorEl.textContent = data.error || 'Ошибка.';
                errorEl.style.display = '';
            } else if (data.status === 'step') {
                var stepHtml = data.html || '';
                if (stepHtml) {
                    var wrapper = form.querySelector('[data-auth-step]') || form;
                    wrapper.innerHTML = stepHtml;
                }
            } else if (data.status === 'confirm_required') {
                window.location.reload();
            }
        })
        .catch(function (err) {
            errorEl.textContent = 'Ошибка соединения. Попробуйте ещё раз.';
            errorEl.style.display = '';
        });
    }

    function createErrorEl(form) {
        var el = document.createElement('div');
        el.className = 'auth-error';
        el.style.display = 'none';
        form.insertBefore(el, form.firstChild);
        return el;
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form.dataset || !form.dataset.auth) {
            return;
        }
        event.preventDefault();
        submitAuthForm(form);
    });
}());
