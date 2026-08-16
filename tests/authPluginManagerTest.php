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
}
