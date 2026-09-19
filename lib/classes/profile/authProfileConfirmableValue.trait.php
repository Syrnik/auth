<?php

/**
 * Shared plumbing for a section whose value is proven by sending it back to
 * itself — a link followed (email) or a code typed back (phone). Two
 * independent callers use it:
 *
 *   - authProfileSectionLogin::getConfirmationUrl() — a new value, not yet
 *     stored, withheld until proven. startConfirmation() is what withholds
 *     it: nothing is written to the contact until the token/code comes back.
 *   - authProfileSectionEmail/Phone's sendConfirmation() — an already-stored
 *     value, confirmed out of band from an explicit "Confirm" action
 *     (my/confirm/send/<section>/, authFrontendMyConfirmSendController), not
 *     from a save. Nothing is withheld: the value stays on the contact
 *     either way, only its status changes.
 *
 * Requires, from whatever channel trait is used alongside this one
 * (authProfileConfirmEmailTrait / authProfileConfirmPhoneTrait):
 * getConfirmableField(), usesCode(), sendProof(), normalize(). Requires, from
 * authProfileSectionMultiField/authProfileSectionBase: getValue(), getId(),
 * $this->contact, addError(), $this->errors.
 */
trait authProfileConfirmableValueTrait
{
    /**
     * The change this contact is currently waiting to confirm for this
     * section's field, or null. Read by the profile section, which has to
     * say "we sent a code to +7…, confirm it" instead of offering the same
     * edit form again.
     */
    public function getPending(): ?array
    {
        return (new authProfileConfirmModel())
            ->getPending((int)$this->contact->getId(), $this->getConfirmableField());
    }

    /**
     * The stored value at $index, normalized the way this field compares
     * values — or null when there is nothing there yet.
     */
    public function getConfirmableValue(?int $index = null): ?string
    {
        $row = $this->getValue($index ?? 0);
        if (!$row || empty($row['value'])) {
            return null;
        }

        return $this->normalize((string)$row['value']);
    }

    /**
     * Whether the stored value at $index already carries status = confirmed.
     * Both wa_contact_emails.status and wa_contact_data.status use the
     * literal string 'confirmed' for this — waContactEmailsModel::STATUS_CONFIRMED
     * and waContactDataModel::STATUS_CONFIRMED are the same string — so one
     * comparison serves both fields without asking which one this is.
     */
    public function isConfirmed(?int $index = null): bool
    {
        $row = $this->getValue($index ?? 0);

        return $row !== null && (string)($row['status'] ?? '') === 'confirmed';
    }

    /**
     * Starts (or restarts) confirmation of an already-stored value. Never
     * withholds anything — the value stays on the contact exactly as it is,
     * whatever the outcome; only startConfirmation()'s stamp, once the proof
     * comes back, changes anything.
     */
    public function sendConfirmation(?int $index): ?string
    {
        $value = $this->getConfirmableValue($index);
        if ($value === null || $value === '') {
            $this->addError('', _w('There is nothing to confirm.'));
            return null;
        }

        return $this->startConfirmation($value);
    }

    /**
     * Issues a fresh auth_profile_confirm row for $value, tagged with this
     * section's own id (authProfileSectionRegistry::getSection() reads it
     * back at redemption time — see authFrontendMyConfirmAction::apply()'s
     * docblock for the hijack this prevents), and sends the proof through it.
     * Rolls the row back if delivery fails, so a "check your messages" page
     * is never shown for a message that never went out.
     *
     * Shared by authProfileSectionLogin::getConfirmationUrl() (a new,
     * not-yet-stored value) and sendConfirmation() above (an already-stored
     * one) — the two differ only in when this is called and what happens to
     * the field afterwards, not in how the proof itself is requested and
     * sent.
     *
     * Throttled here rather than by either caller, on the same 'otp_send'
     * scope authPhoneMethod and authPhoneRecoveryProvider already share for
     * SMS spend (decision 8 of docs/adr/005-value-confirmation.md): before
     * this method existed, the login flow's own getConfirmationUrl() had no
     * limit at all on how often a visitor could resubmit a login-email
     * change and trigger another mail/SMS send — sharing this one guarded
     * implementation closes that gap for both callers at once instead of
     * only the new one. Keyed on the value itself, not the channel, so an
     * email confirmation and a phone confirmation never share one budget
     * (authThrottle hashes the key; two different values hash to two
     * different windows regardless of scope).
     */
    protected function startConfirmation(string $value): ?string
    {
        $field_id = $this->getConfirmableField();

        $keys  = ['ip' => waRequest::getIp(), 'login' => $value];
        $state = authThrottle::check('otp_send', $keys);
        if ($state->blocked) {
            $this->errors = [];
            $this->addError($field_id, authThrottle::blockedMessage($state->retryAfter));
            return null;
        }
        authThrottle::hit('otp_send', $keys);

        $model  = new authProfileConfirmModel();
        $issued = $model->issue(
            (int)$this->contact->getId(),
            $field_id,
            $value,
            $this->usesCode(),
            $this->getId()
        );

        try {
            $this->sendProof($value, $issued['token'], $issued['code']);
        } catch (Exception $e) {
            $model->deleteByField('token', $issued['token']);
            waLog::log('auth profile confirmation not sent: '.$e->getMessage(), 'auth.log');

            $this->errors = [];
            $this->addError($field_id, _w('The confirmation could not be sent. Please try again later.'));
            return null;
        }

        return authFrontendMyConfirmAction::getWaitUrl($field_id);
    }

    /**
     * The two template vars every confirmable section's view partial needs
     * ('pending', 'confirm_send_url') — a plain method rather than an
     * override of getTemplateVars() itself, since a trait method is shadowed
     * by a same-named method the using class declares directly, and
     * authProfileSectionLogin already has its own getTemplateVars(). Each of
     * the four confirmable sections' own getTemplateVars() merges this in
     * instead.
     */
    protected function confirmableTemplateVars(): array
    {
        return [
            'pending'           => $this->getPending(),
            'confirm_send_url'  => authHelper::getMyConfirmSendUrl($this->getId()),
        ];
    }
}
