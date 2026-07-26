<?php

/**
 * Shared contact resolution for any OAuth-style method (Webasyst ID, VK, Google, ...).
 * Expects the normalized shape all waAuthAdapter::auth() implementations return:
 * ['source' => ..., 'source_id' => ..., 'email' => ..., 'photo_url' => ..., ...].
 */
class authContactResolver
{
    /**
     * Marks a password hash as unusable, see setUnusablePassword(). No hash
     * function produces a value starting with it, so the marker cannot collide
     * with a real password.
     */
    const UNUSABLE_PASSWORD_PREFIX = '!';

    /**
     * Find an existing contact for this identity, or run signup guards and
     * create a new one. Guards run against the raw OAuth data BEFORE any
     * contact is created, same as the plain registration form — a blocked
     * signup never touches the database, so there is nothing to roll back.
     * Respects the signup_enabled setting: an unknown OAuth identity is not
     * silently turned into a new account just because registration is off.
     * Returns [contact_id, is_new]. Throws authGuardException if blocked.
     */
    public static function resolve(array $data): array
    {
        $contact_id = self::find($data);
        if ($contact_id !== null) {
            return [$contact_id, false];
        }

        if (!authConfig::get('signup_enabled')) {
            throw new authGuardException('Регистрация отключена.');
        }

        foreach (authPluginManager::getGuardsEnabled('signup') as $guard) {
            $guard->checkSignup($data);
        }

        return [self::create($data), true];
    }

    /**
     * The wa_contact_data field an OAuth identity is stored under. One place,
     * because both ends of the link need it: this class writes it on login, and
     * the profile's linked-accounts section reads it back to show and unlink.
     */
    public static function getSourceField(string $source): string
    {
        return $source . '_id';
    }

    /**
     * Look up an existing contact by source id or linked email. Returns null
     * if no match — the caller should treat this as a new signup.
     */
    public static function find(array $data): ?int
    {
        $field              = self::getSourceField($data['source']);
        $contact_data_model = new waContactDataModel();

        $row = $contact_data_model->getByField([
            'field' => $field,
            'value' => $data['source_id'],
            'sort'  => 0,
        ]);
        if ($row) {
            return (int) $row['contact_id'];
        }

        $email = self::extractEmail($data);
        if ($email && self::canLinkByEmail($data)) {
            $contact_model = new waContactModel();
            $contact_id    = (int) $contact_model->query(
                "SELECT c.id FROM wa_contact_emails e
                 JOIN wa_contact c ON e.contact_id = c.id
                 WHERE e.email = s:email AND e.sort = 0 AND c.password != ''",
                ['email' => $email]
            )->fetchField('id');

            if ($contact_id) {
                $contact_data_model->insert([
                    'contact_id' => $contact_id,
                    'field'      => $field,
                    'value'      => $data['source_id'],
                    'sort'       => 0,
                ]);
                return $contact_id;
            }
        }

        return null;
    }

    /**
     * Whether an OAuth identity may be attached to an existing password account
     * purely because their email addresses match.
     *
     * This is an account-takeover surface: a provider that lets a user sign in
     * with an unverified email would let an attacker claim someone else's local
     * account just by putting that victim's address on their social profile.
     *
     * So we only allow it when the email is trustworthy: an adapter/plugin that
     * explicitly vouches for it (email_verified / verified_email in the payload
     * — e.g. our WAID adapter sets this), or, failing an explicit signal, when
     * the admin has opted in via the oauth_link_by_email setting. Matching by
     * the provider's own source_id is always safe and handled before this.
     */
    private static function canLinkByEmail(array $data): bool
    {
        foreach (['email_verified', 'verified_email'] as $flag) {
            if (array_key_exists($flag, $data)) {
                return (bool) $data[$flag];
            }
        }
        return (bool) authConfig::get('oauth_link_by_email', false);
    }

    /**
     * Create a new contact from OAuth data. Caller is responsible for
     * running signup guards first — this method does not check them.
     */
    public static function create(array $data): int
    {
        $field      = self::getSourceField($data['source']);
        $contact    = new waContact();
        $save_data  = $data;
        $save_data[$field]             = $data['source_id'];
        $save_data['create_method']    = $data['source'];
        $save_data['create_app_id']    = 'auth';
        unset(
            $save_data['source'], $save_data['source_id'], $save_data['photo_url'],
            $save_data['email_verified'], $save_data['verified_email']
        );
        // Unusable password so the account cannot be brute-forced via password form.
        self::setUnusablePassword($contact);
        $contact->save($save_data);

        return (int) $contact->getId();
    }

    /**
     * Give a contact a password it can never sign in with, while keeping the
     * hash non-empty: the framework and this app both read `password != ''` as
     * "this is a login account" (waAuth, authEmailMethod::findByEmail), so an
     * empty hash would change more than intended.
     */
    public static function setUnusablePassword(waContact $contact): void
    {
        $contact->setPassword(self::UNUSABLE_PASSWORD_PREFIX . uniqid((string) time(), true), true);
    }

    /**
     * Whether this contact could actually sign in with a password.
     *
     * `password != ''` is not the same question: an account created from OAuth
     * data carries the placeholder above, which every such check reads as a
     * password the user does not have and has no way to use. Counting it as a
     * login factor is exactly how someone gets to unlink their only real way in
     * — see decision 3 of docs/adr/001-profile-config-boundaries.md.
     *
     * Two placeholder shapes are recognised: the current prefixed one, and the
     * earlier "a real hash with its last character cut off". The latter is
     * detected by width — waContact::getPasswordHash() is fixed-width (md5 by
     * default, whatever wa_password_hash() returns otherwise), so a hash one
     * character short is never a hash this installation produced.
     */
    public static function hasUsablePassword(waContact $contact): bool
    {
        static $hash_length = null;
        if ($hash_length === null) {
            $hash_length = strlen(waContact::getPasswordHash('x'));
        }

        $hash = (string) $contact->get('password');

        return $hash !== ''
            && strncmp($hash, self::UNUSABLE_PASSWORD_PREFIX, strlen(self::UNUSABLE_PASSWORD_PREFIX)) !== 0
            && strlen($hash) !== $hash_length - 1;
    }

    private static function extractEmail(array $data): string
    {
        if (empty($data['email'])) {
            return '';
        }
        $email = $data['email'];
        if (is_array($email)) {
            $email = $email[0]['value'] ?? ($email[0] ?? '');
        }
        return is_string($email) ? trim($email) : '';
    }
}
