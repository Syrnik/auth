<?php

/**
 * waContactForm with hasField() for template-level checks and htmlAllExcept()
 * to render the form except a set of manually laid out fields.
 */
class authContactForm extends waContactForm
{
    /**
     * waContactForm::loadConfig() always does `new self(...)`, so calling
     * authContactForm::loadConfig() directly would return a plain waContactForm.
     * Build the form the usual way, then copy all public state into this subclass.
     *
     * @param waContactForm $form
     * @return self
     */
    public static function fromForm(waContactForm $form): self
    {
        $self = new self($form->fields, $form->options);
        $self->values = $form->values;
        $self->errors = $form->errors;
        $self->post   = $form->post;
        return $self;
    }

    public function hasField(string $field_id): bool
    {
        return isset($this->fields[$field_id]);
    }

    /**
     * HTML for the whole form except the given field ids, e.g. for fields
     * already laid out manually elsewhere in the template.
     *
     * @param string[] $excluded_field_ids
     * @param bool $with_errors whether to add class="error" and error text next to form fields
     * @param bool $placeholders
     * @return string HTML
     */
    public function htmlAllExcept(array $excluded_field_ids, bool $with_errors = true, bool $placeholders = false): string
    {
        $this->validateFields();
        $this->treatNamesFieldValidation();

        $class_field = $this->opt('css_class_field', wa()->getEnv() == 'frontend' ? 'wa-field' : 'field');
        $class_value = $this->opt('css_class_value', wa()->getEnv() == 'frontend' ? 'wa-value' : 'value');
        $class_name = $this->opt('css_class_name', wa()->getEnv() == 'frontend' ? 'wa-name' : 'name');
        $result = '';
        foreach ($this->fields() as $fid => $f) {
            /** @var waContactField $f */

            if (in_array($fid, $excluded_field_ids, true)) {
                continue;
            }

            // Upload contact photo
            if ($fid === 'photo') {
                $result .= '<div class="'.$class_field.' '.($class_field.'-'.$f->getId()).'"><div class="'.$class_name.'">'.
                    _ws('Photo').'</div><div class="'.$class_value.'">';

                // Current photo of a person
                if (wa()->getUser()->get($fid)) {
                    $result .= "\n".'<img src="'.wa()->getUser()->getPhoto().'">';
                }

                // Empty photo
                $result .= "\n".'<img src="'.waContact::getPhotoUrl(null, null, null, null, 'person').'">';

                $result .= "\n".'<p><input type="file" name="'.$fid.'_file"></p>';
                $result .= $this->html($fid, true);
                $result .= "\n</div></div>";
                continue;
            }

            // Fake password confirmation field
            if ($fid === 'password_confirm') {
                continue;
            }

            // Hidden field
            if ($f->isHidden()) {
                $result .= $this->html($fid, true);
                continue;
            }

            $field_class = $class_field.'-'.$f->getId();
            if (strpos($fid, '.') !== false) {
                $field_class .= ' '.$class_field.'-'.str_replace('.', '-', $fid);
            }
            if ($f->isRequired()) {
                $field_class .= ' '.(wa()->getEnv() == 'frontend' ? 'wa-required' : 'required');
            }
            $result .= '<div class="'.$class_field.' '.$field_class.'"><div class="'.$class_name.'">'.
                $f->getName(null, true).'</div><div class="'.$class_value.'">';
            $result .= "\n".$this->html($fid, $with_errors, $placeholders);
            $result .= "\n</div></div>";
        }

        return $result;
    }
}
