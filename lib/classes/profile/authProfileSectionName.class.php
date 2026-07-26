<?php

/**
 * The plain end of the section contract: three contact fields, one small form,
 * nothing that is not already in waContactForm. Everything except the "at least
 * one name" rule below is inherited from authProfileSectionFields.
 *
 * Its counterpart is authProfileSectionLinkedAccounts, which has no form at all
 * — between the two, the contract is exercised from both ends.
 */
class authProfileSectionName extends authProfileSectionFields
{
    protected static $id = 'name';

    public function getName(): string
    {
        return _ws('Name');
    }

    /**
     * The whole name may not be blanked out — the framework says so through the
     * system 'name' field, which is declared required and answers with
     * "At least one of these fields must be filled in."
     *
     * That validator cannot do the work here. It lives on the composite 'name'
     * field, which is not part of this section (the section owns the three
     * subfields), and putting it in the form would make things worse rather
     * than better: waContactForm::validateFields() calls
     * waContactNameField::set() with the value from post('name') — absent from
     * any section POST — and that setter blanks firstname, middlename and
     * lastname on the contact before validating. So the rule is applied here,
     * against the state the save would actually produce.
     *
     * waContactForm::treatNamesFieldValidation() (waContactForm.class.php:532)
     * additionally marks all three fields, since the failure belongs to the
     * group and not to any one of them. The error is recorded on the first
     * enabled field, and the section's edit template marks the whole block —
     * same effect, without repeating one sentence three times.
     */
    protected function validateSection(array $data, waContactForm $form): void
    {
        $fields = $this->getEnabledFields();

        foreach ($fields as $field_id => $field) {
            // What this field will hold after the save: the submitted value
            // where there is one, the stored value where the POST was partial.
            $value = array_key_exists($field_id, $data) ? $data[$field_id] : $field->get($this->contact);
            if (trim((string)$value) !== '') {
                return;
            }
        }

        $field_id = (string)key($fields);
        $message  = _ws('At least one of these fields must be filled in.');

        $this->addError($field_id, $message);
        $form->errors($field_id, $message);
    }
}
