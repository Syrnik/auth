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
                resetCaptcha(form);
                if (data.captcha_widget) {
                    injectCaptcha(form, data.captcha_widget);
                }
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

    /**
     * reCAPTCHA tokens are single-use: after any failed submit the widget
     * still shows solved but its token is already spent, so a retry without
     * resetting fails server-side with invalid-input-response.
     */
    function resetCaptcha(form) {
        if (window.grecaptcha && form.querySelector('.g-recaptcha')) {
            window.grecaptcha.reset();
        }
    }

    /**
     * The login form's error response only ever comes with a captcha_widget
     * when one just became necessary mid-session (AUTH-49's captcha_mode
     * escalation) — the widget wasn't in the page when it was first rendered,
     * so there is nothing to reset(), only to inject.
     *
     * `.innerHTML =` alone would parse but never execute the widget's own
     * <script> tags (every captcha plugin's markup relies on one running,
     * see plugins/gcaptcha/templates/*.html) — each script is rebuilt as a
     * fresh element and appended so the browser actually runs it, in place
     * of the inert node innerHTML left behind.
     */
    function injectCaptcha(form, html) {
        var container = form.querySelector('[data-auth-captcha]');
        if (!container) {
            return;
        }
        container.innerHTML = html;
        var scripts = container.querySelectorAll('script');
        for (var i = 0; i < scripts.length; i++) {
            var old = scripts[i];
            var fresh = document.createElement('script');
            for (var a = 0; a < old.attributes.length; a++) {
                fresh.setAttribute(old.attributes[a].name, old.attributes[a].value);
            }
            fresh.textContent = old.textContent;
            old.parentNode.replaceChild(fresh, old);
        }
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
