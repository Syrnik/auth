<?php

/**
 * Phone as a plain contact field, the counterpart of authProfileSectionEmail:
 * several numbers, each of an optional type, saved on submit. Available only
 * while phone is NOT a login method — see authProfileSectionLoginPhone and
 * decision 2 of docs/adr/001-profile-config-boundaries.md.
 *
 * Decision 1 of docs/adr/005-value-confirmation.md — see authProfileSectionEmail's
 * docblock, which applies here unchanged with 'phone' in place of 'email'.
 */
class authProfileSectionPhone extends authProfileSectionMultiField implements authProfileSectionConfirmable
{
    use authProfileConfirmableValueTrait;
    use authProfileConfirmPhoneTrait;

    protected static $id = 'phone';

    public function getName(): string
    {
        return _ws('Phone');
    }

    public function isAvailable(): bool
    {
        return parent::isAvailable() && !authHelper::isLoginField('phone');
    }

    /**
     * Always null — see authProfileSectionEmail::getConfirmationUrl().
     */
    public function getConfirmationUrl(array $data, ?int $index = null): ?string
    {
        return null;
    }

    public function applyConfirmedValue(string $value): bool
    {
        $this->errors = [];

        if (!$this->isAvailable()) {
            $this->addError('', _w('This change can no longer be applied.'));
            return false;
        }

        if (!$this->stampConfirmed($this->normalize($value))) {
            $this->addError('', _w('This number is no longer on your profile.'));
            return false;
        }

        return true;
    }

    protected function getTemplateVars(string $mode, ?int $index = null): array
    {
        return parent::getTemplateVars($mode, $index) + $this->confirmableTemplateVars();
    }
}
