<?php
/**
 * Outpost CMS — Compass Template Helpers
 *
 * Safe to require_once from compiled templates. Provides server-side
 * functions for Compass facet tags (counts, values, hierarchy) without
 * the API router or header side-effects that compass.php carries.
 */

if (!function_exists('compass_tpl_facet_values')) {

    /**
     * Get distinct values and counts for a field across a collection.
     * Returns associative array: ['value' => count, ...]
     */
    function compass_tpl_facet_values(string $collectionSlug, string $fieldName): array {
        $col = OutpostDB::fetchOne(
            'SELECT id FROM collections WHERE slug = ?',
            [$collectionSlug]
        );
        if (!$col) return [];

        $items = OutpostDB::fetchAll(
            "SELECT data FROM collection_items WHERE collection_id = ? AND status = 'published'",
            [$col['id']]
        );

        $counts = [];
        foreach ($items as $row) {
            $data = json_decode($row['data'], true) ?: [];
            $val = $data[$fieldName] ?? '';
            if ($val === '' || $val === null) continue;

            // Handle comma-separated or array values
            $vals = is_array($val) ? $val : array_map('trim', explode(',', (string) $val));
            foreach ($vals as $v) {
                $v = trim($v);
                if ($v === '') continue;
                $key = strtolower($v);
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        arsort($counts);
        return $counts;
    }

    /**
     * Get count of items where a boolean/truthy field is set.
     */
    function compass_tpl_facet_count(string $collectionSlug, string $fieldName): int {
        $col = OutpostDB::fetchOne(
            'SELECT id FROM collections WHERE slug = ?',
            [$collectionSlug]
        );
        if (!$col) return 0;

        $items = OutpostDB::fetchAll(
            "SELECT data FROM collection_items WHERE collection_id = ? AND status = 'published'",
            [$col['id']]
        );

        $count = 0;
        foreach ($items as $row) {
            $data = json_decode($row['data'], true) ?: [];
            $val = $data[$fieldName] ?? '';
            if ($val && $val !== '0' && $val !== 'false') $count++;
        }

        return $count;
    }

    /**
     * Get total count of published items in a collection.
     */
    function compass_tpl_total_count(string $collectionSlug): int {
        $col = OutpostDB::fetchOne(
            'SELECT id FROM collections WHERE slug = ?',
            [$collectionSlug]
        );
        if (!$col) return 0;

        $row = OutpostDB::fetchOne(
            "SELECT COUNT(*) as cnt FROM collection_items WHERE collection_id = ? AND status = 'published'",
            [$col['id']]
        );
        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * Get top-level hierarchy values for a field.
     * Delimiter defaults to " > " (e.g. "Food > Italian > Pizza").
     */
    function compass_tpl_hierarchy_top(string $collectionSlug, string $fieldName, string $delimiter = ' > '): array {
        $col = OutpostDB::fetchOne(
            'SELECT id FROM collections WHERE slug = ?',
            [$collectionSlug]
        );
        if (!$col) return [];

        $items = OutpostDB::fetchAll(
            "SELECT data FROM collection_items WHERE collection_id = ? AND status = 'published'",
            [$col['id']]
        );

        $top = [];
        foreach ($items as $row) {
            $data = json_decode($row['data'], true) ?: [];
            $val = $data[$fieldName] ?? '';
            if (!$val) continue;

            $paths = array_map('trim', explode(',', (string) $val));
            foreach ($paths as $path) {
                $parts = explode($delimiter, $path);
                $topLevel = trim($parts[0]);
                if ($topLevel !== '') $top[$topLevel] = true;
            }
        }

        ksort($top);
        return array_keys($top);
    }
}
