<?php

/**
 * Intercepts all frontend login requests.
 * waSystem::login() dispatches here when authLoginController class exists.
 * Handles both form display (GET) and authentication (POST).
 */
class authLoginController extends waViewController
{
    use authJsonResponseTrait;

    public function execute(): void
    {
        // No login methods enabled for this site → auth is off here.
        if (!authConfig::isEnabled()) {
            throw new waException('Страница не найдена', 404);
        }

        if (waRequest::method() === 'post') {
            $this->handlePost();
        } else {
            $this->showForm();
        }
    }

    // -------------------------------------------------------------------------
    // GET: display login form
    // -------------------------------------------------------------------------

    private function showForm(string $error = '', array $step_vars = [], ?bool $captcha_required = null): void
    {
        $goal_url = waRequest::get('goal_url', '', 'string');
        if (!$goal_url) {
            $goal_url = (string)(wa()->getStorage()->get('auth_goal_url') ?? '');
        }
        if ($goal_url) {
            wa()->getStorage()->set('auth_goal_url', $goal_url);
        }

        $this->setLayout(new authFrontendLayout());
        $this->executeAction(new authLoginFormAction($goal_url, $error, $step_vars, $captcha_required));
    }

    // -------------------------------------------------------------------------
    // POST: authenticate
    // -------------------------------------------------------------------------

    private function handlePost(): void
    {
        // CSRF validated by framework (csrf: true in app.php).

        $goal_url = waRequest::post('goal_url', '', 'string');
        if ($goal_url) {
            wa()->getStorage()->set('auth_goal_url', $goal_url);
        }

        $post = waRequest::post();

        // Resolve method — moved ahead of the throttle check and the captcha,
        // both of which now need it: the throttle keys on the typed
        // identifier, and captcha_mode:after_n needs the identifier's attempt
        // count to decide whether to ask at all (AUTH-49).
        $method_id = waRequest::post('auth_method', 'email', 'string');
        $methods   = authPluginManager::getEnabled();
        $method    = $methods[$method_id] ?? null;
        if (!$method) {
            $this->renderError('Неизвестный метод входа.');
            return;
        }

        $throttle_keys = [
            'ip'    => waRequest::getIp(),
            'login' => authThrottle::identifierFromPost($post),
        ];

        $throttle_state = authThrottle::check('login', $throttle_keys);
        if ($throttle_state->blocked) {
            $this->renderError(
                authThrottle::blockedMessage($throttle_state->retryAfter),
                ['retry_after' => $throttle_state->retryAfter, 'attempts' => $throttle_state->attempts],
                $throttle_state->captchaRequired
            );
            return;
        }

        // Captcha — only when currently required (off/always/after_n, see
        // authHelper::getCaptchaWidget()). This check is by IP alone at GET
        // time (the identifier isn't known yet), but this decision below
        // also has the identifier — so it can go from "not required" to
        // "required" between the two, and the form the visitor is looking
        // at then has no widget at all. isCaptchaShown() catches exactly
        // that case: re-render with the widget instead of running
        // verifyCaptcha() against a POST that was never going to carry a
        // token, which would otherwise blame the visitor for a "wrong
        // captcha" and charge their IP a hit before the password is ever
        // checked (see authHelper::loginCaptchaWidget()).
        if ($throttle_state->captchaRequired) {
            $captcha = authPluginManager::getCaptchaPlugin();
            if ($captcha) {
                if (!authHelper::isCaptchaShown($post)) {
                    $this->renderError(_w('Please confirm you are not a robot and try again.'), [], true);
                    return;
                }
                // A bad captcha counts by IP only: it can be failed without
                // knowing anything about a login, so counting it against the
                // identifier would let it be used to run up someone else's
                // counter without a single credential guess.
                if (!$captcha->verifyCaptcha($post)) {
                    $hit_state = authThrottle::hit('login', ['ip' => waRequest::getIp()]);
                    $this->renderError('Неверный код капчи.', [], $hit_state->captchaRequired);
                    return;
                }
            }
        }

        // Authenticate
        try {
            $contact_id = $method->authenticate($post);
        } catch (authCredentialFailureException $e) {
            $hit_state = authThrottle::hit('login', $throttle_keys);
            $this->renderError($e->getMessage(), ['attempts' => $hit_state->attempts], $hit_state->captchaRequired);
            return;
        } catch (authMethodStepException $e) {
            $this->renderStep($method, $e->getTemplateVars());
            return;
        } catch (authGuardException $e) {
            $this->renderError($e->getMessage());
            return;
        } catch (waException $e) {
            $this->renderError($e->getMessage());
            return;
        }

        if ($contact_id === null) {
            // OAuth redirect already performed by method->authenticate()
            return;
        }

        // Login guards
        try {
            foreach (authPluginManager::getGuardsEnabled('login') as $guard) {
                $guard->checkLogin($contact_id);
            }
        } catch (authGuardException $e) {
            $this->renderError($e->getMessage());
            return;
        }

        // Challenge (2FA)
        foreach (authPluginManager::getChallengeEnabled() as $challenge) {
            if ($challenge->isRequired($contact_id)) {
                wa()->getStorage()->set('auth_pending_id', $contact_id);
                wa()->getStorage()->set('auth_challenge', $challenge->getId());
                $url = authHelper::getChallengeUrl();
                if (waRequest::isXMLHttpRequest()) {
                    $this->sendJson(['status' => 'challenge', 'redirect' => $url]);
                } else {
                    wa()->getResponse()->redirect($url);
                }
                return;
            }
        }

        // Create session. Only the identifier's throttle window is cleared —
        // never the IP window, see authThrottle's own docblock (a successful
        // login is not evidence the other attempts from this IP weren't an
        // attack, when credential stuffing succeeds by construction on some
        // fraction of attempts).
        authThrottle::reset('login', ['login' => authThrottle::identifierFromPost($post)]);

        $stored_goal = (string)(wa()->getStorage()->get('auth_goal_url') ?? '');
        $contact     = new waContact($contact_id);
        wa()->getAuth()->auth(['id' => $contact_id]);
        wa()->getStorage()->del('auth_goal_url');
        wa()->event('login', $contact);

        $fallback     = authHelper::localRedirectUrl(authConfig::get('redirect_after_login'), '/');
        $redirect_url = authHelper::localRedirectUrl($stored_goal, $fallback);

        if (waRequest::isXMLHttpRequest()) {
            $this->sendJson(['status' => 'ok', 'redirect' => $redirect_url]);
        } else {
            wa()->getResponse()->redirect($redirect_url);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * $captcha_required overrides the login form's default (auto, IP-only)
     * captcha decision with a state already computed this request — the
     * fresh IP-only guess showForm() would otherwise make is one attempt out
     * of date the moment a hit() just moved it. Null keeps the default for
     * paths that never touched the throttle (unknown method, a plain guard).
     */
    private function renderError(string $message, array $extra = [], ?bool $captcha_required = null): void
    {
        if (waRequest::isXMLHttpRequest()) {
            $response = array_merge(['status' => 'error', 'error' => $message], $extra);
            if ($captcha_required) {
                // The ajax form never re-renders on an 'error' response (see
                // js/auth.js), so a widget that just became necessary has to
                // be handed over explicitly for the client to inject — with
                // the same shown-marker loginViewData() would have rendered,
                // so a follow-up submit passes authHelper::isCaptchaShown().
                $response['captcha_widget'] = authHelper::loginCaptchaWidget(true);
            }
            $this->sendJson($response);
        } else {
            $this->showForm($message, [], $captcha_required);
        }
    }

    private function renderStep(authMethod $method, array $vars): void
    {
        if (waRequest::isXMLHttpRequest()) {
            $this->sendJson(array_merge(['status' => 'step'], $vars));
        } else {
            $this->showForm('', $vars);
        }
    }

}

// Companion action: renders login.html.
// Defined in the same file so it's loaded together with authLoginController.
class authLoginFormAction extends waViewAction
{
    private string $goal_url;
    private string $error;
    private array  $step_vars;
    private ?bool  $captcha_required;

    public function __construct(string $goal_url = '', string $error = '', array $step_vars = [], ?bool $captcha_required = null)
    {
        parent::__construct();
        $this->goal_url         = $goal_url;
        $this->error            = $error;
        $this->step_vars        = $step_vars;
        $this->captcha_required = $captcha_required;
    }

    public function execute(): void
    {
        $this->view->assign(authHelper::loginViewData($this->goal_url, $this->error, $this->step_vars, $this->captcha_required));

        $this->setThemeTemplate('login.html');
    }
}
