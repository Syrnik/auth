<?php

/**
 * Recovery is entirely delegated to authRecovery/authRecoveryProvider (see
 * docs/adr/004-recovery-channels.md) — this action only routes between three
 * shapes of request and renders the result:
 *
 *   1. no token, nothing pending: the "enter email or phone" form.
 *   2. a code-based provider's request is pending in the session
 *      (authRecovery::hasPendingCode()): code + new password, one form.
 *   3. recovery/<token>/: a link-based provider's request — new password
 *      alone, the mailed token being the proof.
 */
class authFrontendRecoveryAction extends waViewAction
{
    public function execute(): void
    {
        if (!authHelper::hasRecovery()) {
            throw new waException('Страница не найдена', 404);
        }

        $token = waRequest::param('token', '', 'string');

        if ($token !== '') {
            $this->handleTokenStep($token);
            return;
        }

        if (authRecovery::hasPendingCode()) {
            $this->handleCodeStep();
            return;
        }

        if (waRequest::method() === 'post') {
            $this->handleRequestPost();
            return;
        }

        $this->renderRequestForm();
    }

    // -------------------------------------------------------------------------
    // Step 0: "enter email or phone" — no token, nothing pending.
    // -------------------------------------------------------------------------

    private function handleRequestPost(): void
    {
        $identifier = trim((string)waRequest::post('identifier', ''));
        $response   = authRecovery::request($identifier);

        if ($response->error !== '') {
            $this->renderRequestForm($response->error);
            return;
        }

        if ($response->needsCode) {
            $this->renderCodeForm();
            return;
        }

        $this->renderSent();
    }

    // -------------------------------------------------------------------------
    // Step 1a: a code-based provider (phone) — code and new password
    // together, one form, one POST.
    //
    // Not two round trips: authRecovery::verifyCode() only mutates the
    // pending row on a WRONG guess (increments attempts, or deletes the row
    // once they run out) — a right guess leaves it untouched. So resubmitting
    // the same code again after fixing an invalid password still succeeds,
    // and this step needs no separate "code already verified" flag in the
    // session — the pending row itself is the only state.
    // -------------------------------------------------------------------------

    private function handleCodeStep(): void
    {
        if (waRequest::method() !== 'post') {
            $this->renderCodeForm();
            return;
        }

        $code = trim((string)waRequest::post('code', ''));
        if ($code === '' || !authRecovery::verifyCode($code)) {
            // attemptsLeftForCurrentCode() re-reads the row after the failed
            // guess above already spent one, so this reports what is left
            // *now* — same wording authPhoneMethod::verifyOtp() uses for its
            // own OTP guesses. null means the row is gone (this was the
            // guess that exhausted attempts, or the request simply expired
            // between page loads) — the session handle now points at
            // nothing, so it is dropped and the visitor starts over, rather
            // than being left stuck resubmitting into a code step whose row
            // no longer exists.
            $left = authRecovery::attemptsLeftForCurrentCode();
            if ($left === null) {
                authRecovery::clearHandle();
                $this->renderRequestForm(_w('The code expired or ran out of attempts. Request a new one.'));
                return;
            }
            $this->renderCodeForm(sprintf(_w('Incorrect code. Attempts left: %d.'), $left));
            return;
        }

        $password         = (string)waRequest::post('password', '');
        $password_confirm = (string)waRequest::post('password_confirm', '');

        $error = authHelper::validateNewPassword($password, $password_confirm);
        if ($error !== null) {
            $this->renderCodeForm($error);
            return;
        }

        // Read before complete() clears the session handle — see
        // authRecovery::pendingChannel()'s own docblock.
        $channel = authRecovery::pendingChannel();

        $contact_id = authRecovery::complete();
        if ($contact_id === null) {
            authRecovery::clearHandle();
            $this->renderRequestForm(_w('This link is invalid or has expired.'));
            return;
        }

        // Never the login-scope reset here — that key is whatever is typed
        // on the sign-in form, and a phone used only for recovery may have
        // no such counterpart. See finishRecovery()'s $reset_login_throttle.
        $this->finishRecovery($contact_id, $password, false, (string)$channel);
    }

    // -------------------------------------------------------------------------
    // Step 1b: a link-based provider (email) — recovery/<token>/.
    // -------------------------------------------------------------------------

    private function handleTokenStep(string $token): void
    {
        $row = (new authPasswordRecoveryModel())->getValid($token);
        if (!$row) {
            $this->renderRequestForm(_w('This link is invalid or has expired.'));
            return;
        }

        if (waRequest::method() !== 'post') {
            $this->renderPasswordForm($token);
            return;
        }

        $password         = (string)waRequest::post('password', '');
        $password_confirm = (string)waRequest::post('password_confirm', '');

        $error = authHelper::validateNewPassword($password, $password_confirm);
        if ($error !== null) {
            $this->renderPasswordForm($token, $error);
            return;
        }

        $contact_id = authRecovery::completeByToken($token);
        if ($contact_id === null) {
            $this->renderRequestForm(_w('This link is invalid or has expired.'));
            return;
        }

        $this->finishRecovery($contact_id, $password, (string)$row['channel'] === 'email', (string)$row['channel']);
    }

    // -------------------------------------------------------------------------

    /**
     * @param string $channel the recovery_channels id that completed
     *                        ('email'/'phone' for the built-ins, or a plugin
     *                        id) — stamped via authContactStatus::confirmPrimary(),
     *                        which already no-ops for anything that is not
     *                        'email'/'phone', so a plugin channel simply
     *                        stamps nothing rather than needing special
     *                        handling here. Recovering a password proves
     *                        control of whichever identifier it was delivered
     *                        to — one of the four sites that stamp confirmed,
     *                        see docs/adr/005-value-confirmation.md.
     */
    private function finishRecovery(int $contact_id, string $password, bool $reset_login_throttle, string $channel = ''): void
    {
        $contact = new waContact($contact_id);
        if (!$contact->exists()) {
            $this->renderRequestForm(_w('Account not found.'));
            return;
        }

        $contact['password'] = $password;
        $contact->save();

        if ($channel !== '') {
            authContactStatus::confirmPrimary($channel, $contact_id);
        }

        // Any other pending recovery for this contact (a different channel,
        // or an older abandoned request) is invalidated too — completing one
        // must not leave another still-live link/code around to replay.
        (new authPasswordRecoveryModel())->deleteByContact($contact_id);

        if ($reset_login_throttle) {
            $this->resetLoginThrottleForEmail($contact_id);
        }

        // Other active sessions of this contact are invalidated by the
        // framework itself: the session/remember-me token is derived from
        // the password hash (waAuth::getToken), so once the password changes,
        // every other session fails waAuthUser's periodic token re-check and
        // every stale auth_token cookie stops matching. Re-authing here rotates
        // the current session onto the new token so this browser stays logged in.
        wa()->getAuth()->auth(['id' => $contact->getId()]);
        wa()->event('login', $contact);

        $redirect = authHelper::localRedirectUrl(authConfig::get('redirect_after_login'), authHelper::getMyUrl());
        wa()->getResponse()->redirect($redirect);
    }

    /**
     * Clears the identifier-keyed login-scope throttle window for the
     * contact's own primary email — only for a completed email-channel
     * recovery. The recovery row only ever holds a hash of what was typed
     * (never the plain value, see docs/adr/004-recovery-channels.md decision
     * 6), so this reads the contact's actual sign-in email straight from
     * wa_contact_emails instead of trying to recover it from the hash.
     */
    private function resetLoginThrottleForEmail(int $contact_id): void
    {
        $email = (new waContactEmailsModel())
            ->select('email')
            ->where('contact_id = i:id AND sort = 0', ['id' => $contact_id])
            ->fetchField('email');

        if ($email) {
            authThrottle::reset('login', ['login' => $email]);
        }
    }

    // -------------------------------------------------------------------------
    // Rendering — one theme template, one of four steps.
    // -------------------------------------------------------------------------

    private function renderRequestForm(string $error = ''): void
    {
        $this->render('request', $error, '');
    }

    private function renderCodeForm(string $error = ''): void
    {
        $this->render('code', $error, '');
    }

    private function renderPasswordForm(string $token, string $error = ''): void
    {
        $this->render('password', $error, $token);
    }

    private function renderSent(): void
    {
        $this->render('sent', '', '');
    }

    private function render(string $step, string $error, string $token): void
    {
        $this->setLayout(new authFrontendLayout());
        $this->view->assign(['step' => $step, 'error' => $error, 'token' => $token]);
        $this->setThemeTemplate('recovery.html');
    }
}
