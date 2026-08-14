<?php

/**
 * Starts an account-linking OAuth round trip for the profile's "Linked
 * accounts" section (my/?section=linked_accounts): records an authLinkIntent
 * for the signed-in contact, then sends the visitor to the provider exactly
 * like a normal login would. The callback route (authFrontendCallbackAction /
 * authOAuthController) reads the intent back through authContactResolver::
 * resolve() and links instead of logging in — see AUTH-50.
 *
 * A GET, not a POST: it is a real navigation to an external provider, and it
 * has to stay one so a theme's copy of the linked-accounts partials (ADR 001
 * decision 6, the theme is a public contract) keeps working unmodified — the
 * "Link" anchor is unchanged, only where it points changed. The framework's
 * CSRF check only runs on POST (waDispatch::dispatch()/dispatchFrontend()),
 * so this route carries and checks its own token instead
 * (authHelper::getMyLinkUrl()) — without it, a marker set from another site
 * paired with a forgeable callback would let an attacker weld their own
 * provider identity onto a visitor's account.
 *
 * Every branch ends in a redirect (which exits) or a 404.
 */
class authFrontendMyLinkAction extends waViewAction
{
    public function execute(): void
    {
        if (!authConfig::isEnabled()) {
            throw new waException('Страница не найдена', 404);
        }

        $token  = waRequest::get('_csrf', '', 'string');
        $cookie = waRequest::cookie('_csrf', '');
        if ($token === '' || $cookie === '' || !hash_equals($cookie, $token)) {
            $this->fail(_w('Please reload the page and try again.'));
            return;
        }

        $method_id = waRequest::param('method_id', '', 'string');
        $method    = authHelper::getOAuthMethods()[$method_id] ?? null;
        if (!$method) {
            throw new waException('Метод авторизации не найден.', 404);
        }

        if ($method instanceof authWaidMethod && empty(authWaidMethod::getCredentials()['app_id'])) {
            $this->fail(_w('This sign-in method is not configured.'));
            return;
        }

        try {
            $url = $method->getCallbackUrl();
        } catch (BadMethodCallException $e) {
            $this->fail(_w('This sign-in method is not configured.'));
            return;
        }

        authLinkIntent::start(
            (int)wa()->getUser()->getId(),
            $method_id,
            authProfileSectionLinkedAccounts::sourceOf($method_id, $method)
        );

        // waAuthAdapter::url() bakes the current URL in as the post-login
        // destination; without clearing it, an abandoned link attempt would
        // send a later, unrelated login back to this starter route.
        wa()->getStorage()->del('auth_goal_url');

        wa()->getResponse()->redirect($url);
    }

    private function fail(string $message): void
    {
        authLinkIntent::clear();
        wa()->getResponse()->redirect(authHelper::flashLinkResult($message));
    }
}
