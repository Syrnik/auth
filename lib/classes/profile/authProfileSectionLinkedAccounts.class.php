<?php

/**
 * The awkward end of the section contract: no waContactForm, no contact fields,
 * a value list keyed by provider rather than by index, and a rule that has to
 * look at the whole account before it can answer whether one row may be removed.
 *
 * Its data lives in wa_contact_data under <source>_id
 * (authContactResolver::getSourceField()), which is where an OAuth login writes
 * it. Availability comes from the domain's login_methods and nothing else —
 * credentials are not governed by the site's personal_fields, decision 1 of
 * docs/adr/001-profile-config-boundaries.md.
 *
 * If this section fits the contract, sections built out of form fields will too.
 */
class authProfileSectionLinkedAccounts extends authProfileSectionBase
{
    protected static $id = 'linked_accounts';

    /** @var array|null accounts by method id, see getAccounts() */
    private $accounts = null;

    public function getName(): string
    {
        return _w('Linked accounts');
    }

    /**
     * The section exists for a domain that offers at least one OAuth way in.
     * Whether the visitor has linked anything is isEmpty()'s question.
     */
    public function isAvailable(): bool
    {
        return (bool)authHelper::getOAuthMethods();
    }

    public function isEmpty(): bool
    {
        foreach ($this->getAccounts() as $account) {
            if ($account['is_linked']) {
                return false;
            }
        }
        return true;
    }

    /**
     * Every OAuth provider the domain offers, linked or not, keyed by method id
     * and in the order the domain lists them:
     *
     *   id           method id as used in login_methods ('waid', 'oidc_plugin:gitlab')
     *   name         provider name to show
     *   source       wa_contact_data field this link is stored under, without '_id'
     *   is_linked    whether this contact has an account there
     *   value        the provider-side user id, so two accounts are distinguishable
     *   link_url     where to send the visitor to link, null if unconfigured
     *   removal_lock why this one may not be unlinked, or null
     *
     * @return array
     */
    public function getAccounts(): array
    {
        if ($this->accounts === null) {
            $this->accounts = $this->buildAccounts();
        }
        return $this->accounts;
    }

    /**
     * Decision 3 of ADR 001: whatever else happens, the visitor keeps a way in.
     * With one login factor left there is nothing here that may be unlinked, and
     * the reason has to be readable — a button that silently does nothing is
     * worse than no button.
     */
    public function getRemovalLock(?int $index = null): ?string
    {
        return $this->lockFor($this->getAccounts());
    }

    /**
     * Ways this contact could currently sign in: a usable password (when the
     * domain offers a password-based method at all) plus every linked account
     * whose provider the domain still offers.
     *
     * Phone OTP is deliberately not counted. It is a real factor, but it depends
     * on the contact's phone and on an SMS channel being configured, and the
     * phone section (stage 5) faces the same question from the other side —
     * counting it here on a guess would make the lock weaker, and this lock only
     * has value while it is the pessimistic one.
     */
    public function countLoginFactors(): int
    {
        return $this->countFactors($this->getAccounts());
    }

    /**
     * Unlinks one account: $data = ['unlink' => method_id].
     *
     * Linking is not done here — it is an OAuth round trip through
     * authFrontendCallback, and the section only offers the URL to start it.
     */
    public function save(array $data, ?int $index = null): bool
    {
        $this->errors = [];

        $id = (string)($data['unlink'] ?? '');
        if ($id === '') {
            $this->addError('', _w('Nothing to unlink.'));
            return false;
        }

        $accounts = $this->getAccounts();
        if (!isset($accounts[$id]) || !$accounts[$id]['is_linked']) {
            $this->addError('', _w('This account is not linked.'));
            return false;
        }

        // Re-read the lock instead of trusting the rendered state: the page the
        // visitor is acting on may have been drawn before the other factor went.
        $lock = $accounts[$id]['removal_lock'];
        if ($lock !== null) {
            $this->addError('', $lock);
            return false;
        }

        // null, not '', is what deletes a data row (waContactDataStorage::save()).
        $errors = $this->contact->save([
            authContactResolver::getSourceField($accounts[$id]['source']) => null,
        ]);
        if ($errors) {
            foreach ($errors as $messages) {
                foreach ((array)$messages as $message) {
                    $this->addError('', $message);
                }
            }
            return false;
        }

        $this->accounts = null;

        return true;
    }

    // -------------------------------------------------------------------------

    protected function getTemplateVars(string $mode, ?int $index = null): array
    {
        return parent::getTemplateVars($mode, $index) + [
            'accounts'      => $this->getAccounts(),
            'login_factors' => $this->countLoginFactors(),
        ];
    }

    private function countFactors(array $accounts): int
    {
        $factors = 0;

        if (authHelper::hasPasswordLogin() && authContactResolver::hasUsablePassword($this->contact)) {
            $factors++;
        }

        foreach ($accounts as $account) {
            if ($account['is_linked']) {
                $factors++;
            }
        }

        return $factors;
    }

    private function lockFor(array $accounts): ?string
    {
        if ($this->countFactors($accounts) > 1) {
            return null;
        }
        return _w('This is the only way you can sign in. Add another one before unlinking this account.');
    }

    private function buildAccounts(): array
    {
        $accounts = [];

        foreach (authHelper::getOAuthMethods() as $id => $method) {
            $source = $this->getSource($id, $method);
            $value  = (string)$this->contact->get(authContactResolver::getSourceField($source));

            $accounts[$id] = [
                'id'           => $id,
                'name'         => authHelper::methodName($method) ?: $id,
                'source'       => $source,
                'is_linked'    => $value !== '',
                'value'        => $value,
                'link_url'     => $this->getLinkUrl($method),
                'removal_lock' => null,
            ];
        }

        // The lock is a property of the whole set — it takes counting what is
        // linked — so it is filled in on a second pass. Only a link that exists
        // can be locked; an empty row has nothing to remove.
        $lock = $this->lockFor($accounts);
        foreach ($accounts as $id => $account) {
            $accounts[$id]['removal_lock'] = $account['is_linked'] ? $lock : null;
        }

        return $accounts;
    }

    /**
     * The 'source' this method reports when it hands OAuth data to
     * authContactResolver, which is what the link is stored under.
     *
     * For framework adapters our method id and the provider id differ in one
     * case ('waid' is Webasyst ID's short alias for 'webasystID'), and the
     * adapter reports the provider id. Plugins answer for themselves.
     */
    private function getSource(string $id, $method): string
    {
        if ($method instanceof authPlugin) {
            return $method->getLinkSource();
        }

        $adapters = authPluginManager::getSystemAdapters();

        return $adapters[$id] ?? $id;
    }

    private function getLinkUrl($method): ?string
    {
        try {
            return $method->getCallbackUrl() ?: null;
        } catch (BadMethodCallException $e) {
            // Provider enabled but not configured: nothing to link to yet.
            return null;
        }
    }
}
