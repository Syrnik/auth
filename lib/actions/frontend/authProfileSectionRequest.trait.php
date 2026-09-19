<?php

/**
 * Shared request parsing for the two POST endpoints that address one profile
 * section by id (+ optional value index): authFrontendMySaveController
 * (my/save/<section>/) and authFrontendMyConfirmSendController
 * (my/confirm/send/<section>/). Both read 'section' and 'index' the exact
 * same way — kept in one place so the two endpoints' 404/400 semantics
 * cannot drift apart from each other.
 */
trait authProfileSectionRequestTrait
{
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
     * dropping it would address the one value the section has while the client
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

    private function getContact(): waContact
    {
        return wa()->getUser();
    }
}
