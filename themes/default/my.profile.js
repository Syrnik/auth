(function () {
    'use strict';

    /**
     * Profile page (my/): section editing without reloading the page.
     *
     * This file belongs to the theme, not to the app, and is meant to be edited.
     * What it does — swap a section in place — is one way to spend the app's
     * answers, not the only one: a theme that wants a drawer, an accordion or a
     * field that turns into an input on click changes this file and nothing else.
     * The app has no opinion on where markup goes; it only answers with markup,
     * over an interface that stays the same for every theme:
     *
     *   GET  my/?section=<id>&mode=view|edit   → that section's partial, as HTML
     *   POST my/save/<id>/                     → {status, data: {section, index,
     *                                             mode, html | redirect}}
     *
     * Both modes of a section partial get the same template variables ($form,
     * $errors, $save_url, $edit_url, $view_url), so which markup is "view" and
     * which is "edit" is the theme's decision too — see README, «Тема дизайна».
     *
     * Everything below is an enhancement of markup that already works on its own.
     * The "Edit" and "Cancel" links are ordinary links to the profile page with
     * one section opened in one mode; the section form is an ordinary POST
     * answered with a redirect. With this script gone, or broken, or still
     * loading, the page keeps working — every path below ends in the plain
     * navigation it replaced.
     *
     * No markup is built here on purpose. A section is rendered in exactly one
     * place, its Smarty partial, whether a full page load or an XHR asked for it;
     * duplicating any of it in JS is what makes the two drift apart.
     *
     * The markup contract is three attributes:
     *
     *   [data-section]        root of a section partial — the element replaced
     *   [data-section-link]   a link that fetches a section instead of navigating
     *   [data-section-form]   a form posted over fetch instead of submitted
     */

    var SECTION_SELECTOR = '[data-section]';

    document.addEventListener('click', function (event) {
        var link = closest(event.target, 'a[data-section-link]');
        if (!link || event.defaultPrevented || !isPlainClick(event)) {
            return;
        }

        var section = closest(link, SECTION_SELECTOR);
        if (!section) {
            return;
        }

        event.preventDefault();
        loadSection(section, link.href);
    });

    document.addEventListener('submit', function (event) {
        var form = closest(event.target, 'form[data-section-form]');
        if (!form || event.defaultPrevented) {
            return;
        }

        var section = closest(form, SECTION_SELECTOR);
        if (!section) {
            return;
        }

        event.preventDefault();
        saveSection(section, form);
    });

    /**
     * Fetches one section in one mode and swaps it in. On any failure the browser
     * simply follows the link — the address is a working page in its own right.
     */
    function loadSection(section, url) {
        var answered = false;

        setBusy(section, true);

        fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        })
        .then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.text();
        })
        .then(function (html) {
            var fresh = parse(html);
            answered = true;
            swap(section, fresh);
        })
        .catch(function () {
            // Only a request that never produced a section falls back to the
            // plain navigation; past that point the answer is already on the
            // page and reloading it would undo what the visitor sees.
            if (!answered) {
                window.location.href = url;
            }
        });
    }

    /**
     * Posts the section's form. Both outcomes come back as markup — the section
     * in view mode when it saved, in edit mode with the errors when it did not —
     * so success and failure are the same swap and neither needs a message
     * invented here.
     *
     * The session can expire between drawing the page and posting it, in which
     * case the framework answers with the login page rather than with JSON.
     * That is not an error to report but a place to go, so the form is submitted
     * for real and the browser lands where the server wants it.
     */
    function saveSection(section, form) {
        // Read the form before disabling anything: a disabled control is left
        // out of FormData, and the section would be saved short of a field.
        var payload  = new FormData(form);
        var answered = false;

        setBusy(section, true);

        fetch(form.action || window.location.href, {
            method: 'POST',
            body: payload,
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        })
        .then(function (response) {
            return response.json();
        })
        .then(function (answer) {
            var data = (answer && answer.data) || {};

            if (data.redirect) {
                // Changing a login (email, phone, password) is not a save but a
                // flow of its own: the server hands back where it starts.
                answered = true;
                window.location.href = data.redirect;
                return;
            }

            if (data.html) {
                var fresh = parse(data.html);
                answered = true;
                swap(section, fresh);
                return;
            }

            throw new Error('no section markup in the answer');
        })
        .catch(function () {
            // The answer was not one we can put on the page — the session
            // expired and the framework sent the login form, the endpoint is
            // gone, the network dropped. Submitting for real hands the visitor
            // to the server, which knows what to do with all three, and never
            // repeats a save that already happened.
            if (answered) {
                return;
            }
            setBusy(section, false);
            form.submit();
        });
    }

    /**
     * The section the server sent, or an exception.
     *
     * Parsing is deliberately separate from putting the result on the page: a
     * server that answers with something other than a section (an error page, a
     * login form, an empty body from a theme missing a partial) must be found
     * out before anything is touched, so that the caller can still fall back to
     * the plain navigation with the page it has intact.
     */
    function parse(html) {
        var holder = document.createElement('div');
        holder.innerHTML = String(html).trim();

        var fresh = holder.firstElementChild;
        if (!fresh || !fresh.matches(SECTION_SELECTOR)) {
            throw new Error('the answer is not a profile section');
        }

        return fresh;
    }

    /**
     * Puts the new section in place of the old one and the caret in the first
     * field of a form that has just appeared — the visitor clicked "Edit" in
     * order to type, and carrying on with the keyboard should not cost another
     * click.
     */
    function swap(section, fresh) {
        section.parentNode.replaceChild(fresh, section);
        focusFirstField(fresh);
    }

    function focusFirstField(section) {
        var field = section.querySelector('input:not([type=hidden]):not([disabled]), select, textarea');
        if (field) {
            field.focus();
        }
    }

    /**
     * Marks the section as working, so a slow answer is visible and a second
     * click on the same button does not send a second request.
     */
    function setBusy(section, busy) {
        section.classList.toggle('auth-profile-section-busy', busy);

        var controls = section.querySelectorAll('button, input[type=submit]');
        for (var i = 0; i < controls.length; i++) {
            controls[i].disabled = busy;
        }
    }

    /**
     * A click meant for this page: not a middle click, not one held together
     * with a modifier to open a new tab. Those belong to the browser.
     */
    function isPlainClick(event) {
        return event.button === 0 && !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey;
    }

    function closest(node, selector) {
        var el = (node && node.nodeType === 3) ? node.parentNode : node;

        return (el && el.closest) ? el.closest(selector) : null;
    }
}());
