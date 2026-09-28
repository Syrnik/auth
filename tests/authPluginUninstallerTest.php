<?php
/**
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2026
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * authPluginUninstaller — cleanup when a whole plugin is deleted (AUTH-559)
 *
 * The config transform is pure (saved 'domains' array in, array out), so it is
 * tested without ever writing wa-config/apps/auth/config.php. The TOTP purge
 * runs against wa_contact_data shadowed by authTestTemporaryTablesTrait.
 */
class authPluginUninstallerTest extends TestCase
{
    use authTestTemporaryTablesTrait;

    protected function tearDown(): void
    {
        $this->tearDownTemporaryTables();
        parent::tearDown();
    }

    public function testStripRemovesEveryReferenceOnEveryDomain(): void
    {
        $domains = [
            'a.local' => [
                'login_methods'   => ['email', 'oidc_plugin:gitlab', 'github_plugin', 'oidc_plugin:keycloak'],
                'plugin_settings' => ['oidc' => ['gitlab' => ['x' => '1']], 'github' => ['y' => '2']],
            ],
            'b.local' => [
                'login_methods'   => ['oidc_plugin'],
                'signup_methods'  => ['oidc_plugin:gitlab'],
                'plugin_settings' => ['oidc' => ['keycloak' => []]],
            ],
        ];

        $result = authPluginUninstaller::stripPluginFromDomains($domains, 'oidc');

        $this->assertSame(['email', 'github_plugin'], $result['a.local']['login_methods']);
        $this->assertSame(['github' => ['y' => '2']], $result['a.local']['plugin_settings']);
        $this->assertSame([], $result['b.local']['login_methods']);
        $this->assertSame([], $result['b.local']['signup_methods']);
        $this->assertSame([], $result['b.local']['plugin_settings']);
    }

    public function testStripDoesNotTouchPluginWithSharedPrefix(): void
    {
        // 'foo' must not take 'foobar_plugin' or 'foo_plugin_x' with it.
        $domains = ['a.local' => [
            'login_methods'   => ['foobar_plugin', 'foobar_plugin:k'],
            'guard_plugins'   => ['foobar'],
            'plugin_settings' => ['foobar' => ['z' => '1']],
        ]];

        $this->assertSame($domains, authPluginUninstaller::stripPluginFromDomains($domains, 'foo'));
    }

    public function testBareIdIsThisPluginOnlyWhereTheReaderIsLenient(): void
    {
        // guard_plugins/captcha_plugin/throttle_store accept a bare id (the
        // Guards screen stores it that way); login_methods and friends never
        // do — there a bare id is a built-in.
        $domains = ['a.local' => [
            'login_methods'     => ['email'],
            'challenge_methods' => ['email'],
            'guard_plugins'     => ['email', 'testguard'],
            'captcha_plugin'    => 'email',
            'throttle_store'    => 'email_plugin',
        ]];

        $result = authPluginUninstaller::stripPluginFromDomains($domains, 'email')['a.local'];

        $this->assertSame(['email'], $result['login_methods']);
        $this->assertSame(['email'], $result['challenge_methods']);
        $this->assertSame(['testguard'], $result['guard_plugins']);
        $this->assertNull($result['captcha_plugin']);
        $this->assertNull($result['throttle_store']);
    }

    public function testStripLeavesDomainsWithoutReferencesIdentical(): void
    {
        $domains = [
            'a.local' => ['login_methods' => ['email'], 'captcha_plugin' => 'gcaptcha'],
            'broken'  => 'not-an-array',
        ];

        $this->assertSame($domains, authPluginUninstaller::stripPluginFromDomains($domains, 'oidc'));
    }

    public function testCollectInstanceKeysUnionsSettingsAndListsAcrossDomains(): void
    {
        $domains = [
            'a.local' => [
                'plugin_settings' => ['oidc' => ['gitlab' => []]],
                'login_methods'   => ['oidc_plugin:keycloak', 'other_plugin:gitlab2'],
            ],
            'b.local' => [
                'plugin_settings' => ['oidc' => ['gitlab' => [], 'Bad Key!' => []]],
                'signup_methods'  => ['oidc_plugin:azure'],
            ],
        ];

        $keys = authPluginUninstaller::collectInstanceKeys($domains, 'oidc');
        sort($keys);

        $this->assertSame(['azure', 'gitlab', 'keycloak'], $keys);
    }

    public function testTotpPurgeRemovesEnrollmentOfEveryContact(): void
    {
        // plugins/totp is a separate repository, not part of this checkout by default.
        $plugin = authPluginManager::get('totp_plugin');
        if (!$plugin instanceof authTotpPlugin) {
            $this->markTestSkipped('plugins/totp is not installed');
        }

        $this->setUpTemporaryTables('wa_contact_data');

        authTotpPlugin::saveSecret(1, 'SECRETONE', 10);
        authTotpPlugin::saveSecret(2, 'SECRETTWO');
        $this->insertData(1, 'email', 'a@example.com');

        $this->assertSame(2, $plugin->countInstanceContacts());
        $this->assertSame(2, $plugin->purgeInstanceData());

        $this->assertFalse(authTotpPlugin::hasSecret(1));
        $this->assertFalse(authTotpPlugin::hasSecret(2));
        $this->assertSame(0, $plugin->countInstanceContacts());

        $left = (new waContactDataModel())->query(
            "SELECT field FROM wa_contact_data WHERE contact_id IN (1, 2)"
        )->fetchAll(null, true);
        $this->assertSame(['email'], $left);
    }

    private function insertData(int $contact_id, string $field, string $value): void
    {
        (new waContactDataModel())->insert([
            'contact_id' => $contact_id,
            'field'      => $field,
            'value'      => $value,
            'sort'       => 0,
        ]);
    }
}
