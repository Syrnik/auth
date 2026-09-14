<?php

/**
 * Bulk operations over wa_contact_data rows an OAuth/authMethod plugin owns —
 * the admin-side counterpart to authContactResolver, which only ever reads or
 * writes one contact's row at a time.
 *
 * Exists for AUTH-440: deleting a named instance of a multi_instance plugin
 * (backend "Login" screen) has to tell the admin how many accounts are linked
 * through it, then actually remove those links — a source that outlives its
 * connection is how a later connection with the same instance key silently
 * inherits a stranger's account (authContactResolver::find() matches by
 * source_id alone). One place computes the field name
 * (authContactResolver::getSourceField()) so the query side can never drift
 * from the write side.
 *
 * Always runs against the default connection: authTestTemporaryTablesTrait
 * shadows wa_contact_data with a real CREATE TEMPORARY TABLE, which is only
 * visible on the connection that created it, so this must go through the
 * same plain waContactDataModel every other read/write of that table uses.
 */
class authContactLinks
{
    /**
     * Number of distinct contacts holding a link for one source.
     */
    public static function countBySource(string $source): int
    {
        return self::countBySources([$source])[$source] ?? 0;
    }

    /**
     * Number of distinct contacts holding a link, per source, in a single
     * query — for a caller that needs several sources' counts at once rather
     * than one countBySource() call per source.
     *
     * waDbQuery has no GROUP BY support, so this goes through waModel::query()
     * with typed placeholders directly, the same style authContactResolver
     * already uses for its own raw SQL.
     *
     * @param string[] $sources
     * @return array<string, int> source => count, only for sources that have at least one link
     */
    public static function countBySources(array $sources): array
    {
        $sources = array_values(array_unique(array_filter($sources, 'strlen')));
        if (!$sources) {
            return [];
        }

        $model  = new waContactDataModel();
        $fields = array_map(static fn(string $source): string => self::fieldOf($source), $sources);

        $rows = $model->query(
            "SELECT field, COUNT(DISTINCT contact_id) AS cnt
             FROM " . $model->getTableName() . "
             WHERE field IN (s:fields)
             GROUP BY field",
            ['fields' => $fields]
        )->fetchAll('field');

        $result = [];
        foreach ($sources as $source) {
            $field = self::fieldOf($source);
            if (isset($rows[$field])) {
                $result[$source] = (int)$rows[$field]['cnt'];
            }
        }
        return $result;
    }

    /**
     * Deletes every link for one source, across every contact. Returns the
     * number of rows removed, for the admin log line the caller writes.
     *
     * Counted before deleting rather than trusted from deleteByField()'s
     * return value: waModel::exec() hands back whatever the DB adapter's
     * query() returns for a DELETE, which isn't documented as a row count
     * here, and this number ends up in an audit log line — worth one extra
     * cheap query to be sure it's right.
     */
    public static function deleteBySource(string $source): int
    {
        $count = self::countBySource($source);
        if ($count > 0) {
            (new waContactDataModel())->deleteByField(['field' => self::fieldOf($source)]);
        }
        return $count;
    }

    private static function fieldOf(string $source): string
    {
        return authContactResolver::getSourceField($source);
    }
}
