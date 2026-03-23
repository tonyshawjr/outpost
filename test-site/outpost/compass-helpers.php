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
     * Get distinct values and counts for a facet.
     * Supports both "folder:slug" and "field:name" source formats.
     * Uses the compass_index table if available, falls back to direct query.
     * Returns associative array: ['value' => count, ...]
     */
    function compass_tpl_facet_values(string $collectionSlug, string $source): array {
        $col = OutpostDB::fetchOne(
            'SELECT id FROM collections WHERE slug = ?',
            [$collectionSlug]
        );
        if (!$col) return [];
        $colId = (int) $col['id'];

        // Parse source: "folder:categories", "field:city", or plain "city"
        $facetName = $source;
        $sourceType = 'field';
        if (str_starts_with($source, 'folder:')) {
            $facetName = substr($source, 7);
            $sourceType = 'folder';
        } elseif (str_starts_with($source, 'field:')) {
            $facetName = substr($source, 6);
            $sourceType = 'field';
        } elseif (str_starts_with($source, 'label:')) {
            $facetName = substr($source, 6);
            $sourceType = 'label';
        }

        // Try compass_index first (fast)
        $hasIndex = false;
        try {
            $check = OutpostDB::fetchOne(
                "SELECT COUNT(*) as c FROM compass_index WHERE collection_id = ? AND facet_name = ?",
                [$colId, $facetName]
            );
            $hasIndex = ($check && (int) $check['c'] > 0);
        } catch (\Throwable $e) {
            // Table might not exist yet
        }

        if ($hasIndex) {
            $rows = OutpostDB::fetchAll(
                "SELECT facet_value, facet_display, COUNT(*) as cnt FROM compass_index WHERE collection_id = ? AND facet_name = ? GROUP BY facet_value ORDER BY cnt DESC",
                [$colId, $facetName]
            );
            $result = [];
            foreach ($rows as $r) {
                $display = $r['facet_display'] ?: $r['facet_value'];
                $result[$r['facet_value']] = ['count' => (int) $r['cnt'], 'display' => $display];
            }
            return $result;
        }

        // Fallback: query folder labels or data fields directly
        if ($sourceType === 'folder') {
            $folder = OutpostDB::fetchOne(
                "SELECT f.id FROM folders f WHERE f.collection_id = ? AND f.slug = ?",
                [$colId, $facetName]
            );
            if (!$folder) return [];

            $rows = OutpostDB::fetchAll(
                "SELECT l.slug, l.name, COUNT(il.item_id) as cnt
                 FROM labels l
                 LEFT JOIN item_labels il ON il.label_id = l.id
                 WHERE l.folder_id = ?
                 GROUP BY l.id
                 ORDER BY cnt DESC",
                [(int) $folder['id']]
            );
            $result = [];
            foreach ($rows as $r) {
                if ((int) $r['cnt'] > 0) {
                    $result[$r['slug']] = ['count' => (int) $r['cnt'], 'display' => $r['name']];
                }
            }
            return $result;
        }

        // Field: query distinct values from item data
        $items = OutpostDB::fetchAll(
            "SELECT json_extract(data, '$." . preg_replace('/[^a-zA-Z0-9_]/', '', $facetName) . "') as val
             FROM collection_items WHERE collection_id = ? AND status = 'published'",
            [$colId]
        );
        $counts = [];
        foreach ($items as $row) {
            $val = $row['val'] ?? '';
            if ($val === '' || $val === null) continue;
            $key = (string) $val;
            if (!isset($counts[$key])) {
                $counts[$key] = ['count' => 0, 'display' => $val];
            }
            $counts[$key]['count']++;
        }
        uasort($counts, fn($a, $b) => $b['count'] - $a['count']);
        return $counts;
    }

    /**
     * Get count of items where a boolean/truthy field is set.
     */
    function compass_tpl_facet_count(string $collectionSlug, string $fieldName): int {
        $col = OutpostDB::fetchOne('SELECT id FROM collections WHERE slug = ?', [$collectionSlug]);
        if (!$col) return 0;

        try {
            $row = OutpostDB::fetchOne(
                "SELECT COUNT(*) as c FROM compass_index WHERE collection_id = ? AND facet_name = ?",
                [(int) $col['id'], $fieldName]
            );
            return (int) ($row['c'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Get total published items in a collection.
     */
    function compass_tpl_total_count(string $collectionSlug): int {
        $col = OutpostDB::fetchOne('SELECT id FROM collections WHERE slug = ?', [$collectionSlug]);
        if (!$col) return 0;
        $row = OutpostDB::fetchOne(
            "SELECT COUNT(*) as c FROM collection_items WHERE collection_id = ? AND status = 'published'",
            [(int) $col['id']]
        );
        return (int) ($row['c'] ?? 0);
    }

    /**
     * Get top-level labels for a hierarchical folder.
     */
    function compass_tpl_hierarchy_top(string $collectionSlug, string $folderSlug): array {
        $col = OutpostDB::fetchOne('SELECT id FROM collections WHERE slug = ?', [$collectionSlug]);
        if (!$col) return [];
        $folder = OutpostDB::fetchOne(
            'SELECT id FROM folders WHERE collection_id = ? AND slug = ?',
            [(int) $col['id'], $folderSlug]
        );
        if (!$folder) return [];
        return OutpostDB::fetchAll(
            'SELECT slug, name FROM labels WHERE folder_id = ? AND parent_id IS NULL ORDER BY sort_order, name',
            [(int) $folder['id']]
        );
    }
}
