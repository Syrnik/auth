<?php
/**
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2026
 */

declare(strict_types=1);

/**
 * Подмена доменного конфига auth на время одного теста
 *
 * authConfig держит настройки в собственных private static $saved/$defaults/$cache
 * (lib/classes/authConfig.class.php) — единственный способ подсунуть тестовый набор
 * login_methods/challenge_methods/... это Reflection поверх $saved, а не файл на диске:
 * lib/config/config.php (дистрибутивные умолчания) и wa-config/apps/auth/config.php
 * (сохранённые настройки) должны остаться нетронутыми для остальных тестов и разработчика.
 *
 * $defaults не подменяется: пусть грузятся настоящие из lib/config/config.php — так тест
 * заодно проверяет реальный набор умолчаний, а не выдуманный.
 *
 * Домен берётся из authConfig::currentDomain(), а не хардкодится — так трейт переживёт
 * смену $_SERVER['HTTP_HOST'] в tests/init.php.
 */
trait authTestConfigOverrideTrait
{
    /**
     * @param array $domain_config значения, которые вернёт authConfig::getMerged() для
     *              текущего домена — например ['login_methods' => ['email', 'phone']]
     */
    protected function overrideAuthConfig(array $domain_config): void
    {
        // clearCache() itself nulls $saved back out, so it must run BEFORE $saved is set,
        // not after — it only exists here to drop any $cache entry memoized (by an earlier
        // getMerged() call) before this override took effect.
        authConfig::clearCache();

        $domain = authConfig::currentDomain();

        $property = new ReflectionProperty(authConfig::class, 'saved');
        $property->setAccessible(true);
        $property->setValue(null, ['domains' => [$domain => $domain_config]]);
    }

    /**
     * Возвращает authConfig к состоянию "ничего не подменяли". authConfig::clearCache()
     * сама обнуляет $saved и $defaults, так что оба снова читаются с диска при следующем
     * обращении — Reflection здесь не нужен, только вызвать сброс. Дополнительно сбрасывает
     * кэш authPluginManager — иначе экземпляр плагина, собранный под подменённым конфигом
     * теста, протечёт в следующий тест.
     */
    protected function restoreAuthConfig(): void
    {
        authConfig::clearCache();
        authPluginManager::clearCache();
    }
}
