<?php
/**
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2026
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * authConfig::getMerged() и его read-helper'ы — двухуровневый мердж (умолчания → домен)
 *
 * Использует authTestConfigOverrideTrait, чтобы подставить домену тестовый набор настроек
 * поверх настоящих дистрибутивных умолчаний (lib/config/config.php) — иначе тест либо читал
 * бы реальные login_methods разработчика, либо писал бы в wa-config/apps/auth/config.php.
 */
class authConfigTest extends TestCase
{
    use authTestConfigOverrideTrait;

    protected function tearDown(): void
    {
        $this->restoreAuthConfig();
        parent::tearDown();
    }

    public function testDomainValueOverridesDistributionDefault(): void
    {
        // Distribution default for login_methods is an empty array (config.php);
        // a domain-level value must win.
        $this->overrideAuthConfig(['login_methods' => ['email', 'phone']]);

        $this->assertSame(['email', 'phone'], authConfig::getLoginMethods());
    }

    public function testMissingKeyFallsBackToDistributionDefault(): void
    {
        // rememberme is not in the override — it must come from config.php's distribution
        // default (false), not silently disappear or come back null.
        $this->overrideAuthConfig(['login_methods' => ['email']]);

        $this->assertFalse(authConfig::get('rememberme'));
        $this->assertTrue(authConfig::get('recovery_enabled'));
    }

    public function testGetDefaultOnlyAppliesWhenKeyIsAbsent(): void
    {
        // redirect_after_login is declared as null in config.php (a real, explicit value),
        // not merely absent — get()'s $default must not paper over that null, per the
        // method's own docblock. array_key_exists is what draws the line.
        $this->overrideAuthConfig([]);

        $this->assertNull(authConfig::get('redirect_after_login', 'fallback-should-not-appear'));
        $this->assertSame(
            'fallback-should-not-appear',
            authConfig::get('there-is-no-such-key', 'fallback-should-not-appear')
        );
    }

    public function testIsEnabledReflectsLoginMethodsPresence(): void
    {
        $this->overrideAuthConfig(['login_methods' => []]);
        $this->assertFalse(authConfig::isEnabled());

        $this->overrideAuthConfig(['login_methods' => ['email']]);
        $this->assertTrue(authConfig::isEnabled());
    }

    public function testPluginSettingsWithAndWithoutInstance(): void
    {
        $this->overrideAuthConfig([
            'plugin_settings' => [
                'oidc' => [
                    'gitlab'   => ['client_id' => 'g1'],
                    'keycloak' => ['client_id' => 'k1'],
                ],
                'totp' => ['issuer' => 'Syrnik'],
            ],
        ]);

        $this->assertSame(
            ['gitlab' => ['client_id' => 'g1'], 'keycloak' => ['client_id' => 'k1']],
            authConfig::getPluginSettings('oidc')
        );
        $this->assertSame(['client_id' => 'g1'], authConfig::getPluginSettings('oidc', null, 'gitlab'));
        $this->assertSame([], authConfig::getPluginSettings('oidc', null, 'there-is-no-such-instance'));
        $this->assertSame(['issuer' => 'Syrnik'], authConfig::getPluginSettings('totp'));
        $this->assertSame([], authConfig::getPluginSettings('there-is-no-such-plugin'));
    }
}
