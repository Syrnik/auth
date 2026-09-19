<?php

/**
 * POST my/confirm/send/<section>/ — starts (or restarts) confirmation of an
 * already-stored value: the "Confirm" action next to an unconfirmed
 * secondary email/phone in my/ (decision 1 of docs/adr/005-value-confirmation.md).
 *
 * Unlike my/save/<section>/, nothing is written to the contact here — the
 * value is already there. This only issues a fresh auth_profile_confirm row
 * and sends the proof, through authProfileSectionConfirmable::sendConfirmation()
 * (authProfileConfirmableValueTrait). Section id and value index are parsed
 * exactly as my/save/<section>/ parses them — authProfileSectionRequestTrait,
 * shared with authFrontendMySaveController so the two endpoints cannot drift
 * on what counts as an unknown section or a malformed index.
 *
 * Authentication and CSRF are the framework's own, through 'secure' => true
 * on the route together with 'csrf' => true in app.php — see
 * authFrontendMySaveController's own docblock, identical here.
 */
class authFrontendMyConfirmSendController extends waJsonController
{
    use authProfileSectionRequestTrait;

    public function execute()
    {
        if (waRequest::method() !== 'post') {
            throw new waException(_w('This address only accepts a form submission.'), 405);
        }

        $section = $this->getSection();
        if (!($section instanceof authProfileSectionConfirmable)) {
            // Same reasoning as an unknown section id: there is nothing here
            // to confirm, and the visitor should never have been offered the
            // action that posted here.
            throw new waException(_w('This profile section cannot be confirmed.'), 404);
        }

        $index = $this->getIndex($section);

        if ($section->isConfirmed($index)) {
            // Nothing to send — most likely a stale page (confirmed in
            // another tab, or by a mailed link already followed). Sent back
            // to my/ rather than answering with no redirect at all: the JS
            // client (my.profile.js::saveSection()) only recognizes a
            // truthy data.redirect or data.html, and this response carries
            // neither of its own — my/ is also the right landing page for
            // the no-JS form-submit fallback, which would otherwise render
            // this endpoint's bare JSON envelope as the whole page.
            $this->respond($section, $index, authHelper::getMyUrl());
            return;
        }

        // Throttled inside sendConfirmation() itself (authProfileConfirmableValueTrait
        // ::startConfirmation(), scope 'otp_send') — not here, so the same
        // limit also covers authProfileSectionLogin::getConfirmationUrl(),
        // which calls the same method for a new login value.
        $url = $section->sendConfirmation($index);
        if ($url === null) {
            $this->fail($section, $index);
            return;
        }

        $this->logAction(
            'my_profile_confirm_sent',
            ['section' => $section->getId()],
            null,
            $this->getContact()->getId()
        );

        $this->respond($section, $index, $url);
    }

    /**
     * Same envelope shape as authFrontendMySaveController::display() —
     * status/data(/errors), 'data' carrying the redirect for a client that
     * wants to follow it itself rather than relying on a 302.
     */
    public function display()
    {
        if (waRequest::isXMLHttpRequest()) {
            $this->getResponse()->addHeader('Content-Type', 'application/json');
        }
        $this->getResponse()->sendHeaders();

        $result = [
            'status' => $this->errors ? 'fail' : 'ok',
            'data'   => $this->response,
        ];
        if ($this->errors) {
            $result['errors'] = $this->errors;
        }

        echo waUtils::jsonEncode($result);
    }

    // -------------------------------------------------------------------------

    private function respond(authProfileSectionConfirmable $section, ?int $index, string $url): void
    {
        if (!waRequest::isXMLHttpRequest()) {
            $this->getResponse()->redirect($url);
        }

        $this->response = [
            'section'  => $section->getId(),
            'index'    => $index,
            'redirect' => $url,
        ];
    }

    /**
     * my.profile.js::saveSection() only ever acts on a truthy data.redirect
     * or a data.html — a delivery failure is neither, so it has to be turned
     * into one of those here, not left to the generic error envelope the way
     * authFrontendMySaveController::respondFailure() can (that one always
     * has a redirect too, my/ on failure exactly like on success).
     *
     * XHR: the section's own view partial, re-rendered with $errors already
     * on it (authProfileSectionBase::getTemplateVars() carries $this->errors
     * through as-is) — nothing was written, so view mode is still correct,
     * and the JS swap has real markup to show instead of falling through to
     * a full page reload it cannot make sense of either. No-JS: straight to
     * my/ — the specific message does not survive the redirect, the same
     * simplification authFrontendMySaveController accepts for its own
     * request-level failures (a malformed index, an unknown section).
     */
    private function fail(authProfileSectionConfirmable $section, ?int $index): void
    {
        $errors = $section->getErrors();
        $this->errors = $errors ?: ['' => [_w('The confirmation could not be sent.')]];

        if (!waRequest::isXMLHttpRequest()) {
            $this->getResponse()->redirect(authHelper::getMyUrl());
        }

        $this->response = [
            'section' => $section->getId(),
            'index'   => $index,
            'html'    => $section->render(authProfileSection::MODE_VIEW, $index),
        ];
    }
}
