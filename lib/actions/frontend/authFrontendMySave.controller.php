<?php

/**
 * The one place a profile section is written from: POST my/save/<section>/.
 *
 * A route per section rather than one POST for the whole page, because a section
 * is its own small form validated on its own — see authProfileSection::getForm()
 * for why a slice of the page-wide form cannot be validated at all — so the
 * request has to name the section it is about.
 *
 * What comes back is the section's rendered partial: view mode after a save,
 * edit mode with the errors after a failure. The client swaps the contents of
 * the section container and nothing more; the markup exists once, in the theme,
 * and is never duplicated in JS.
 *
 * Authentication and CSRF are the framework's own, through 'secure' => true on
 * the route together with 'csrf' => true in app.php (waDispatch::dispatch()):
 * an unauthenticated POST is answered with the login form and never reaches this
 * class, and a POST without a matching _csrf is a 403. Note that the frontend
 * check reads the _csrf *field* and not the X-CSRF-Token header, so an XHR has
 * to carry the token in the body — which it does anyway, the partials render it.
 */
class authFrontendMySaveController extends waJsonController
{
    /**
     * Session key holding the state of a save that failed without JS, read by
     * authFrontendMyAction on the page the visitor is redirected to.
     */
    const FAILED_SAVE = 'auth/my/failed_save';

    public function execute()
    {
        if (waRequest::method() !== 'post') {
            throw new waException(_w('This address only accepts a form submission.'), 405);
        }

        $section = $this->getSection();
        $index   = $this->getIndex($section);
        $data    = $this->getData($section, $index);

        // Credentials do not travel this route. Changing an email or a phone
        // that is a login method, or the password, means proving the new value
        // first — decision 2 of docs/adr/001-profile-config-boundaries.md — so
        // such a section answers with the URL of its own flow and is not saved
        // here. Every other section, and the same section for a value that is
        // not a login, returns null and is saved normally.
        if ($section instanceof authProfileSectionConfirmable) {
            $url = $section->getConfirmationUrl($data, $index);
            if ($url !== null) {
                $this->respondRedirect($section, $index, $url);
                return;
            }
        }

        $before = $this->readOwnFields($section);

        if (!$section->save($data, $index)) {
            $this->respondFailure($section, $index, $data);
            return;
        }

        $this->logSave($section, $before);
        $this->respondSuccess($section, $index);
    }

    /**
     * The framework's fail branch answers with status and errors and drops the
     * data, but a failed save has a body worth sending: the section's edit
     * partial, which the visitor has to see. This is that envelope plus 'data',
     * so a client reading only status/errors still reads them correctly.
     */
    public function display()
    {
        if (waRequest::isXMLHttpRequest()) {
            $this->getResponse()->addHeader('Content-Type', 'application/json');
        }
        $this->getResponse()->sendHeaders();

        $result = [
            'status' => $this->errors ? 'fail' : 'ok',
            'data'   => $this->response,
        ];
        if ($this->errors) {
            $result['errors'] = $this->errors;
        }

        echo waUtils::jsonEncode($result);
    }

    // -------------------------------------------------------------------------

    /**
     * An unknown section id, a section not implemented yet and a section this
     * domain does not offer are one and the same thing seen from outside: there
     * is nothing here to post to. 404, deliberately — a page drawn before the
     * config changed must not look like a bug in the app.
     */
    private function getSection(): authProfileSection
    {
        $section_id = waRequest::param('section', '', 'string');

        $section = authProfileSectionRegistry::getSection($section_id, $this->getContact());
        if (!$section) {
            throw new waException(_w('Profile section not found.'), 404);
        }

        return $section;
    }

    /**
     * Which value of a multi-value section the request is about, or null.
     *
     * An index for a single-value section is rejected rather than ignored: the
     * contract (authProfileSection) says callers must reject it, and silently
     * dropping it would save the one value the section has while the client
     * believes it addressed row 2.
     */
    private function getIndex(authProfileSection $section): ?int
    {
        $index = waRequest::post('index');
        if ($index === null || $index === '' || is_array($index)) {
            return null;
        }

        if (!$section->isMultiple()) {
            throw new waException(_w('This profile section holds a single value.'), 400);
        }

        if (!preg_match('/^\d+$/', (string)$index)) {
            throw new waException(_w('Malformed value number.'), 400);
        }

        return (int)$index;
    }

    /**
     * The section's own slice of the POST.
     *
     * For a section made of contact fields the form extracts it: waContactForm
     * knows its namespace, trims the values and drops everything that is not
     * its own field — so a request carrying more than one section's fields
     * still saves exactly one section. A section without a form (linked
     * accounts, account deletion) gets the raw POST, with _csrf already removed
     * by waRequest::post(); what the rest of it means is that section's own
     * business.
     */
    private function getData(authProfileSection $section, ?int $index): array
    {
        $form = $section->getForm($index);
        if (!$form) {
            return (array)waRequest::post();
        }

        $data = $form->post();
        if ($data === null) {
            // Not "the user cleared the fields" — the namespace is missing from
            // the request altogether, so there is nothing to interpret.
            throw new waException(_w('No section data submitted.'), 400);
        }

        return (array)$data;
    }

    private function respondSuccess(authProfileSection $section, ?int $index): void
    {
        if (!waRequest::isXMLHttpRequest()) {
            // No JS: ordinary post/redirect/get. The framework's own flag is
            // reused so that the profile page shows its "saved" notice.
            wa()->getStorage()->set('my/profile/updated', true);
            $this->getResponse()->redirect(authHelper::getMyUrl());
        }

        $this->response = [
            'section' => $section->getId(),
            'index'   => $index,
            'mode'    => authProfileSection::MODE_VIEW,
            'html'    => $section->render(authProfileSection::MODE_VIEW, $index),
        ];
    }

    /**
     * Errors are sent as waContactForm sends them, field_id => messages, rather
     * than through setError(): that one stores (message, data) tuples and loses
     * which field each message belongs to, and the field binding is the whole
     * point for a form.
     */
    private function respondFailure(authProfileSection $section, ?int $index, array $data): void
    {
        $errors = $section->getErrors();

        if (!waRequest::isXMLHttpRequest()) {
            // No JS: the state of the failure has to survive the redirect, or
            // the visitor lands on a page that looks as if nothing happened.
            wa()->getStorage()->set(self::FAILED_SAVE, [
                'section' => $section->getId(),
                'index'   => $index,
                'data'    => $data,
                'errors'  => $errors,
            ]);
            $this->getResponse()->redirect(authHelper::getMyUrl());
        }

        $this->errors   = $errors ?: ['' => [_w('The changes could not be saved.')]];
        $this->response = [
            'section' => $section->getId(),
            'index'   => $index,
            'mode'    => authProfileSection::MODE_EDIT,
            'html'    => $section->render(authProfileSection::MODE_EDIT, $index),
        ];
    }

    private function respondRedirect(authProfileSection $section, ?int $index, string $url): void
    {
        if (!waRequest::isXMLHttpRequest()) {
            $this->getResponse()->redirect($url);
        }

        $this->response = [
            'section'  => $section->getId(),
            'index'    => $index,
            'redirect' => $url,
        ];
    }

    /**
     * Values of the contact fields this section owns, for the log diff below.
     * A section that owns no contact fields yields nothing to compare.
     */
    private function readOwnFields(authProfileSection $section): array
    {
        $contact = $this->getContact();

        $values = [];
        foreach (authProfileSectionRegistry::getFieldIds($section->getId()) as $field_id) {
            $values[$field_id] = $contact->get($field_id);
        }

        return $values;
    }

    /**
     * Sections leave logging to the endpoint (see authProfileSectionFields::save()),
     * because it is the request that knows the context. The action name stays
     * the framework's 'my_profile_edit', so the entries of this app and of
     * waMyProfileAction remain one history of the same thing.
     *
     * The submitted data is never logged, only the diff of the section's own
     * contact fields: sections outside that set include account deletion and
     * password changes, whose payload has no business in wa_log.
     */
    private function logSave(authProfileSection $section, array $before): void
    {
        $params = ['section' => $section->getId()];

        if ($before) {
            $diff = [];
            wa_array_diff_r($before, $this->readOwnFields($section), $diff);
            if (!$diff) {
                // Resubmitting unchanged values is not an edit.
                return;
            }
            $params['diff'] = $diff;
        }

        $this->logAction('my_profile_edit', $params, null, $this->getContact()->getId());
    }

    private function getContact(): waContact
    {
        return wa()->getUser();
    }
}
