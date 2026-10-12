<?php
/**
 * Department and category repository.
 *
 * @package WPCalibrate\SupportDesk\Repositories
 */

declare(strict_types=1);

namespace WPCalibrate\SupportDesk\Repositories;

use WPCalibrate\SupportDesk\Database\SchemaManager;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class CategoryRepository
 */
final class CategoryRepository
{
    /**
     * Get all categories.
     *
     * @param bool $activeOnly
     * @return array<int, array<string, mixed>>
     */
    public function getAll(bool $activeOnly = false): array
    {
        global $wpdb;
        $tables = SchemaManager::getTableNames();

        $where = $activeOnly ? 'WHERE is_active = 1' : '';
        $sql = "SELECT * FROM {$tables['categories']} {$where} ORDER BY sort_order ASC, name ASC";

        return $wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    /**
     * Get single category by ID.
     *
     * @param int $id
     * @return array<string, mixed>|null
     */
    public function getById(int $id): ?array
    {
        global $wpdb;
        $tables = SchemaManager::getTableNames();

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$tables['categories']} WHERE id = %d", $id),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Create category.
     *
     * @param array<string, mixed> $data
     * @return int|false
     */
    public function create(array $data): int|false
    {
        global $wpdb;
        $tables = SchemaManager::getTableNames();
        $now = current_time('mysql', true);

        $name = sanitize_text_field((string) $data['name']);
        $slug = sanitize_title((string) ($data['slug'] ?? $name));

        // Ensure unique slug.
        $slug = $this->generateUniqueSlug($slug);

        $record = [
            'name'             => $name,
            'slug'             => $slug,
            'description'      => !empty($data['description']) ? sanitize_textarea_field((string) $data['description']) : null,
            'is_active'        => !empty($data['is_active']) ? 1 : 0,
            'sort_order'       => (int) ($data['sort_order'] ?? 0),
            'default_agent_id' => !empty($data['default_agent_id']) ? (int) $data['default_agent_id'] : null,
            'created_at'       => $now,
            'updated_at'       => $now,
        ];

        $formats = ['%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s'];
        $success = $wpdb->insert($tables['categories'], $record, $formats);

        return $success ? (int) $wpdb->insert_id : false;
    }

    /**
     * Update category.
     *
     * @param int $id
     * @param array<string, mixed> $data
     * @return bool
     */
    public function update(int $id, array $data): bool
    {
        global $wpdb;
        $tables = SchemaManager::getTableNames();
        $now = current_time('mysql', true);

        $record = ['updated_at' => $now];
        $formats = ['%s'];

        if (isset($data['name'])) {
            $record['name'] = sanitize_text_field((string) $data['name']);
            $formats[] = '%s';
        }
        if (isset($data['slug'])) {
            $record['slug'] = $this->generateUniqueSlug(sanitize_title((string) $data['slug']), $id);
            $formats[] = '%s';
        }
        if (isset($data['description'])) {
            $record['description'] = sanitize_textarea_field((string) $data['description']);
            $formats[] = '%s';
        }
        if (isset($data['is_active'])) {
            $record['is_active'] = !empty($data['is_active']) ? 1 : 0;
            $formats[] = '%d';
        }
        if (isset($data['sort_order'])) {
            $record['sort_order'] = (int) $data['sort_order'];
            $formats[] = '%d';
        }
        if (array_key_exists('default_agent_id', $data)) {
            $record['default_agent_id'] = !empty($data['default_agent_id']) ? (int) $data['default_agent_id'] : null;
            $formats[] = !empty($data['default_agent_id']) ? '%d' : null;
        }

        $result = $wpdb->update($tables['categories'], $record, ['id' => $id], $formats, ['%d']);
        return $result !== false;
    }

    /**
     * Delete category with safe reassignment of existing tickets.
     *
     * @param int $id Category to delete.
     * @param int $reassignTo Category ID to reassign tickets to.
     * @return bool
     */
    public function delete(int $id, int $reassignTo = 1): bool
    {
        global $wpdb;
        $tables = SchemaManager::getTableNames();

        // If target reassign category is the same or invalid, find first remaining.
        if ($id === $reassignTo) {
            $altCat = (int) $wpdb->get_var(
                $wpdb->prepare("SELECT id FROM {$tables['categories']} WHERE id != %d LIMIT 1", $id)
            );
            $reassignTo = $altCat > 0 ? $altCat : 1;
        }

        // Reassign historical tickets safely so no references break.
        $wpdb->query(
            $wpdb->prepare("UPDATE {$tables['tickets']} SET category_id = %d WHERE category_id = %d", $reassignTo, $id)
        );

        $deleted = $wpdb->delete($tables['categories'], ['id' => $id], ['%d']);
        return (bool) $deleted;
    }

    /**
     * Helper to guarantee unique slug.
     *
     * @param string $slug
     * @param int $excludeId
     * @return string
     */
    private function generateUniqueSlug(string $slug, int $excludeId = 0): string
    {
        global $wpdb;
        $tables = SchemaManager::getTableNames();

        $original = $slug;
        $counter = 1;

        while (true) {
            $existing = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$tables['categories']} WHERE slug = %s AND id != %d",
                    $slug,
                    $excludeId
                )
            );

            if ($existing === 0) {
                return $slug;
            }

            $slug = $original . '-' . $counter;
            $counter++;
        }
    }
}
