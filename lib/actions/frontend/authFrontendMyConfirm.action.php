<?php

/**
 * The flow a login change goes through instead of being saved: my/confirm/.
 *
 * Decision 2 of docs/adr/001-profile-config-boundaries.md — an email or a phone
 * that is an active login method is proven before it replaces the old one. The
 * section refuses the save and hands back this address
 * (authProfileSectionConfirmable::getConfirmationUrl()); the pending value waits
 * in auth_profile_confirm and the contact is not touched until the proof lands.
 *
 * Two addresses, one action, because they are two ends of one thing:
 *
 *   my/confirm/<token>/     the link mailed to the new address — proof is
 *                           having received it, so it applies and returns
 *   my/confirm/?field=phone the page that waits: a form for the code sent by
 *                           SMS, or, for an email, "we sent you a link"
 *
 * Proof is always delivered to the NEW value, never the old one. A confirmation
 * sent to the address already on file proves the visitor owns what they already
 * had, and would let a typo — or someone else's address — become the login.
 *
 * Both routes are 'secure': this changes an account's way in, so a leaked link
 * on its own must not be enough. The visitor is editing their own profile and
 * is signed in anyway.
 *
 * What a value means is never decided here. This class owns tokens, codes,
 * expiry and rate limits; where a confirmed value goes in the contact's list and
 * what has to hold before it may go there stays with the section, through
 * applyConfirmedValue().
 */
class authFrontendMyConfirmAction extends waViewAction
{
    public function execute(): void
    {
        $token = waRequest::param('token', '', 'string');
        if ($token !== '') {
            $this->confirmByToken($token);
            return;
        }

        $this->waitForCode();
    }

    // -------------------------------------------------------------------------

    /**
     * The mailed link. Nothing to fill in: the token is the proof, so the change
     * is applied and the visitor lands back on the profile.
     */
    private function confirmByToken(string $token): void
    {
        $model = new authProfileConfirmModel();
        $row   = $model->getValid($token);

        if (!$this->isOwnRow($row)) {
            $this->showPage(null, _w('This confirmation link is no longer valid. Please start the change again.'));
            return;
        }

        // A row confirmed by a code is not confirmed by holding its token: the
        // token addresses the request, the code proves the phone. Send the
        // visitor to the form instead of applying on the strength of a URL.
        if ($model->needsCode($row)) {
            $this->getResponse()->redirect(self::getWaitUrl($row['field']));
            return;
        }

        $this->apply($model, $row);
    }

    /**
     * The waiting page: a code form for a phone, a "check your mail" notice for
     * an email, and the change itself for both — the visitor has to see which
     * value they are confirming, or a typo stays invisible until they are
     * locked out.
     */
    private function waitForCode(): void
    {
        $model = new authProfileConfirmModel();
        $field = waRequest::request('field', '', 'string');
        $row   = $field === '' ? null : $model->getPending($this->getContact()->getId(), $field);

        if (!$row) {
            // Nothing pending: either it was confirmed already, or it expired,
            // or the address was typed by hand. The profile answers all three.
            $this->getResponse()->redirect(authHelper::getMyUrl());
            return;
        }

        if (waRequest::method() !== 'post') {
            $this->showPage($row);
            return;
        }

        if (waRequest::post('cancel')) {
            $model->deleteById($row['id']);
            $this->getResponse()->redirect(authHelper::getMyUrl());
            return;
        }

        if (!$model->needsCode($row)) {
            // An email is confirmed by following its link; there is nothing to
            // submit here.
            $this->showPage($row);
            return;
        }

        $code = trim((string)waRequest::post('code', '', 'string'));
        if ($code === '') {
            $this->showPage($row, _w('Enter the code from the message.'));
            return;
        }

        if (!$model->verifyCode($row, $code)) {
            // verifyCode() throws the request away once the guesses run out, so
            // re-read it: gone means there is nothing left to retry.
            $row = $model->getPending($this->getContact()->getId(), $field);
            if (!$row) {
                $this->showPage(null, _w('Too many incorrect codes. Please start the change again.'));
                return;
            }

            $this->showPage($row, sprintf(
                _w('Incorrect code. Attempts left: %d.'),
                $model->attemptsLeft($row)
            ));
            return;
        }

        $this->apply($model, $row);
    }

    /**
     * Hands the proven value to the section that asked for it and clears the
     * request either way — a value the section refuses now (taken by someone
     * else in the meantime, no longer a login method) will not start passing on
     * a retry, and leaving the row would offer a form that can never succeed.
     */
    private function apply(authProfileConfirmModel $model, array $row): void
    {
        $section = authProfileSectionRegistry::getConfirmable((string)$row['field'], $this->getContact());
        if (!$section) {
            $model->deleteById($row['id']);
            $this->showPage(null, _w('This change can no longer be applied.'));
            return;
        }

        $applied = $section->applyConfirmedValue((string)$row['value']);
        $model->deleteById($row['id']);

        if (!$applied) {
            $this->showPage(null, $this->firstError($section) ?: _w('This change could not be applied.'));
            return;
        }

        wa()->getStorage()->set('my/profile/updated', true);
        $this->getResponse()->redirect(authHelper::getMyUrl());
    }

    /**
     * A pending row belongs to the visitor, or it is not theirs to confirm.
     *
     * The token is unguessable, but it travels by mail and mail gets forwarded;
     * without this check a link handed to someone else would change that
     * person's login instead — the account whose mailbox received it.
     */
    private function isOwnRow(?array $row): bool
    {
        // Both sides cast: waContact::getId() hands back whatever the record
        // holds, which is a string when the contact came from the database, and
        // an identity check against it would reject every genuine link.
        return $row && (int)$row['contact_id'] === (int)$this->getContact()->getId();
    }

    private function showPage(?array $row, string $error = ''): void
    {
        $this->setLayout(new authFrontendLayout());
        $this->setThemeTemplate('my.confirm.html');

        $model = new authProfileConfirmModel();

        $this->view->assign([
            'error'          => $error,
            'field'          => $row ? $row['field'] : '',
            'value'          => $row ? $row['value'] : '',
            'needs_code'     => $row ? $model->needsCode($row) : false,
            'attempts_left'  => $row ? $model->attemptsLeft($row) : 0,
            'csrf_token'     => authHelper::getCsrfToken(),
            'my_url'         => authHelper::getMyUrl(),
        ]);
    }

    private function firstError(authProfileSection $section): string
    {
        foreach ($section->getErrors() as $messages) {
            foreach ((array)$messages as $message) {
                if (strlen((string)$message)) {
                    return (string)$message;
                }
            }
        }

        return '';
    }

    private function getContact(): waContact
    {
        return wa()->getUser();
    }

    // -------------------------------------------------------------------------

    /**
     * Where a section sends the visitor after asking for a change: the page that
     * waits for the proof.
     *
     * Built here rather than in authHelper because it is this action's own
     * address in this action's own shape, and the section only forwards it.
     */
    public static function getWaitUrl(string $field): string
    {
        return wa()->getRouteUrl('auth/frontend/myConfirm', [], true).'?'.http_build_query(['field' => $field]);
    }

    /**
     * The link mailed to a new address.
     */
    public static function getTokenUrl(string $token): string
    {
        return wa()->getRouteUrl('auth/frontend/myConfirm', ['token' => $token], true);
    }
}
