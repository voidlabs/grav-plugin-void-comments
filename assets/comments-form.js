(function () {
    'use strict';

    function ready(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback, { once: true });
        } else {
            callback();
        }
    }

    ready(function () {
        var form = document.getElementById('void-comments-form');
        if (!form) return;

        var widget = form.querySelector('cap-widget');
        var tokenInput = function () { return form.querySelector('input[name="cap-token"]'); };
        var solved = Boolean(tokenInput() && tokenInput().value.trim());
        var error = document.createElement('div');
        error.className = 'void-comments-captcha-error';
        error.setAttribute('role', 'alert');
        error.hidden = true;
        error.textContent = 'Completa la verifica di essere umano prima di inviare il commento.';
        if (widget && widget.parentNode) widget.parentNode.insertBefore(error, widget.nextSibling);

        function hasToken() {
            var input = tokenInput();
            return Boolean(input && input.value.trim());
        }

        function showCaptchaError() {
            error.hidden = false;
            error.classList.add('is-visible');
            if (widget) widget.scrollIntoView({ behavior: 'smooth', block: 'center' });
            else form.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        function clearCaptchaError() {
            error.hidden = true;
            error.classList.remove('is-visible');
        }

        if (widget) {
            widget.addEventListener('solve', function () { solved = true; clearCaptchaError(); });
            widget.addEventListener('reset', function () { solved = false; });
        }

        form.addEventListener('submit', function (event) {
            if (widget && !solved && !hasToken()) {
                event.preventDefault();
                showCaptchaError();
            }
        });

        var serverErrors = form.querySelectorAll('[role="alert"], .form-errors, .form-error, .invalid-feedback, .alert-danger, .alert-error');
        for (var index = 0; index < serverErrors.length; index += 1) {
            if (serverErrors[index] !== error && serverErrors[index].textContent.trim() !== '') {
                window.setTimeout(function () {
                    form.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }, 0);
                break;
            }
        }
    });
}());
