<?php

/**
 * Which contact fields the site allows on the profile page of the current
 * domain — the field selection of waMyProfileAction::getForm()
 * (wa-system/controller/waMyProfileAction.class.php:294), reusable outside an
 * action so that a section can answer "am I available?" without building the
 * profile-wide form first.
 *
 * This mirrors the site app's personal_fields and nothing else. Credential
 * sections (password, linked accounts, account deletion) must NOT consult this
 * class — decision 1 of docs/adr/001-profile-config-boundaries.md: login
 * methods are governed by auth's own login_methods, and two knobs on one thing
 * produce combinations where neither is right.
 */
class authProfileFields
{
    /**
     * Fields the framework falls back to when the domain has no personal_fields
     * of its own. Kept identical to waMyProfileAction::getForm() so that a
     * freshly installed site shows the same profile through both pages.
     */
    const DEFAULTS = ['firstname', 'middlename', 'lastname', 'email', 'phone', 'password', 'password_confirm'];

    private static array $cache = [];

    /**
     * Enabled fields of the current domain as field_id => waContactField,
     * in the framework's own order.
     */
    public static function getEnabled(string $domain = null): array
    {
        // Same domain resolution as waMyProfileAction::getForm(), on purpose:
        // this reads the site app's per-domain config, so it must agree with
        // the framework rather than with authConfig::currentDomain().
        $domain = $domain ?: (string)wa()->getRouting()->getDomain();

        if (!isset(self::$cache[$domain])) {
            self::$cache[$domain] = self::build($domain);
        }

        return self::$cache[$domain];
    }

    public static function isEnabled(string $field_id, string $domain = null): bool
    {
        $enabled = self::getEnabled($domain);
        return isset($enabled[$field_id]);
    }

    /**
     * The given field ids that are enabled, preserving the requested order.
     * This is the filter a field-backed section applies to its own field list.
     *
     * @param string[] $field_ids
     * @return array field_id => waContactField
     */
    public static function filter(array $field_ids, string $domain = null): array
    {
        $enabled = self::getEnabled($domain);
        $result  = [];
        foreach ($field_ids as $field_id) {
            if (isset($enabled[$field_id])) {
                $result[$field_id] = $enabled[$field_id];
            }
        }
        return $result;
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }

    // -------------------------------------------------------------------------

    private static function build(string $domain): array
    {
        $fields = self::getAll();
        $config = self::getDomainConfig($domain);

        $enabled = [];
        foreach ($fields as $field_id => $field) {
            if (!empty($config['personal_fields'][$field_id])) {
                $enabled[$field_id] = $field;
                // password_confirm is not a real contact field and is never
                // listed in personal_fields — it follows the password field.
                if ($field_id === 'password' && isset($fields['password_confirm'])) {
                    $enabled['password_confirm'] = $fields['password_confirm'];
                }
            }
        }

        if (!$enabled) {
            foreach (self::DEFAULTS as $field_id) {
                if (isset($fields[$field_id])) {
                    $enabled[$field_id] = $fields[$field_id];
                }
            }
        }

        return $enabled;
    }

    /**
     * The whole pool a profile can be assembled from: person fields plus the
     * two pseudo-fields the framework adds around them.
     *
     * @return array field_id => waContactField
     */
    private static function getAll(): array
    {
        return ['photo' => new waContactHiddenField('photo', _ws('Photo'))]
            + waContactFields::getAll('person')
            + [
                'password'         => new waContactPasswordField('password', _ws('Password')),
                'password_confirm' => new waContactPasswordField('password_confirm', _ws('Confirm password')),
            ];
    }

    private static function getDomainConfig(string $domain): array
    {
        $path = wa()->getConfig()->getConfigPath('domains/'.$domain.'.php', true, 'site');
        return file_exists($path) ? (array)include($path) : [];
    }
}
