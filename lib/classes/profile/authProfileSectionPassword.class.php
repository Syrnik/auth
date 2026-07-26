<?php

/**
 * The password.
 *
 * Availability is decision 1 of docs/adr/001-profile-config-boundaries.md, and
 * the record spells out why it is not personal_fields: the site map and
 * login_methods are two knobs on one thing, and both settings of the pair are
 * wrong. A site map with 'password' ticked while no method signs anyone in by
 * password shows a section for a credential that does not exist; a site map
 * with it unticked while password login is on takes away a visitor's control of
 * their own credentials, in the app that exists for exactly that. So the answer
 * comes from login_methods and nothing else.
 *
 * The old password is asked for even though the visitor is signed in — a
 * session left open on a shared machine must not be enough to take the account
 * over, and this is the one field where that matters.
 */
class authProfileSectionPassword extends authProfileSectionFields
{
    protected static $id = 'password';

    public function getName(): string
    {
        return _ws('Password');
    }

    /**
     * The domain signs someone in by password. Whether THIS contact has a usable
     * one is a different question, and isEmpty() answers it.
     */
    public function isAvailable(): bool
    {
        return authHelper::hasPasswordLogin();
    }

    /**
     * A contact created from OAuth data carries a deliberately unusable hash
     * (authContactResolver::setUnusablePassword()), which every `password != ''`
     * check reads as "has a password". For this section that is the wrong
     * answer twice over: the visitor has no password to show as set, and no old
     * password to confirm with — so the section is empty and asks for none.
     */
    public function isEmpty(): bool
    {
        return !authContactResolver::hasUsablePassword($this->contact);
    }

    /**
     * Whether changing the password requires proving the current one. Only when
     * there is one that can be proven; a contact signed up through OAuth is
     * setting a password for the first time.
     */
    public function needsCurrentPassword(): bool
    {
        return !$this->isEmpty();
    }

    /**
     * Credentials ignore the site map — see the class comment. The two fields
     * come straight from the framework rather than through
     * authProfileFields::filter(), which is personal_fields' filter.
     */
    protected function getEnabledFields(): array
    {
        return [
            'password'         => new waContactPasswordField('password', _ws('Password')),
            'password_confirm' => new waContactPasswordField('password_confirm', _ws('Confirm password')),
        ];
    }

    /**
     * Rules the two password fields cannot check on their own, lifted from
     * waMyProfileAction::saveFromPost() (waMyProfileAction.class.php:106-115)
     * and joined by the current-password check this section adds.
     *
     * The framework's version silently drops the password when either field is
     * empty, because there it is one field among a whole profile being saved.
     * Here the section IS the password, so an empty submit is a failed change
     * and says so.
     */
    protected function validateSection(array $data, waContactForm $form): void
    {
        $password = (string)($data['password'] ?? '');
        $confirm  = (string)($data['password_confirm'] ?? '');

        if ($this->needsCurrentPassword()) {
            $current = (string)waRequest::post('current_password', '', 'string');
            if ($current === '') {
                $this->fail($form, 'current_password', _w('Enter your current password.'));
                return;
            }
            if (!waContact::verifyPasswordHash($current, (string)$this->contact->get('password'))) {
                $this->fail($form, 'current_password', _w('The current password is not correct.'));
                return;
            }
        }

        if ($password === '') {
            $this->fail($form, 'password', _ws('Password is required.'));
            return;
        }

        if (strlen($password) > waAuth::PASSWORD_MAX_LENGTH) {
            $this->fail($form, 'password', _ws('Specified password is too long.'));
            return;
        }

        if ($password !== $confirm) {
            $this->fail($form, 'password', _ws('Passwords do not match'));
        }
    }

    /**
     * password_confirm is not a contact field and must never reach the contact:
     * waContact::set() would file it as an unknown field. The framework drops it
     * at the same point, waMyProfileAction.class.php:115.
     */
    protected function prepareForStorage(array $data): array
    {
        unset($data['password_confirm']);

        return $data;
    }

    // -------------------------------------------------------------------------

    /**
     * The message goes on the section and on the form, so that the section
     * knows it failed and the rendered field carries its own error.
     */
    private function fail(waContactForm $form, string $field_id, string $message): void
    {
        $this->addError($field_id, $message);

        // current_password is this section's own input, not a form field —
        // waContactForm would have nowhere to put it.
        if ($field_id !== 'current_password') {
            $form->errors($field_id, $message);
        }
    }
}
