<?php
/**
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2026
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * authPluginManager::splitInstance() и состав встроенных методов — без БД и без конфига
 *
 * splitInstance() разбирает id вида 'oidc_plugin:gitlab' на [plugin_id, instance] и завязано
 * на него всё именование именованных инстансов (getEnabled(), getProfileSectionPlugins(),
 * loadPlugin()) — ошибка здесь ломает multi_instance-плагины молча, без исключения.
 *
 * getBuiltinFormMethods() — единственный источник состава встроенных form-методов, разделяемый
 * рантаймом (loadBuiltin()) и экраном настроек бэкенда; тест фиксирует состав, чтобы случайное
 * удаление записи не осталось незамеченным.
 */
class authPluginManagerTest extends TestCase
{
    use authTestConfigOverrideTrait;

    protected function tearDown(): void
    {
        $this->restoreAuthConfig();
        parent::tearDown();
    }

    /** @dataProvider splitInstanceProvider */
    public function testSplitInstance(string $id, array $expected): void
    {
        $this->assertSame($expected, authPluginManager::splitInstance($id));
    }

    public function splitInstanceProvider(): array
    {
        return [
            'plain id has no instance'          => ['oidc_plugin', ['oidc_plugin', null]],
            'colon splits id and instance'      => ['oidc_plugin:gitlab', ['oidc_plugin', 'gitlab']],
            'builtin id is untouched'           => ['email', ['email', null]],
            // Trailing colon with nothing after it is not a named instance —
            // an empty instance key would desync from every "$instance !== null" check.
            'trailing colon yields null instance' => ['oidc_plugin:', ['oidc_plugin', null]],
            'instance can itself contain digits' => ['oidc_plugin:keycloak2', ['oidc_plugin', 'keycloak2']],
        ];
    }

    public function testBuiltinFormMethodsMapping(): void
    {
        $this->assertSame(
            [
                'email' => 'authEmailMethod',
                'login' => 'authLoginMethod',
                'phone' => 'authPhoneMethod',
            ],
            authPluginManager::getBuiltinFormMethods()
        );
    }

    public function testGetReturnsBuiltinMethodInstance(): void
    {
        $method = authPluginManager::get('email');
        $this->assertInstanceOf(authEmailMethod::class, $method);
    }

    public function testGetReturnsWaidForSpecialCasedId(): void
    {
        // 'waid' is a system adapter, not in getBuiltinFormMethods(), but loadBuiltin()
        // special-cases it to authWaidMethod — this is the only place that mapping lives.
        $method = authPluginManager::get('waid');
        $this->assertInstanceOf(authWaidMethod::class, $method);
    }

    public function testGetReturnsNullForUnknownId(): void
    {
        $this->assertNull(authPluginManager::get('there-is-no-such-method'));
    }

    public function testPluginSuffixedIdIsNotABuiltin(): void
    {
        // A '..._plugin' id is only ever resolved through loadPlugin(); an id for a
        // plugin directory that does not exist must not fall through to loadBuiltin().
        $this->assertNull(authPluginManager::get('there-is-no-such-plugin_plugin'));
    }

    /** @dataProvider validInstanceKeyProvider */
    public function testIsValidInstanceKey(string $key, bool $expected): void
    {
        $this->assertSame($expected, authPluginManager::isValidInstanceKey($key));
    }

    public function validInstanceKeyProvider(): array
    {
        return [
            'plain lowercase key'       => ['gitlab', true],
            'digits and hyphen'         => ['keycloak-2', true],
            'starts with a digit'       => ['2fa', true],
            'underscore allowed'        => ['my_instance', true],
            'empty string'              => ['', false],
            'uppercase is not accepted' => ['GitLab', false],
            'starts with a hyphen'      => ['-gitlab', false],
            'contains a colon'          => ['gitlab:eu', false],
            'contains a space'          => ['git lab', false],
        ];
    }

    /**
     * filterInstanceBlocks() is the one place AUTH-440's explicit deletion
     * list actually removes an instance's settings block — every guarantee
     * "deletion is never inferred from absence" rests on lives here.
     */
    public function testFilterInstanceBlocksDropsOnlyDeletedKeys(): void
    {
        $posted = [
            'gitlab'   => ['client_id' => '1'],
            'keycloak' => ['client_id' => '2'],
        ];

        $this->assertSame(
            ['keycloak' => ['client_id' => '2']],
            authPluginManager::filterInstanceBlocks($posted, ['gitlab'])
        );
    }

    public function testFilterInstanceBlocksNormalizesKeyCaseAndWhitespace(): void
    {
        $posted = ['GitLab ' => ['client_id' => '1']];

        // Same normalization the server has always applied when reading
        // posted instance keys (strtolower + trim) — a deletion list built
        // from the same un-normalized key must still match.
        $this->assertSame(
            [],
            authPluginManager::filterInstanceBlocks($posted, ['gitlab'])
        );
    }

    public function testFilterInstanceBlocksDropsKeysFailingTheRegex(): void
    {
        $posted = [
            'gitlab'  => ['client_id' => '1'],
            'git lab' => ['client_id' => '2'],
            ''        => ['client_id' => '3'],
        ];

        $this->assertSame(
            ['gitlab' => ['client_id' => '1']],
            authPluginManager::filterInstanceBlocks($posted, [])
        );
    }

    public function testFilterInstanceBlocksDeletingEveryInstanceYieldsEmptyArray(): void
    {
        // The AUTH-440 regression this whole mechanism exists to fix:
        // deleting the last instance of a plugin must produce [], not have
        // the stale block survive because "nothing was posted" looked the
        // same as "nothing to delete".
        $posted = ['gitlab' => ['client_id' => '1']];

        $this->assertSame(
            [],
            authPluginManager::filterInstanceBlocks($posted, ['gitlab'])
        );
    }

    public function testFilterInstanceBlocksOnEmptyPostedIsEmpty(): void
    {
        $this->assertSame([], authPluginManager::filterInstanceBlocks([], ['gitlab']));
    }

    /**
     * getRecoveryProvider() — the resolver docs/adr/004-recovery-channels.md
     * introduces for AUTH-48, discovered by id the same way get() discovers a
     * login method, but never confused with one: 'email'/'phone' here yield
     * authEmailRecoveryProvider/authPhoneRecoveryProvider, not
     * authEmailMethod/authPhoneMethod.
     */
    public function testGetRecoveryProviderReturnsBuiltinEmailProvider(): void
    {
        $this->assertInstanceOf(authEmailRecoveryProvider::class, authPluginManager::getRecoveryProvider('email'));
    }

    public function testGetRecoveryProviderReturnsBuiltinPhoneProvider(): void
    {
        $this->assertInstanceOf(authPhoneRecoveryProvider::class, authPluginManager::getRecoveryProvider('phone'));
    }

    public function testGetRecoveryProviderLoadsAnInstalledPlugin(): void
    {
        $provider = authPluginManager::getRecoveryProvider('testrecovery_plugin');
        $this->assertInstanceOf(authRecoveryProvider::class, $provider);
    }

    public function testGetRecoveryProviderReturnsNullForAnUnknownId(): void
    {
        $this->assertNull(authPluginManager::getRecoveryProvider('there-is-no-such-thing'));
    }

    /**
     * The flag/interface mismatch check every other role already has
     * (is_guard, is_captcha, is_throttle_store, ...) — a fixture plugin
     * declaring is_recovery_provider without implementing
     * authRecoveryProvider must fail to load.
     *
     * Built and torn down inline rather than shipped as a permanent fixture
     * (like testguard/testmulti): a plugin sitting in plugins/ with a
     * declared-but-unimplemented flag breaks loadPlugin() for *every*
     * interface, not just this one — authBackendDomainSettingsAction::
     * getPluginInstancesOf() scandir()s the whole plugins/ directory and
     * calls authPluginManager::get() on each entry regardless of which
     * interface it is currently enumerating, and loadPlugin() validates
     * every flag a plugin.php declares, not only the one being asked about.
     * A permanent broken fixture here would 500 the real "Login"/"Guards"/
     * "Captcha" backend screens on any install this test suite ships with,
     * not just fail this one assertion — this was caught by hand against a
     * live domain while verifying AUTH-48, not by any automated check.
     */
    public function testGetRecoveryProviderThrowsWhenFlagDeclaredWithoutInterface(): void
    {
        $dir = wa()->getAppPath('plugins/testbadrecoverytmp', 'auth');
        mkdir($dir . '/lib/config', 0777, true);
        file_put_contents(
            $dir . '/lib/config/plugin.php',
            "<?php\nreturn ['name' => 'x', 'version' => '1.0.0', 'is_recovery_provider' => true];\n"
        );
        file_put_contents(
            $dir . '/lib/authTestbadrecoverytmpPlugin.class.php',
            "<?php\nclass authTestbadrecoverytmpPlugin extends authPlugin {}\n"
        );

        try {
            $this->expectException(waException::class);
            authPluginManager::getRecoveryProvider('testbadrecoverytmp_plugin');
        } finally {
            authPluginManager::clearCache();
            unlink($dir . '/lib/authTestbadrecoverytmpPlugin.class.php');
            unlink($dir . '/lib/config/plugin.php');
            rmdir($dir . '/lib/config');
            rmdir($dir . '/lib');
            rmdir($dir);
        }
    }

    /**
     * getRecoveryProviders() tries claims() in recovery_channels order
     * (decision 3 of docs/adr/004-recovery-channels.md) — a plugin listed
     * before the built-ins must come back before them, not after.
     */
    public function testGetRecoveryProvidersFollowsRecoveryChannelsOrder(): void
    {
        $this->overrideAuthConfig(['recovery_channels' => ['phone', 'testrecovery_plugin', 'email']]);

        $ids = array_map(static fn($p) => $p->getId(), authPluginManager::getRecoveryProviders());

        $this->assertSame(['phone', 'testrecovery', 'email'], $ids);
    }

    public function testGetRecoveryProvidersSkipsUnknownIds(): void
    {
        $this->overrideAuthConfig(['recovery_channels' => ['email', 'there-is-no-such-thing', 'phone']]);

        $ids = array_map(static fn($p) => $p->getId(), authPluginManager::getRecoveryProviders());

        $this->assertSame(['email', 'phone'], $ids);
    }
}
