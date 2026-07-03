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

    private function showForm(string $error = '', array $step_vars = []): void
    {
        $goal_url = waRequest::get('goal_url', '', 'string');
        if (!$goal_url) {
            $goal_url = (string)(wa()->getStorage()->get('auth_goal_url') ?? '');
        }
        if ($goal_url) {
            wa()->getStorage()->set('auth_goal_url', $goal_url);
        }

        $this->setLayout(new authFrontendLayout());
        $this->executeAction(new authLoginFormAction($goal_url, $error, $step_vars));
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

        // Captcha
        $captcha = authPluginManager::getCaptchaPlugin();
        if ($captcha && !$captcha->verifyCaptcha(waRequest::post())) {
            $this->renderError('Неверный код капчи.');
            return;
        }

        // Resolve method
        $method_id = waRequest::post('auth_method', 'email', 'string');
        $methods   = authPluginManager::getEnabled();
        $method    = $methods[$method_id] ?? null;
        if (!$method) {
            $this->renderError('Неизвестный метод входа.');
            return;
        }

        // Authenticate
        try {
            $contact_id = $method->authenticate(waRequest::post());
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

        // Create session
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

    private function renderError(string $message): void
    {
        if (waRequest::isXMLHttpRequest()) {
            $this->sendJson(['status' => 'error', 'error' => $message]);
        } else {
            $this->showForm($message);
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

    public function __construct(string $goal_url = '', string $error = '', array $step_vars = [])
    {
        parent::__construct();
        $this->goal_url  = $goal_url;
        $this->error     = $error;
        $this->step_vars = $step_vars;
    }

    public function execute(): void
    {
        $this->view->assign(authHelper::loginViewData($this->goal_url, $this->error, $this->step_vars));

        $this->setThemeTemplate('login.html');
    }
}
