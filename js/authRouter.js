/**
 * Backend content router: links marked with class "js-router" load their
 * target via ajax into the content area instead of doing a full page
 * reload, using pushState so the address bar and back/forward still work.
 *
 * Modeled on wa-apps/team/js/team.js's ContentRouter.
 */
var authRouter = (function ($) {
    var $content = null;
    var xhr = false;
    // Pathname of the section currently loaded into $content (hash excluded).
    // Used to tell "back landed on a hash-only step within this same
    // section" (nothing to fetch, hashchange handles it) apart from "back
    // landed on a different section that predates our pushState history"
    // (needs a real reload) — both look identical as far as
    // event.state goes, since neither carries our content_uri.
    var current_pathname = location.pathname;

    function init(options) {
        $content = options.$content;

        $(document).on('click', 'a.js-router', function (event) {
            if (event.ctrlKey || event.shiftKey || event.metaKey || event.which === 2) {
                return;
            }
            event.preventDefault();
            load(this.href);
        });

        // Forms marked js-ajax-save (the per-domain settings screens) post via
        // ajax and swap the response fragment in, same as navigation, instead
        // of a full-page redirect back to the same section.
        $(document).on('submit', 'form.js-ajax-save', function (event) {
            event.preventDefault();
            if (xhr) {
                xhr.abort();
            }
            var $form = $(this);
            xhr = $.post($form.attr('action') || (location.pathname + location.search), $form.serialize(), function (html) {
                xhr = false;
                setContent(html, true);
            });
        });

        window.addEventListener('popstate', function (event) {
            if (event.state && event.state.content_uri) {
                load(event.state.content_uri, true);
            } else if (location.pathname !== current_pathname) {
                location.reload();
            }
            // else: a hash-only history step within the section that's
            // already loaded (e.g. Design's theme/route sub-navigation),
            // which carries no pushState of its own — the hashchange
            // event fired alongside this one handles re-rendering it.
        });

        // Design section's own hash-based sub-navigation (theme cards,
        // route settings, code editor tabs) lives entirely inside content
        // already loaded by this router, so it's driven by the native
        // hashchange event rather than another router click/href. This
        // covers plain <a href="#..."> clicks within an already-loaded
        // design page; pushState navigation (below) does not fire
        // hashchange, so that path calls waDesignLoad() itself.
        window.addEventListener('hashchange', function () {
            if (typeof window.waDesignLoad === 'function') {
                window.waDesignLoad();
            }
        });

        // Initial full page load landing directly on the design section.
        if (typeof window.waDesignLoad === 'function') {
            window.waDesignLoad();
        }
    }

    function load(url, is_popstate) {
        if (xhr) {
            xhr.abort();
        }

        xhr = $.get(url, function (html) {
            xhr = false;

            if (!is_popstate) {
                history.pushState({ content_uri: url }, '', url);
            }

            setContent(html);
        });
    }

    function setContent(html, keep_scroll) {
        current_pathname = location.pathname;
        $content.html(html);
        if (!keep_scroll) {
            window.scrollTo(0, 0);
        }
        highlightSidebar();

        if ($content.find('.js-design-container').length) {
            // pushState navigation onto the design section changes
            // location.hash without firing a native hashchange event,
            // so kick it off by hand; Design's own inline script (just
            // evaluated by $.fn.html() above) has freshly redefined
            // window.waDesignLoad by this point.
            if (typeof window.waDesignLoad === 'function') {
                window.waDesignLoad();
            }
        } else {
            // Leaving the design section: drop the stale reference so a
            // later hashchange elsewhere can't call into torn-down markup.
            window.waDesignLoad = undefined;
        }
    }

    function highlightSidebar() {
        var current = location.pathname + location.search;
        $('.js-router').each(function () {
            var link = this.pathname + this.search;
            $(this).closest('li').toggleClass('selected', link === current);
        });
    }

    return {
        init: init
    };
})(jQuery);
