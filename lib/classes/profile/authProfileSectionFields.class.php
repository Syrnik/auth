<?php

/**
 * Base for sections made of contact fields (photo, name, email, phone,
 * address). The field list comes from authProfileSectionRegistry::getFieldIds()
 * and is filtered by what the domain enables, so a section is available exactly
 * when at least one of its fields is.
 *
 * The form is built here, per section, and never shared with the profile-wide
 * one: waContactForm::validateFields() (waContactForm.class.php:495) validates
 * every field it holds, reading values through post($field_id) — which yields
 * null for fields that were not submitted. A form limited to the section's own
 * fields therefore validates exactly what arrived, with no framework fighting.
 */
abstract class authProfileSectionFields extends authProfileSectionBase
{
    /**
     * waContactForm namespace, kept equal to waMyProfileAction::$namespace so
     * that a section posts the same profile[field] shape the framework expects.
     */
    const FORM_NAMESPACE = 'profile';

    /** @var waContactForm[] built forms, by index key */
    private $forms = [];

    public function isAvailable(): bool
    {
        return (bool)$this->getEnabledFields();
    }

    /**
     * Values are read through the field objects rather than waContact::get(),
     * because the latter invents data when there is none: an empty firstname
     * comes back as the local part of the contact's email (waContact.class.php,
     * "Contact without name derive firstname from email or phone"). A section
     * asking whether the user has filled anything in must see the stored value,
     * or every nameless contact reports a full name section.
     */
    public function isEmpty(): bool
    {
        foreach ($this->getEnabledFields() as $field) {
            if (!$this->isValueEmpty($field->get($this->contact))) {
                return false;
            }
        }
        return true;
    }

    /**
     * What view mode shows: the section's enabled fields with their stored
     * values, field_id => ['name' => label, 'value' => value].
     *
     * Values come from the field objects for the reason given on isEmpty(), and
     * are handed over unformatted — a multi-value field yields a list, and how
     * to present it is the section's template's business, not this class's.
     *
     * @return array
     */
    public function getValues(): array
    {
        $result = [];
        foreach ($this->getEnabledFields() as $field_id => $field) {
            $result[$field_id] = [
                // Unescaped: the template escapes, and doing it twice shows
                // entities to the user.
                'name'  => $field->getName(),
                'value' => $field->get($this->contact),
            ];
        }
        return $result;
    }

    public function getForm(?int $index = null): ?waContactForm
    {
        $fields = $this->getEnabledFields();
        if (!$fields) {
            return null;
        }

        $key = $index === null ? '' : (string)$index;
        if (!isset($this->forms[$key])) {
            $form = new waContactForm($fields, ['namespace' => static::FORM_NAMESPACE]);
            $form->setValue($this->contact);
            $this->forms[$key] = $form;
        }

        return $this->forms[$key];
    }

    /**
     * Validates the submitted slice against the section's own form and writes
     * it to the contact. Fields with their own storage quirks (photo upload,
     * phone normalization, address ext preservation) override this.
     *
     * Logging of profile changes stays with the caller: the save endpoint knows
     * the request context and owns the log action name.
     */
    public function save(array $data, ?int $index = null): bool
    {
        $this->errors = [];

        $form = $this->getForm($index);
        if (!$form) {
            return false;
        }

        $data = $this->prepareData($data, $index);
        if ($data === null) {
            // prepareData() recorded why; a section that refuses to shape its
            // input has nothing to validate.
            return false;
        }

        $saved_post = $form->post;
        $form->post = $data;
        $valid = $form->isValid($this->contact);
        if (!$valid) {
            $this->errors = $form->errors();
            $form->post = $saved_post;
            return false;
        }
        $form->post = $saved_post;

        $this->validateSection($data, $form);
        if ($this->errors) {
            return false;
        }

        foreach ($this->prepareForStorage($data) as $field_id => $value) {
            $this->contact->set($field_id, $value);
        }

        $errors = $this->contact->save();
        if ($errors) {
            foreach ($errors as $field_id => $messages) {
                foreach ((array)$messages as $message) {
                    $this->addError($field_id, $message);
                    $form->errors($field_id, $message);
                }
            }
            return false;
        }

        $form->setValue($this->contact);

        return true;
    }

    /**
     * Puts the visitor's own values back into the form, so that a save that
     * failed in an earlier request (the no-JS redirect) is re-rendered as the
     * failed request left it rather than as the stored contact.
     *
     * waContactForm::$post is the property the form renders from, and errors
     * are pushed through errors() so that each field carries its own message.
     * Empty messages are skipped: errors() treats an empty string as "give me
     * the errors of this field" and would run a full validation instead.
     */
    public function restoreFailedSave(array $data, array $errors, ?int $index = null): void
    {
        parent::restoreFailedSave($data, $errors, $index);

        $form = $this->getForm($index);
        if (!$form) {
            return;
        }

        $form->post = array_intersect_key($data, $this->getEnabledFields());

        foreach ($errors as $field_id => $messages) {
            foreach ((array)$messages as $message) {
                if (strlen((string)$message)) {
                    $form->errors((string)$field_id, $message);
                }
            }
        }
    }

    // -------------------------------------------------------------------------

    /**
     * Rules that hold for the section as a whole rather than for any one field,
     * checked once the individual fields have passed. Record failures with
     * addError() and on $form, so that both the section state and the rendered
     * form know about them. No-op by default.
     */
    protected function validateSection(array $data, waContactForm $form): void
    {
    }

    /**
     * The submitted slice turned into the field_id => value map this save is
     * actually about, or null to refuse the save outright (record why with
     * addError() first).
     *
     * Runs before validation, which is where the framework normalizes phone
     * numbers too (waMyProfileAction::saveFromPost(), waMyProfileAction.class.php:91)
     * — a number has to be in its final form before it is checked, or the check
     * is about a value that will never be stored.
     *
     * By default: the section's own fields and nothing else, whatever else the
     * request carried. A multi-value section merges the submitted value into the
     * stored list here, which is why $index is passed through.
     */
    protected function prepareData(array $data, ?int $index = null): ?array
    {
        return array_intersect_key($data, $this->getEnabledFields());
    }

    /**
     * Last shaping before the values are written to the contact, after they have
     * passed validation.
     *
     * The counterpart of prepareData(), and separate from it because the
     * framework's own order is not one step but two: addresses are reshaped
     * after validation (waMyProfileAction.class.php:125), since the storage
     * shape ['value' => ..., 'ext' => ...] is not the shape the validator reads.
     * Doing it earlier would validate the wrapper instead of the address.
     */
    protected function prepareForStorage(array $data): array
    {
        return $data;
    }

    /**
     * This section's fields that the domain actually enables.
     *
     * @return array field_id => waContactField
     */
    protected function getEnabledFields(): array
    {
        return authProfileFields::filter($this->getFieldIds());
    }

    /**
     * Field ids this section owns, per the registry map.
     *
     * @return string[]
     */
    protected function getFieldIds(): array
    {
        return authProfileSectionRegistry::getFieldIds($this->getId());
    }

    /**
     * A contact value counts as absent when it is null, an empty string or an
     * empty list — '0' and 0 do not, they are values a user could have entered.
     */
    protected function isValueEmpty($value): bool
    {
        if (is_array($value)) {
            return !$value;
        }
        return $value === null || $value === '';
    }
}
