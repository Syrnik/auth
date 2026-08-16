<?php
/**
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2026
 */

declare(strict_types=1);

/**
 * Реальные MySQL TEMPORARY TABLE, перекрывающие таблицы приложения по имени
 *
 * Приём взят из тестов плагина shopSdekint (shopSdekintPluginTestTemporaryTablesTrait):
 * временная таблица с тем же именем, что и настоящая, видна только текущему соединению и сама
 * исчезает, когда оно закрывается. Код под тестом (модели) работает с настоящими именами таблиц
 * без единой правки, а тест не касается реальных данных dev-БД (wa-config/db.php) и не оставляет
 * мусора при упавшем assert-е.
 *
 * Схема временной таблицы клонируется через SHOW CREATE TABLE уже существующей настоящей таблицы —
 * то есть приём проверяет поведение кода поверх схемы, а не саму схему.
 */
trait authTestTemporaryTablesTrait
{
    /** @var string[] */
    private array $temporary_tables = [];

    /**
     * @param string|string[] $tables
     * @throws waDbException
     */
    protected function setUpTemporaryTables($tables): void
    {
        $model = new waModel();

        foreach ((array)$tables as $table) {
            $sql = $model->query("SHOW CREATE TABLE `$table`")->fetchField(1);
            $sql = preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $sql, 1);
            $sql = preg_replace('/\s+AUTO_INCREMENT=\d+/', '', $sql);

            try {
                $model->exec($sql);
            } catch (waDbException $e) {
                // временная таблица уже открыта на этом соединении — например, осталась
                // от упавшего в середине теста прогона. Пересоздаём с нуля.
                if ($e->getCode() == 1050) {
                    $model->exec("DROP TEMPORARY TABLE `$table`");
                    $model->exec($sql);
                } else {
                    throw $e;
                }
            }

            $this->temporary_tables[] = $table;
        }
    }

    protected function tearDownTemporaryTables(): void
    {
        if (empty($this->temporary_tables)) {
            return;
        }

        $model = new waModel();
        foreach ($this->temporary_tables as $table) {
            $model->exec("DROP TEMPORARY TABLE IF EXISTS `$table`");
        }
        $this->temporary_tables = [];
    }
}
