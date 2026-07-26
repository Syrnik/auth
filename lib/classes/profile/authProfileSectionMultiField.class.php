<?php

/**
 * Base for sections that hold a list of values addressed by $index: emails,
 * phones, addresses. One contact field each, several values in it, one edited
 * at a time.
 *
 * Editing one value rather than the whole list is the reason this class exists.
 * waContactForm renders a multi-value field as all of its values at once, and
 * the section contract addresses one (authProfileSection::getForm()) — so the
 * markup for a single value is taken from the field object directly, through
 * waContactField::getHtmlOneWithErrors() with a 'multi_index'
 * (waContactField.class.php:768, and :752 for the input names it produces:
 * profile[phone][2] for a plain field, profile[address][2][street] for a
 * composite one). That is the same call waContactField::getHTML() makes for
 * each value when it draws them all, so a value edited on its own posts under
 * exactly the name it would have posted under as part of the whole list.
 *
 * Writing is still the whole list: storages replace a field's values wholesale,
 * so the submitted value is merged into the stored list first (prepareData())
 * and the merged list is what gets validated and written.
 */
abstract class authProfileSectionMultiField extends authProfileSectionFields
{
    /**
     * Stand-in type used only to pin down the input names of a value that has
     * no type yet. See fieldHtml(); it is stripped again in mergeValue() and
     * never reaches storage.
     */
    const EXT_NONE = '-';

    public function isMultiple(): bool
    {
        return true;
    }

    /**
     * The stored values, in order, each ['value' => ..., 'ext' => ..., 'status' => ...].
     *
     * Read through the field object rather than waContact::get() for the reason
     * given on authProfileSectionFields::isEmpty(), and unformatted, so the
     * template decides how a value looks.
     */
    public function getList(): array
    {
        $field = $this->getField();
        if (!$field) {
            return [];
        }

        return array_values((array)$field->get($this->contact));
    }

    /**
     * One stored value, or null when $index addresses a value that is not there
     * yet — which is what "Add" is: an index one past the end.
     */
    public function getValue(?int $index): ?array
    {
        $list = $this->getList();
        $index = $index ?? 0;

        return isset($list[$index]) ? (array)$list[$index] : null;
    }

    /**
     * Index the "Add" link should point at: the first free position.
     */
    public function getNextIndex(): int
    {
        return count($this->getList());
    }

    /**
     * Value types this field offers, ext value => label, or an empty array when
     * it has none. waContactField::getInfo() (waContactField.class.php:105) is
     * where the labels are translated, so this reads them from there rather
     * than from the raw options.
     *
     * The type is optional by design: the framework renders it as a hidden input
     * carrying whatever was already there (waContactField::getHtmlOne(),
     * waContactField.class.php:801, with a "!!! add a proper <select>?" note),
     * so a section that wants it editable renders the control itself.
     */
    public function getExtOptions(): array
    {
        $field = $this->getField();
        if (!$field || !$field->hasExt()) {
            return [];
        }

        return (array)($field->getInfo()['ext'] ?? []);
    }

    /**
     * Markup for the inputs of one value — what an edit partial puts inside its
     * form. The value shown is the submitted one when this render follows a
     * failed save, and the stored one otherwise, same rule waContactForm uses.
     */
    public function fieldHtml(?int $index = null): string
    {
        $field = $this->getField();
        if (!$field) {
            return '';
        }

        $index = $index ?? 0;
        $value = $this->getSubmittedValue($index) ?? $this->getValue($index) ?? '';

        $params = [
            'namespace'   => static::FORM_NAMESPACE,
            'id'          => $field->getId(),
            'multi_index' => $index,
            'value'       => $this->withExtPlaceholder($value),
        ];

        return $field->getHtmlOneWithErrors($this->getValueErrors($index), $params);
    }

    /**
     * The name the type control has to post under, or '' when the field has no
     * type at all. Composed here rather than in Smarty for the same reason the
     * section's URLs are: the shape is the framework's, not the theme's.
     */
    public function extInputName(?int $index = null): string
    {
        $field = $this->getField();
        if (!$field || !$field->hasExt()) {
            return '';
        }

        return static::FORM_NAMESPACE.'['.$field->getId().']['.($index ?? 0).'][ext]';
    }

    // -------------------------------------------------------------------------

    /**
     * Gives a typeless value a stand-in type, so that the inputs the field
     * renders always carry the same names.
     *
     * waContactField::getHtmlOne() (waContactField.class.php:782) decides the
     * input names from the value it is given: with a type it posts
     * profile[phone][2][value] and profile[phone][2][ext], and without one just
     * profile[phone][2]. Both are correct for the framework's own "draw every
     * value" rendering, and neither can be relied on by a partial that adds a
     * type control of its own — a select named profile[phone][2][ext] beside a
     * plain profile[phone][2] makes PHP throw the value away and keep the array.
     *
     * So one shape is chosen and kept. Composite fields are left alone: they
     * name their subfields themselves and never render a type input.
     *
     * The stand-in is stripped again in mergeValue(), which means a theme that
     * drops the select cannot store it either — the type of such a value simply
     * stays what it was.
     *
     * @param mixed $value
     * @return mixed
     */
    private function withExtPlaceholder($value)
    {
        $field = $this->getField();
        if (!$field || !$field->hasExt() || $field instanceof waContactCompositeField) {
            return $value;
        }

        if (!is_array($value)) {
            $value = ['value' => $value];
        }

        if (empty($value['ext'])) {
            $value['ext'] = self::EXT_NONE;
        }

        return $value;
    }

    /**
     * A value may be dropped unless something says otherwise; the login
     * sections override this, since giving up the only way in is not an edit.
     */
    public function getRemovalLock(?int $index = null): ?string
    {
        return null;
    }

    /**
     * Where to open one value for editing, and where to add a new one.
     *
     * A view partial lists several values and needs an address per value, which
     * the single $edit_url of the common template vars cannot give it. Composing
     * these in Smarty instead would mean every theme repeating how an index
     * travels in a URL — see authProfileSectionBase::getModeUrl().
     */
    public function getValueEditUrl(?int $index): string
    {
        return $this->getModeUrl(self::MODE_EDIT, $index);
    }

    public function getAddUrl(): string
    {
        return $this->getModeUrl(self::MODE_EDIT, $this->getNextIndex());
    }

    // -------------------------------------------------------------------------

    /**
     * Merges the submitted value into the stored list, so that validation and
     * the write see the field as it will be, not as one value out of context.
     *
     * An empty submitted value means removal: the entry goes and the list is
     * renumbered, unless getRemovalLock() says this one has to stay.
     */
    protected function prepareData(array $data, ?int $index = null): ?array
    {
        $field = $this->getField();
        if (!$field) {
            return [];
        }

        $field_id = $field->getId();
        $index    = $index ?? 0;
        $list     = $this->getList();
        $value    = $this->extractSubmitted($data, $index);

        if ($this->isSubmittedValueEmpty($value)) {
            $lock = $this->getRemovalLock($index);
            if ($lock !== null) {
                $this->addError('', $lock);
                return null;
            }
            unset($list[$index]);
            $list = array_values($list);
        } else {
            // Assigning past the end appends, which is exactly what the "Add"
            // link's index means. ksort keeps a sparse write in order before the
            // list is renumbered.
            $list[$index] = $this->mergeValue($value, isset($list[$index]) ? (array)$list[$index] : null);
            ksort($list);
            $list = array_values($list);
        }

        $prepared = [$field_id => $list];
        $this->prepareList($prepared[$field_id]);

        return $prepared;
    }

    /**
     * Normalization the whole list needs before it is validated — phone numbers
     * into international form, and so on. No-op by default.
     *
     * @param array $list modified in place
     */
    protected function prepareList(&$list): void
    {
    }

    /**
     * The value to store at a position, given what was submitted for it and
     * what was there before.
     *
     * Carries the type over when the request does not name one. The framework
     * recovers a value's ext by looking up the same position in the contact
     * afterwards (waMyProfileAction::prepareAddressesBeforeSave()), which holds
     * only while positions do not move — and here they do: removing the first
     * of three values renumbers the other two, and every one of them would come
     * back filed under its neighbour's type. Attaching the type during the merge
     * asks the question while the answer is still known.
     *
     * @param mixed $submitted
     * @return mixed
     */
    protected function mergeValue($submitted, ?array $stored)
    {
        $field = $this->getField();
        if (!$field || !$field->hasExt()) {
            return $submitted;
        }

        $stored_ext = ($stored !== null && isset($stored['ext'])) ? (string)$stored['ext'] : '';

        // A composite posts its subfields as a flat map, and the type is not one
        // of them — it wraps the map instead. This is the ['data' => ...,
        // 'ext' => ...] shape that waContactCompositeField::getHtmlOne()
        // (waContactCompositeField.class.php:333) and
        // authProfileValues::prepareAddresses() both read, so the type has to
        // come out of the map and into the wrapper here, or it would be stored
        // as if it were part of the address.
        if ($field instanceof waContactCompositeField) {
            $data = (is_array($submitted) && array_key_exists('data', $submitted))
                ? (array)$submitted['data']
                : (array)$submitted;

            $ext = $this->resolveExt($submitted, $data, $stored_ext);
            unset($data['ext']);

            return ['data' => $data, 'ext' => $ext];
        }

        if (!is_array($submitted)) {
            $submitted = ['value' => $submitted];
        }

        $submitted['ext'] = $this->resolveExt($submitted, $submitted, $stored_ext);

        return $submitted;
    }

    /**
     * The type to store: the one the request names, the one that was there
     * before when it names none, and never the stand-in fieldHtml() adds to pin
     * the input names down (see withExtPlaceholder()).
     *
     * @param mixed $submitted
     */
    private function resolveExt($submitted, array $data, string $stored_ext): string
    {
        foreach ([$submitted, $data] as $source) {
            if (is_array($source) && array_key_exists('ext', $source)) {
                $ext = (string)$source['ext'];
                if ($ext === self::EXT_NONE) {
                    // The control that would have replaced the stand-in is not
                    // on this form, so the type is simply not being changed.
                    return $stored_ext;
                }
                return $ext;
            }
        }

        return $stored_ext;
    }

    /**
     * The submitted value for this index, in whatever shape the field posts it:
     * a bare string, or ['value' => ..., 'ext' => ...], or a composite's map of
     * subfields.
     *
     * @return mixed null when the request carried nothing for this index
     */
    protected function extractSubmitted(array $data, int $index)
    {
        $field = $this->getField();
        if (!$field || !array_key_exists($field->getId(), $data)) {
            return null;
        }

        $submitted = $data[$field->getId()];
        if (!is_array($submitted)) {
            // A single value posted without its index. Not the shape this
            // section renders, but a hand-written form is allowed to be simple.
            return $submitted;
        }

        if (array_key_exists($index, $submitted)) {
            return $submitted[$index];
        }

        // Composite fields post their subfields directly when the form holds
        // one value only, with no index level in between.
        return array_key_exists(0, $submitted) ? null : $submitted;
    }

    /**
     * Whether a submitted value means "remove this entry". A composite is empty
     * when every one of its parts is, since an address with only a country
     * picked is the form's default, not something the visitor entered.
     */
    protected function isSubmittedValueEmpty($value): bool
    {
        if ($value === null) {
            return true;
        }

        if (!is_array($value)) {
            return trim((string)$value) === '';
        }

        foreach ($value as $key => $part) {
            if ($key === 'ext' || $key === 'status') {
                // The type of a value that is not there is not a value.
                continue;
            }
            if (!$this->isSubmittedValueEmpty($part)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The value the visitor submitted for this index, when this render is
     * redrawing a save that failed; null otherwise.
     *
     * @return mixed
     */
    protected function getSubmittedValue(int $index)
    {
        $form = $this->getForm($index);
        if (!$form || !is_array($form->post)) {
            return null;
        }

        return $this->extractSubmitted($form->post, $index);
    }

    /**
     * Errors belonging to the value at this index. waContactForm keys a
     * multi-value field's errors by index (waContactField::getHTML(),
     * waContactField.class.php:850), and everything not keyed that way belongs
     * to the field as a whole.
     */
    protected function getValueErrors(int $index): array
    {
        $field = $this->getField();
        if (!$field || empty($this->errors[$field->getId()])) {
            return [];
        }

        $errors = (array)$this->errors[$field->getId()];

        if (isset($errors[$index])) {
            return (array)$errors[$index];
        }

        // Not indexed: the message is about the field, and with one value on
        // screen it belongs to that value.
        return array_filter($errors, 'is_scalar');
    }

    /**
     * The one enabled contact field this section is made of, or null when the
     * domain does not offer it.
     */
    protected function getField(): ?waContactField
    {
        $fields = $this->getEnabledFields();

        return $fields ? reset($fields) : null;
    }

    protected function getTemplateVars(string $mode, ?int $index = null): array
    {
        return parent::getTemplateVars($mode, $index) + [
            'values'        => $this->getList(),
            'value'         => $this->getValue($index),
            'next_index'    => $this->getNextIndex(),
            'ext_options'   => $this->getExtOptions(),
            'field_html'    => $mode === self::MODE_EDIT ? $this->fieldHtml($index) : '',
            'removal_lock'  => $this->getRemovalLock($index),
        ];
    }
}
