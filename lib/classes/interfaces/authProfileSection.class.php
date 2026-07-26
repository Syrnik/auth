<?php

/**
 * One independently editable block of the profile page (my/).
 *
 * A section is not "a subset of the profile form" — it knows the condition of
 * its own existence and owns its data end to end. See
 * docs/adr/001-profile-config-boundaries.md: visibility comes from three
 * independent sources (site's personal_fields, auth's login_methods, the
 * contact's actual linked accounts), and which of them applies is the
 * section's own business, not the template's.
 *
 * Three states must stay distinguishable, because "the config forbids it" and
 * "the user has not filled it in" are different questions:
 *
 *   unavailable — isAvailable() === false, the section is not rendered at all;
 *   empty       — available, but the contact has no value yet (heading + "Add");
 *   filled      — available and has data.
 *
 * Multi-value sections (addresses, phones, emails) address a single value by
 * $index; single-value sections ignore it and always get null. The parameter is
 * part of the contract from the start because adding it later would have to
 * touch every implementation at once.
 */
interface authProfileSection
{
    /** view mode: the section renders its current value */
    const MODE_VIEW = 'view';

    /** edit mode: the section renders its form */
    const MODE_EDIT = 'edit';

    /**
     * Section id, unique within the registry: 'name', 'address', 'password'.
     * Used in the save URL (my/save/{section}/) and in partial file names.
     */
    public function getId(): string;

    /**
     * Human-readable section title.
     */
    public function getName(): string;

    /**
     * Id of the group this section is rendered in, see
     * authProfileSectionRegistry::getGroupNames().
     */
    public function getGroup(): string;

    /**
     * Whether the config allows this section at all. A false here means the
     * section does not exist for this domain — not that it is empty.
     */
    public function isAvailable(): bool;

    /**
     * Whether an available section has no data yet.
     */
    public function isEmpty(): bool;

    /**
     * Whether the section holds a list of values addressed by $index.
     * Callers must reject an index for a single-value section.
     */
    public function isMultiple(): bool;

    /**
     * The section's own small waContactForm, or null for sections that are not
     * about contact fields at all (linked accounts, account deletion).
     *
     * Deliberately a separate form per section, not a slice of the profile-wide
     * one: waContactForm::validateFields() (waContactForm.class.php:495) walks
     * every field of the form and reads values via post($field_id), which
     * returns null for fields absent from POST — so a partial POST fails
     * validation on the fields of other sections.
     */
    public function getForm(?int $index = null): ?waContactForm;

    /**
     * Reason why this value may not be removed (unlinked, cleared), or null
     * when removal is allowed. Guards decision 3 of ADR 001: the last remaining
     * login factor cannot be taken away, or the account becomes unreachable.
     * Sections that hold nothing removable return null.
     */
    public function getRemovalLock(?int $index = null): ?string;

    /**
     * Store $data (the section's own namespaced POST slice) and report success.
     * On failure the reasons are available from getErrors().
     */
    public function save(array $data, ?int $index = null): bool;

    /**
     * Errors from the last save(), as field_id => list of messages. Errors not
     * bound to a field use '' as the key, same as waContactForm.
     */
    public function getErrors(): array;

    /**
     * Restores the state a failed save left behind in an earlier request:
     * $data is what the visitor submitted, $errors what save() reported.
     *
     * Needed because without JS a failed save answers with a redirect back to
     * the profile page (authFrontendMySaveController), and the request that
     * renders that page carries neither the submitted values nor the errors.
     * The section restores them itself — only it knows where its values live,
     * and a section without a form has nothing but the errors to restore.
     */
    public function restoreFailedSave(array $data, array $errors, ?int $index = null): void;

    /**
     * Where the visitor has to go now that this section saved, or null to stay
     * on the profile — which is what almost every section wants, and the
     * default.
     *
     * Exists because a save can end the page it was made on. Deleting the
     * account is the case that forced it: the contact whose profile this is no
     * longer exists, so redrawing the section afterwards would render it from a
     * deleted record. "Show the result in place" is not universal, and a section
     * is the only thing that knows whether it still has a place to show.
     */
    public function getRedirectAfterSave(): ?string;

    /**
     * Rendered HTML of the section in the given mode (MODE_VIEW | MODE_EDIT).
     */
    public function render(string $mode, ?int $index = null): string;
}
