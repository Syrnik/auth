<?php
/**
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2026
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * authContactLinks — bulk read/delete over wa_contact_data for AUTH-440
 *
 * Shadows wa_contact_data with a real CREATE TEMPORARY TABLE
 * (authTestTemporaryTablesTrait) so the test can insert rows for made-up
 * sources and delete them without touching real contacts, and without the
 * risk of a failed assertion leaving rows behind.
 */
class authContactLinksTest extends TestCase
{
    use authTestTemporaryTablesTrait;

    protected function setUp(): void
    {
        $this->setUpTemporaryTables('wa_contact_data');
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryTables();
        parent::tearDown();
    }

    public function testCountBySourceCountsDistinctContactsOnly(): void
    {
        // Two rows, same contact, same source (sort 0 and, say, a stray
        // duplicate at a different sort) must count as one linked account —
        // "accounts linked", not "rows written".
        $this->insertLink(1, 'oidc_gitlab', '111');
        $this->insertLink(1, 'oidc_gitlab', '111', 1);
        $this->insertLink(2, 'oidc_gitlab', '222');

        $this->assertSame(2, authContactLinks::countBySource('oidc_gitlab'));
    }

    public function testCountBySourceIsZeroForUnknownSource(): void
    {
        $this->assertSame(0, authContactLinks::countBySource('there-is-no-such-source'));
    }

    public function testCountBySourcesCountsEachSourceInOneQuery(): void
    {
        $this->insertLink(1, 'oidc_gitlab', '111');
        $this->insertLink(2, 'oidc_gitlab', '222');
        $this->insertLink(3, 'oidc_keycloak', '333');

        $this->assertSame(
            ['oidc_gitlab' => 2, 'oidc_keycloak' => 1],
            authContactLinks::countBySources(['oidc_gitlab', 'oidc_keycloak', 'oidc_unused'])
        );
    }

    public function testCountBySourcesReturnsEmptyArrayForNoSources(): void
    {
        $this->assertSame([], authContactLinks::countBySources([]));
    }

    public function testDeleteBySourceRemovesOnlyItsOwnSource(): void
    {
        $this->insertLink(1, 'oidc_gitlab', '111');
        $this->insertLink(2, 'oidc_gitlab', '222');
        $this->insertLink(3, 'oidc_keycloak', '333');

        $removed = authContactLinks::deleteBySource('oidc_gitlab');

        $this->assertSame(2, $removed);
        $this->assertSame(0, authContactLinks::countBySource('oidc_gitlab'));
        $this->assertSame(1, authContactLinks::countBySource('oidc_keycloak'));
    }

    public function testDeleteBySourceOnUnknownSourceRemovesNothing(): void
    {
        $this->insertLink(1, 'oidc_gitlab', '111');

        $this->assertSame(0, authContactLinks::deleteBySource('there-is-no-such-source'));
        $this->assertSame(1, authContactLinks::countBySource('oidc_gitlab'));
    }

    /**
     * Writes one wa_contact_data row the same way authContactResolver does,
     * without going through it: field name is source . '_id', same as
     * authContactResolver::getSourceField().
     */
    private function insertLink(int $contact_id, string $source, string $value, int $sort = 0): void
    {
        (new waContactDataModel())->insert([
            'contact_id' => $contact_id,
            'field'      => $source . '_id',
            'value'      => $value,
            'sort'       => $sort,
        ]);
    }
}
