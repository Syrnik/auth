<?php

/**
 * The list of profile sections, their groups and the contact fields each one
 * owns — the single place that knows what the my/ page consists of.
 *
 * The map is declared for the whole page, but only sections whose class exists
 * and whose isAvailable() says yes are ever handed out. Missing classes are
 * skipped on purpose: sections are being implemented one stage at a time, and a
 * half-finished page must not be a fatal error.
 *
 * Availability itself is never decided here. It comes from three unrelated
 * sources (site's personal_fields, auth's login_methods, the contact's linked
 * accounts) and only the section knows which of them applies to it — see
 * docs/adr/001-profile-config-boundaries.md.
 */

declare(strict_types=1);

class authProfileSectionRegistry
{
    const GROUP_PROFILE = 'profile';
    const GROUP_AUTHORIZATION = 'authorization';

    /**
     * section_id => [class, group, fields], in display order.
     *
     * 'fields' lists the contact fields the section owns; sections that are not
     * about contact fields (linked accounts, account deletion) declare none.
     */
    public static function getMap(): array
    {
        return [
            'photo'           => [
                'class'  => 'authProfileSectionPhoto',
                'group'  => self::GROUP_PROFILE,
                'fields' => ['photo'],
            ],
            'name'            => [
                'class'  => 'authProfileSectionName',
                'group'  => self::GROUP_PROFILE,
                'fields' => ['firstname', 'middlename', 'lastname'],
            ],
            'email'           => [
                'class'  => 'authProfileSectionEmail',
                'group'  => self::GROUP_PROFILE,
                'fields' => ['email'],
            ],
            'phone'           => [
                'class'  => 'authProfileSectionPhone',
                'group'  => self::GROUP_PROFILE,
                'fields' => ['phone'],
            ],
            'address'         => [
                'class'  => 'authProfileSectionAddress',
                'group'  => self::GROUP_PROFILE,
                'fields' => ['address'],
            ],
            // The credential twins of 'email' and 'phone' above. Decision 2 of
            // docs/adr/001-profile-config-boundaries.md: a value that is a login
            // method is served by its own class, not by the contact-field
            // section with a flag on it — it is proven before it is stored, it
            // cannot be given up while it is the only way in, and it has states
            // ("a code is on its way") the plain field has no idea about.
            //
            // Each pair is mutually exclusive by availability: exactly one of
            // 'email' / 'login_email' can be available at a time, so the page
            // never shows a field twice. Being credentials, these sit under
            // "Sign-in and security" and never consult personal_fields.
            'login_email'     => [
                'class'  => 'authProfileSectionLoginEmail',
                'group'  => self::GROUP_AUTHORIZATION,
                'fields' => ['email'],
            ],
            'login_phone'     => [
                'class'  => 'authProfileSectionLoginPhone',
                'group'  => self::GROUP_AUTHORIZATION,
                'fields' => ['phone'],
            ],
            'password'        => [
                'class'  => 'authProfileSectionPassword',
                'group'  => self::GROUP_AUTHORIZATION,
                'fields' => ['password', 'password_confirm'],
            ],
            'linked_accounts' => [
                'class'  => 'authProfileSectionLinkedAccounts',
                'group'  => self::GROUP_AUTHORIZATION,
                'fields' => [],
            ],
            'delete_account'  => [
                'class'  => 'authProfileSectionDeleteAccount',
                'group'  => self::GROUP_AUTHORIZATION,
                'fields' => [],
            ],
        ];
    }

    /**
     * group_id => title, in display order.
     */
    public static function getGroupNames(): array
    {
        return [
            self::GROUP_PROFILE       => _w('Profile'),
            self::GROUP_AUTHORIZATION => _w('Sign-in and security'),
        ];
    }

    /**
     * Contact fields the section owns, before any domain filtering.
     *
     * @return string[]
     */
    public static function getFieldIds(string $section_id): array
    {
        $map = self::getMap();
        return $map[$section_id]['fields'] ?? [];
    }

    public static function getGroupId(string $section_id): ?string
    {
        $map = self::getMap();
        return $map[$section_id]['group'] ?? null;
    }

    /**
     * A single section by id, or null when it is unknown, not implemented yet
     * or not available for this domain and contact.
     */
    public static function getSection(string $section_id, ?waContact $contact = null): ?authProfileSection
    {
        $map = self::getMap();
        if (!isset($map[$section_id])) {
            return null;
        }

        $section = self::instantiate($map[$section_id]['class'], $contact);

        return ($section && $section->isAvailable()) ? $section : null;
    }

    /**
     * The available section that owns confirmable changes to a contact field
     * ('email', 'phone'), or null when this domain has none.
     *
     * A pending change (auth_profile_confirm) records a field and a value, not
     * a section id — the flow that confirms it knows nothing about sections.
     * This is the way back: it is looked up rather than mapped by hand, so
     * adding another confirmable section does not mean editing the flow too.
     */
    public static function getConfirmable(string $field_id, ?waContact $contact = null): ?authProfileSectionConfirmable
    {
        foreach (self::getSections($contact) as $section) {
            if ($section instanceof authProfileSectionConfirmable
                && $section->getConfirmableField() === $field_id
            ) {
                return $section;
            }
        }

        return null;
    }

    /**
     * Available sections for this contact, keyed by id, in map order.
     *
     * @return authProfileSection[]
     */
    public static function getSections(?waContact $contact = null): array
    {
        $result = [];
        foreach (self::getMap() as $section_id => $declaration) {
            $section = self::instantiate($declaration['class'], $contact);
            if ($section && $section->isAvailable()) {
                $result[$section_id] = $section;
            }
        }
        return $result;
    }

    /**
     * The page structure the template renders: groups in display order, each
     * with its available sections and their state already worked out. A group
     * with no available sections is dropped here rather than in Smarty — with
     * three sources of visibility, template-side checks are guaranteed to drift
     * apart between the default theme and custom ones.
     *
     * @return array group_id => ['id', 'name', 'sections' => section_id => [...]]
     */
    public static function getGroups(?waContact $contact = null): array
    {
        $names = self::getGroupNames();
        $groups = [];

        foreach (self::getSections($contact) as $section_id => $section) {
            $group_id = $section->getGroup();
            if (!isset($names[$group_id])) {
                continue;
            }
            if (!isset($groups[$group_id])) {
                $groups[$group_id] = [
                    'id'       => $group_id,
                    'name'     => $names[$group_id],
                    'sections' => [],
                ];
            }
            $groups[$group_id]['sections'][$section_id] = [
                'id'          => $section_id,
                'name'        => $section->getName(),
                'is_empty'    => $section->isEmpty(),
                'is_multiple' => $section->isMultiple(),
                'section'     => $section,
                'html'        => $section->render(authProfileSection::MODE_VIEW),
            ];
        }

        // Keep the declared group order regardless of section order.
        $ordered = [];
        foreach (array_keys($names) as $group_id) {
            if (isset($groups[$group_id])) {
                $ordered[$group_id] = $groups[$group_id];
            }
        }

        return $ordered;
    }

    // -------------------------------------------------------------------------

    /**
     * Sections land in the codebase stage by stage, so a class named in the map
     * may legitimately not exist yet — an unknown class is a gap, not an error.
     */
    private static function instantiate(string $class, ?waContact $contact = null): ?authProfileSection
    {
        if (!class_exists($class)) {
            return null;
        }

        $section = new $class($contact);

        return $section instanceof authProfileSection ? $section : null;
    }
}
