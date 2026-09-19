<?php

/**
 * Base for the credential twins of the email and phone sections: the value the
 * domain signs this visitor in with.
 *
 * Decision 2 of docs/adr/001-profile-config-boundaries.md. The same string is a
 * contact field to the site and a credential to this app, and the two have
 * nothing in common past their storage:
 *
 *   - a new value is proven before it replaces the old one, not saved on submit;
 *   - the value that is the login cannot be given up while it is the way in;
 *   - it has states a contact field never has ("a code is on its way").
 *
 * Availability comes from login_methods alone — never from the site's
 * personal_fields, decision 1 of the same record. An admin unticking "email" on
 * the site map must not take away the ability to change the email one signs in
 * with, in the app that exists for signing in.
 *
 * Index 0 is the login: it is the value with sort = 0, which is the one every
 * built-in method looks up (authEmailMethod::findByEmail(),
 * authPhoneMethod::findByPhone(), both "AND sort = 0"). Values past it are
 * ordinary contact values and behave exactly as they do in the plain section.
 *
 * The issue/send/rollback mechanics of proving a value are shared with the
 * plain twins (authProfileSectionEmail/Phone, decision 1 of
 * docs/adr/005-value-confirmation.md) through authProfileConfirmableValueTrait;
 * what stays here is what only a login value needs: withholding the value
 * until it is proven, and refusing a change outright when the new value is
 * already someone else's login.
 */
abstract class authProfileSectionLogin extends authProfileSectionMultiField implements authProfileSectionConfirmable
{
    use authProfileConfirmableValueTrait;

    /**
     * Errors from a getConfirmationUrl() that refused the change, kept until
     * save() can answer with them. See save().
     *
     * @var array|null
     */
    private $refusal = null;

    /**
     * A change that could not even be sent for confirmation is not a change to
     * store.
     *
     * The save endpoint asks getConfirmationUrl() first and saves whenever it
     * answers null (authFrontendMySaveController::execute()) — null means "this
     * one is mine to store", which is the right answer for a second address or
     * an unchanged value, and the wrong one for a refusal. Without this the
     * refused value would be written directly, skipping the very proof the
     * refusal was about, and the errors would be cleared by save() on its way
     * past.
     */
    public function save(array $data, ?int $index = null): bool
    {
        if ($this->refusal !== null) {
            $this->errors = $this->refusal;
            return false;
        }

        return parent::save($data, $index);
    }

    /**
     * The domain signs people in with this field. personal_fields is not
     * consulted, on purpose — see the class comment.
     */
    public function isAvailable(): bool
    {
        return authHelper::isLoginField($this->getConfirmableField()) && $this->getField() !== null;
    }

    /**
     * Whether the proof is a code typed back (phone, by SMS) or a link followed
     * (email). Provided by whichever channel trait (authProfileConfirmEmailTrait
     * / authProfileConfirmPhoneTrait) the concrete subclass uses.
     */
    abstract public function usesCode(): bool;

    /**
     * Deliver the proof to the new value. $code is null when the proof is a link.
     *
     * Failures throw: the caller has to know the change never left the building,
     * or the visitor waits for a message that is not coming.
     */
    abstract protected function sendProof(string $value, string $token, ?string $code): void;

    /**
     * How the value is written before it is compared or stored. Provided by
     * whichever channel trait the concrete subclass uses.
     */
    abstract protected function normalize(string $value): string;

    /**
     * Stamps a proven value confirmed (authContactStatus::confirmEmail()/
     * confirmPhone()). Provided by whichever channel trait the concrete
     * subclass uses.
     */
    abstract protected function stampConfirmed(string $value): bool;

    /**
     * Whether another account already signs in with this value. Two accounts on
     * one login make the method ambiguous — it resolves to the lowest id — so
     * the second one could never sign in with it anyway.
     */
    abstract protected function isTaken(string $value): bool;

    /**
     * The value currently signing this visitor in.
     */
    public function getLoginValue(): string
    {
        $value = $this->getValue(0);

        return $value === null ? '' : (string)($value['value'] ?? '');
    }

    /**
     * Whether the submitted change has to be proven, and where that starts.
     *
     * Null for everything that is not a new login value: a second address, an
     * unchanged one, a removal (which getRemovalLock() guards instead). The
     * pending change is recorded and the proof sent from here, because this is
     * the moment the request exists — the save endpoint only forwards the URL.
     */
    public function getConfirmationUrl(array $data, ?int $index = null): ?string
    {
        if (($index ?? 0) !== 0) {
            return null;
        }

        $field_id = $this->getConfirmableField();
        $value    = $this->extractSubmitted($data, 0);
        $value    = $this->normalize(is_array($value) ? (string)($value['value'] ?? '') : (string)$value);

        if ($value === '' || $value === $this->normalize($this->getLoginValue())) {
            // Nothing new to prove. An empty value is a removal and belongs to
            // save(), where the lock is.
            return null;
        }

        if ($this->isTaken($value)) {
            // Refusing rather than answering with a URL: the save endpoint
            // treats a URL as "the flow took over" and would report success on
            // a change that cannot happen.
            return $this->refuse($field_id, _w('This value is already used by another account.'));
        }

        $url = $this->startConfirmation($value);
        if ($url === null) {
            // startConfirmation() has already recorded the error via addError();
            // mark it a refusal too so save() (above) does not write the
            // submitted value straight through, skipping the very proof this
            // was about.
            $this->refusal = $this->errors;
        }

        return $url;
    }

    /**
     * Records why the change cannot even be attempted and answers null, which
     * is what getConfirmationUrl() returns for "not a confirmable change" —
     * save() tells the two apart by the recorded refusal.
     */
    private function refuse(string $field_id, string $message): ?string
    {
        $this->errors  = [];
        $this->addError($field_id, $message);
        $this->refusal = $this->errors;

        return null;
    }

    public function applyConfirmedValue(string $value): bool
    {
        $this->errors = [];

        $field = $this->getField();
        if (!$field || !$this->isAvailable()) {
            $this->addError('', _w('This change can no longer be applied.'));
            return false;
        }

        $value = $this->normalize($value);

        // Already one of this contact's own stored values — a secondary
        // address at index > 0, reconfirmed through sendConfirmation()
        // (authProfileConfirmableValueTrait) the same way the plain twin's
        // own secondary values are, per decision 1 of
        // docs/adr/005-value-confirmation.md: this section's list holds both
        // the login (index 0) and every secondary value for the same field,
        // and only a row issued for index 0 by getConfirmationUrl() below is
        // about replacing the login. There is no index on applyConfirmedValue()
        // to tell the two cases apart by, but a value getConfirmationUrl()
        // ever issued a row for is guaranteed NOT to already be in this list
        // (it checks that before issuing), so a value found here can only
        // have arrived through the other path — nothing to write, only the
        // status to stamp.
        if ($this->isAlreadyStored($value)) {
            if (!$this->stampConfirmed($value)) {
                $this->addError('', _w('This value is no longer on your profile.'));
                return false;
            }
            return true;
        }

        // Time has passed since the change was asked for: the value may have
        // been claimed in the meantime, and it is this check, not the token,
        // that keeps two accounts off one login.
        if ($this->isTaken($value)) {
            $this->addError('', _w('This value is already used by another account.'));
            return false;
        }

        // Straight past getConfirmationUrl(): this value is proven, and asking
        // for it again would start a second flow instead of finishing this one.
        if (!parent::save([$field->getId() => [0 => $value]], 0)) {
            return false;
        }

        // The save just wrote this exact value, so a false here (the writer
        // finding nothing to stamp) is an anomaly, not something the visitor
        // did wrong — stampConfirmed() already logs it, nothing more to do.
        $this->stampConfirmed($value);

        return true;
    }

    /**
     * Whether $value (already normalized) sits somewhere in this contact's
     * current list for this field — any index, login or not.
     */
    private function isAlreadyStored(string $value): bool
    {
        foreach ($this->getList() as $item) {
            if ($this->normalize((string)($item['value'] ?? '')) === $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * The login value stays put; everything after it is an ordinary value.
     *
     * Two different refusals, because they are two different situations and one
     * message would be wrong for both. With other values present, removing this
     * one would promote the next in line to being the login — a login change
     * that skipped its proof, which is the thing decision 2 exists to prevent.
     * With none present, it is decision 3: the last way in does not go.
     */
    public function getRemovalLock(?int $index = null): ?string
    {
        if (($index ?? 0) !== 0) {
            return null;
        }

        if (count($this->getList()) > 1) {
            return _w('This is what you sign in with. Remove the other values first, or the next one would take its place without being confirmed.');
        }

        if ($this->countAlternativeFactors() < 1) {
            return _w('This is the only way you can sign in. Add another one before removing it.');
        }

        return null;
    }

    // -------------------------------------------------------------------------

    /**
     * Ways this contact could still sign in if this value went: linked OAuth
     * accounts, and any OTHER field the domain also accepts as a login and that
     * the contact has filled in.
     *
     * A password is deliberately not counted on its own. Password methods
     * authenticate by this very field (authEmailMethod::USES_PASSWORD), so
     * "there is a password" is not an alternative to the value that carries it —
     * counting it would answer "you can still sign in" about a form the visitor
     * would have nothing to type into.
     */
    protected function countAlternativeFactors(): int
    {
        $factors = 0;

        $linked = new authProfileSectionLinkedAccounts($this->contact);
        if ($linked->isAvailable()) {
            foreach ($linked->getAccounts() as $account) {
                if ($account['is_linked']) {
                    $factors++;
                }
            }
        }

        foreach (authHelper::getLoginFields() as $field_id) {
            if ($field_id === $this->getConfirmableField()) {
                continue;
            }
            if ($this->contact->get($field_id)) {
                $factors++;
            }
        }

        return $factors;
    }

    /**
     * Credentials ignore personal_fields, so the field is taken straight from
     * the contact field registry instead of through authProfileFields::filter(),
     * which is the site map's filter — decision 1 of ADR 001.
     *
     * @return array field_id => waContactField
     */
    protected function getEnabledFields(): array
    {
        $field = waContactFields::get($this->getConfirmableField(), 'enabled');

        return $field ? [$field->getId() => $field] : [];
    }

    protected function getTemplateVars(string $mode, ?int $index = null): array
    {
        return parent::getTemplateVars($mode, $index) + $this->confirmableTemplateVars() + [
            'login_value' => $this->getLoginValue(),
            'uses_code'   => $this->usesCode(),
            'is_login'    => ($index ?? 0) === 0,
        ];
    }
}
