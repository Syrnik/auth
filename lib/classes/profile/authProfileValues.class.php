<?php

/**
 * The three value preparations a profile save needs and cannot borrow: photo
 * cropping, phone normalization, address ext preservation. All three exist in
 * the framework, as protected methods of waMyProfileAction, and all three are
 * unreachable from anywhere else — they are steps inside the monolithic
 * saveFromPost() (wa-system/controller/waMyProfileAction.class.php:48), which
 * does photo, phones, validation, password and addresses in one pass. A section
 * saves one of those things and none of the others, so there is nothing to call.
 *
 * This is the same move authProfileFields makes for the field selection of
 * waMyProfileAction::getForm(): lift the logic out unchanged, name its origin,
 * and keep exactly one copy of it in this app. Each method below documents the
 * line it came from — when the framework changes, that reference is what makes
 * the difference findable.
 *
 * Nothing here validates. Validation belongs to the section's own waContactForm;
 * these methods only shape values the way the storage layer expects them.
 */
class authProfileValues
{
    /**
     * Stores an uploaded avatar on the contact and reports whether it did.
     *
     * Lifted from waMyProfileAction::saveFromPost() (waMyProfileAction.class.php:55-89).
     * waContact::setPhoto() (waContact.class.php:235) is the closer-looking API
     * and the wrong one: it copies the original as the display image, so a wide
     * photo stays wide and every avatar the site draws as a square is squashed.
     * The profile page has always cropped, and the crop is the reason this block
     * exists at all.
     *
     * The written value is a random number, not a path: waContactInfoStorage
     * keeps it in wa_contact.photo and the URL is composed from it plus the
     * contact id (waContact::getPhotoUrl()), which is also what makes the value
     * a cache buster — a new upload changes the URL.
     */
    public static function storePhoto(waContact $contact, waRequestFile $file): bool
    {
        if (!$file->uploaded()) {
            return false;
        }

        $image = $file->waImage();
        if (!$image) {
            return false;
        }

        $square = min($image->height, $image->width);
        $rand   = mt_rand();
        $path   = wa()->getDataPath(waContact::getPhotoDir($contact->getId()), true, 'contacts', false);

        // The whole directory goes: it holds the previous photo's files under
        // that photo's own random number, and nothing else ever reads them again.
        if (file_exists($path)) {
            waFiles::delete($path);
        }
        waFiles::create($path);

        $original = $path.$rand.'.original.jpg';
        waFiles::create($original);
        waImage::factory($file)->save($original, 90);

        $cropped = $path.$rand.'.jpg';
        waFiles::create($cropped);
        waImage::factory($file)->crop($square, $square)->save($cropped, 90);

        // Straight to the storage rather than through waContact::save(): 'photo'
        // is not a field the contact form owns, and this is where the framework
        // writes it too.
        waContactFields::getStorage('waContactInfoStorage')->set($contact, ['photo' => $rand]);

        return true;
    }

    /**
     * Normalizes submitted phone numbers in place, keeping the confirmation
     * status each number already had.
     *
     * Lifted from waMyProfileAction::preparePhonesBeforeSave()
     * (waMyProfileAction.class.php:164). The status is the point: it lives in
     * wa_contact_data.status, is set by whatever verified the number, and a
     * plain save() would write the list back with STATUS_UNKNOWN — silently
     * un-confirming a phone because the visitor edited the field next to it.
     * So the stored numbers are read first, normalized the same way, and matched
     * against the submitted ones by their normalized form.
     *
     * Empty values are dropped and the list is renumbered, because a hole in the
     * list is not a phone.
     *
     * @param array $phones submitted values, modified in place
     */
    public static function preparePhones(&$phones, int $contact_id): void
    {
        $phones = is_scalar($phones) ? (array)$phones : $phones;
        if (!is_array($phones)) {
            return;
        }

        $statuses = self::getPhoneStatuses($contact_id);

        foreach ($phones as &$phone) {
            if (is_array($phone)) {
                $value = isset($phone['value']) ? $phone['value'] : '';
                $ext   = isset($phone['ext']) ? $phone['ext'] : '';
            } else {
                $value = $phone;
                $ext   = '';
            }

            $number = waContactPhoneField::cleanPhoneNumber(self::transformPhone($value));

            $phone = [
                'value'  => $number,
                'status' => $statuses[$number] ?? waContactDataModel::STATUS_UNKNOWN,
                'ext'    => $ext,
            ];
        }
        unset($phone);

        foreach ($phones as $index => $phone) {
            if (!$phone['value']) {
                unset($phones[$index]);
            }
        }

        $phones = array_values($phones);
    }

    /**
     * Reshapes submitted addresses into the ['value' => data, 'ext' => type]
     * pairs the storage expects, carrying over the ext of the address that
     * already occupied each position.
     *
     * Lifted from waMyProfileAction::prepareAddressesBeforeSave()
     * (waMyProfileAction.class.php:219). The ext is the address type — billing,
     * shipping — and the form does not submit it, so without this step every
     * saved address loses the type it was filed under.
     *
     * @param array $addresses submitted values, modified in place
     */
    public static function prepareAddresses(&$addresses, waContact $contact): void
    {
        if (!is_array($addresses)) {
            return;
        }

        $stored = (array)$contact['address'];

        // A single address may arrive unwrapped, as the field's data alone.
        if (!isset($addresses[0])) {
            $addresses = [$addresses];
        }

        foreach ($addresses as $index => &$address) {
            $ext = null;

            // Already in storage shape (a value re-submitted as it was read, or
            // one a caller has attached the type to): keep that type and take
            // the address itself out of the wrapper.
            if (isset($address['data']) && (isset($address['ext']) || isset($address['value']))) {
                $ext     = isset($address['ext']) ? $address['ext'] : null;
                $address = $address['data'];
            }

            // Fall back to whatever occupied this position before. That is the
            // framework's only way of recovering the type
            // (waMyProfileAction.class.php:239), and it is right exactly while
            // the positions have not moved — which is why a caller that edits
            // one value of a list attaches the type itself instead of relying
            // on this; see authProfileSectionMultiField::mergeValue().
            if ($ext === null && isset($stored[$index]['ext'])) {
                $ext = $stored[$index]['ext'];
            }

            $address = [
                'value' => $address,
                'ext'   => $ext,
            ];
        }
        unset($address);
    }

    // -------------------------------------------------------------------------

    /**
     * Confirmation status of every phone this contact has, keyed by the
     * normalized number. The first row wins for a duplicate, matching the
     * framework's own loop.
     *
     * @return array normalized number => status
     */
    private static function getPhoneStatuses(int $contact_id): array
    {
        $rows = (new waContactDataModel())
            ->select('value, status')
            ->where("contact_id = :contact_id AND field = 'phone'", ['contact_id' => $contact_id])
            ->order('sort')
            ->query();

        $statuses = [];
        foreach ($rows as $row) {
            $number = self::transformPhone($row['value']);
            if (!isset($statuses[$number])) {
                $statuses[$number] = $row['status'];
            }
        }

        return $statuses;
    }

    /**
     * A national number in the domain's international form, or the number
     * unchanged when it is already international or not a phone number at all.
     *
     * waMyProfileAction::transformPhone() (waMyProfileAction.class.php:253)
     * returns a ['status', 'phone'] pair and every caller reads 'phone' alone,
     * so this returns the number itself.
     */
    private static function transformPhone($phone): string
    {
        $phone = (string)$phone;

        if (substr($phone, 0, 1) === '+') {
            return $phone;
        }

        if (!(new waPhoneNumberValidator())->isValid($phone)) {
            return $phone;
        }

        $result = waDomainAuthConfig::factory()->transformPhone($phone);

        return (string)($result['phone'] ?? $phone);
    }
}
