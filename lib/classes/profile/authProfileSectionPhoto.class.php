<?php

/**
 * The avatar. A section built out of a contact field that nevertheless does not
 * arrive through the form: the file is posted as photo_file, outside the
 * profile namespace, because a file input is not a waContactField value. The
 * form exists only so that the framework's hidden 'photo' input keeps carrying
 * the current value — waContactHiddenField, see authProfileFields::getAll().
 *
 * Being its own section is the whole point of the change this stage belongs to:
 * a new avatar used to mean re-posting the entire profile, and now it is one
 * request that touches one field.
 */
class authProfileSectionPhoto extends authProfileSectionFields
{
    protected static $id = 'photo';

    public function getName(): string
    {
        return _ws('Photo');
    }

    /**
     * '0' is what a removed photo is stored as (waMyProfileAction.class.php:81),
     * so it counts as absent here even though it is not an empty string.
     */
    public function isEmpty(): bool
    {
        $photo = (string)$this->contact->get('photo');

        return $photo === '' || $photo === '0';
    }

    /**
     * URL of the photo to show — the contact's own, or the framework's default
     * silhouette when there is none. Both come from waContact so that the
     * profile and the rest of the site draw the same avatar.
     */
    public function getPhotoUrl(int $size = 96): string
    {
        return $this->isEmpty()
            ? waContact::getPhotoUrl(null, null, $size, $size, 'person')
            : $this->contact->getPhoto($size, $size);
    }

    /**
     * Two actions, one section: upload (a file in photo_file) and remove
     * ($data['photo'] empty, which is what the hidden input posts when the
     * visitor asked for the photo to go).
     *
     * The parent's save() is not usable: it validates the section's fields
     * against the submitted values and writes them to the contact, and neither
     * step applies to a file that never was a form value.
     */
    public function save(array $data, ?int $index = null): bool
    {
        $this->errors = [];

        if (!$this->isAvailable()) {
            $this->addError('', _w('Changing the photo is not available.'));
            return false;
        }

        $file = waRequest::file('photo_file');

        if ($file->uploaded()) {
            try {
                if (!authProfileValues::storePhoto($this->contact, $file)) {
                    $this->addError('photo', _ws('Uploading failed.'));
                    return false;
                }
            } catch (Exception $e) {
                // A file that is not an image, or one waImage cannot read.
                // The message is the visitor's business; the reason is the log's.
                waLog::log('auth profile photo upload failed: '.$e->getMessage(), 'auth.log');
                $this->addError('photo', _ws('Uploading failed.'));
                return false;
            }

            $this->syncForm();

            return true;
        }

        // No file: this is the remove action. An empty POST that is neither is
        // not an error to shout about — the visitor pressed Save with nothing
        // chosen, and the photo they have is the photo they keep.
        if (!array_key_exists('photo', $data) || !empty($data['photo'])) {
            return true;
        }

        if ($this->isEmpty()) {
            return true;
        }

        $errors = $this->contact->save(['photo' => '0']);
        if ($errors) {
            foreach ($errors as $messages) {
                foreach ((array)$messages as $message) {
                    $this->addError('photo', $message);
                }
            }
            return false;
        }

        $this->syncForm();

        return true;
    }

    // -------------------------------------------------------------------------

    /**
     * Puts the stored value back into the form after a save.
     *
     * The section renders itself again in the same request (the save endpoint
     * answers with the partial), and waContactForm renders its hidden input
     * from $post when there is one — so without this the answer would still
     * carry the value the request arrived with. Same fix, and the same reason,
     * as waMyProfileAction.class.php:85-88.
     */
    private function syncForm(): void
    {
        $form = $this->getForm();
        if (!$form) {
            return;
        }

        $photo = $this->contact->get('photo');

        $form->values['photo'] = $photo;
        $form->post['photo']   = $photo;
    }
}
