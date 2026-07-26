<?php

/**
 * Addresses: several of them, each of a type (billing, shipping), each edited
 * on its own.
 *
 * This is the section the third state of the contract was written for — see
 * authProfileSection. "The domain allows addresses" and "this visitor has
 * entered one" are different answers, and only the first is about the config:
 * an available but empty address section is a heading and an "Add", not an
 * absence.
 *
 * Everything about editing one value of a list is in authProfileSectionMultiField.
 * What is left here is the one thing addresses do differently: their ext must
 * survive a save.
 */
class authProfileSectionAddress extends authProfileSectionMultiField
{
    protected static $id = 'address';

    public function getName(): string
    {
        return _ws('Address');
    }

    /**
     * Reshapes the validated addresses the way the storage expects and carries
     * over the type of the address already in each position — see
     * authProfileValues::prepareAddresses(), and waMyProfileAction.class.php:125
     * for why this happens after validation rather than before it.
     *
     * Without it every save files the address under no type at all, and an
     * address the shop app filed as the shipping one silently stops being it.
     */
    protected function prepareForStorage(array $data): array
    {
        $field_id = $this->getField() ? $this->getField()->getId() : '';
        if ($field_id === '' || !isset($data[$field_id])) {
            return $data;
        }

        authProfileValues::prepareAddresses($data[$field_id], $this->contact);

        return $data;
    }
}
