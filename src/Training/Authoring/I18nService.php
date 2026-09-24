<?php

namespace ITFlow\Training\Authoring;

use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;

/**
 * Non-default-language text for courses, sections, quizzes, paths and achievements
 * (training_i18n). The entity's own columns hold its default language (a course's
 * course_default_language; paths and achievements use the first configured language); every
 * other language lives here, one row per (entity, id, lang, field). Lessons and questions do
 * not use this table - they have symmetric per-language tables.
 *
 * An empty value deletes the row, so "no translation" is always "no row".
 */
final class I18nService
{
    public const FIELDS = [
        'course' => ['name', 'summary', 'description_html', 'attestation_text'],
        'section' => ['title'],
        'quiz' => ['intro'],
        'path' => ['name', 'description'],
        'achievement' => ['name', 'description'],
    ];

    public function __construct(private readonly Ctx $c)
    {
    }

    /** Sets (or, for null/'', clears) one translated field. Returns whether anything changed. */
    public function set(string $entity, int $id, string $lang, string $field, ?string $value): bool
    {
        self::assertField($entity, $field);
        if (preg_match('/^[a-z]{2}$/', $lang) !== 1) {
            throw new \InvalidArgumentException("I18nService: bad language '$lang'");
        }
        $current = Db::one(
            $this->c->db,
            'SELECT ti18n_value FROM training_i18n WHERE ti18n_entity = ? AND ti18n_entity_id = ? AND ti18n_lang = ? AND ti18n_field = ? FOR UPDATE',
            'siss',
            [$entity, $id, $lang, $field]
        );
        $value = ($value === null || trim($value) === '') ? null : $value;
        if ($value === null) {
            if ($current === null) {
                return false;
            }
            Db::exec(
                $this->c->db,
                'DELETE FROM training_i18n WHERE ti18n_entity = ? AND ti18n_entity_id = ? AND ti18n_lang = ? AND ti18n_field = ?',
                'siss',
                [$entity, $id, $lang, $field]
            );
            return true;
        }
        if ($current !== null && (string) $current['ti18n_value'] === $value) {
            return false;
        }
        Db::exec(
            $this->c->db,
            'INSERT INTO training_i18n (ti18n_entity, ti18n_entity_id, ti18n_lang, ti18n_field, ti18n_value, ti18n_updated_by)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE ti18n_value = VALUES(ti18n_value), ti18n_updated_by = VALUES(ti18n_updated_by)',
            'sisssi',
            [$entity, $id, $lang, $field, $value, $this->c->userId]
        );
        return true;
    }

    /** @return array<string, array<string, string>> lang => field => value */
    public function forEntity(string $entity, int $id): array
    {
        return $this->forEntities($entity, [$id])[$id] ?? [];
    }

    /**
     * Batch read.
     *
     * @param list<int> $ids
     * @return array<int, array<string, array<string, string>>> id => lang => field => value
     */
    public function forEntities(string $entity, array $ids): array
    {
        self::assertEntity($entity);
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $rows = Db::all(
                $this->c->db,
                'SELECT ti18n_entity_id, ti18n_lang, ti18n_field, ti18n_value FROM training_i18n
                 WHERE ti18n_entity = ? AND ti18n_entity_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')
                 ORDER BY ti18n_entity_id, ti18n_lang, ti18n_field',
                's' . str_repeat('i', count($chunk)),
                array_merge([$entity], $chunk)
            );
            foreach ($rows as $r) {
                if (!in_array($r['ti18n_field'], self::FIELDS[$entity], true)) {
                    continue;
                }
                $out[(int) $r['ti18n_entity_id']][(string) $r['ti18n_lang']][(string) $r['ti18n_field']] = (string) $r['ti18n_value'];
            }
        }
        return $out;
    }

    /** Removes every translation of the given entities (used when the entity itself is deleted). */
    public function deleteFor(string $entity, array $ids): void
    {
        self::assertEntity($entity);
        $ids = array_values(array_unique(array_map('intval', $ids)));
        foreach (array_chunk($ids, 500) as $chunk) {
            Db::exec(
                $this->c->db,
                'DELETE FROM training_i18n WHERE ti18n_entity = ? AND ti18n_entity_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')',
                's' . str_repeat('i', count($chunk)),
                array_merge([$entity], $chunk)
            );
        }
    }

    /** Copies every translation of one entity to another (duplicates). */
    public function copy(string $entity, int $fromId, int $toId): void
    {
        foreach ($this->forEntity($entity, $fromId) as $lang => $fields) {
            foreach ($fields as $field => $value) {
                $this->set($entity, $toId, (string) $lang, (string) $field, $value);
            }
        }
    }

    private static function assertEntity(string $entity): void
    {
        if (!isset(self::FIELDS[$entity])) {
            throw new \InvalidArgumentException("I18nService: unknown entity '$entity'");
        }
    }

    private static function assertField(string $entity, string $field): void
    {
        self::assertEntity($entity);
        if (!in_array($field, self::FIELDS[$entity], true)) {
            throw new \InvalidArgumentException("I18nService: '$field' is not a translatable $entity field");
        }
    }
}
