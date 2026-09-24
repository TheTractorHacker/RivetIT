<?php

namespace ITFlow\Training\Publish;

use ITFlow\Training\Media\ArticleMediaRefs;

/**
 * Collects every media id a revision references, with its downloadable flag (true wins when the
 * same file is referenced twice) and where it is used (for validator messages).
 * Used by RevisionBuilder.
 */
final class MediaRefCollector
{
    /** @var array<int, array{dl:bool, where:list<string>}> */
    private array $refs = [];

    public function add(int $id, bool $dl, string $where): void
    {
        if ($id <= 0) {
            return;
        }
        if (!isset($this->refs[$id])) {
            $this->refs[$id] = ['dl' => $dl, 'where' => [$where]];
            return;
        }
        $this->refs[$id]['dl'] = $this->refs[$id]['dl'] || $dl;
        if (!in_array($where, $this->refs[$id]['where'], true)) {
            $this->refs[$id]['where'][] = $where;
        }
    }

    /** Images and file links embedded in purified HTML (ArticleMediaRefs::extract). */
    public function addHtml(string $html, string $where): void
    {
        foreach (ArticleMediaRefs::extract($html) as $ref) {
            $id = is_array($ref) ? (int) ($ref['media_id'] ?? $ref['id'] ?? 0) : (int) $ref;
            $this->add($id, false, $where);
        }
    }

    /** @return list<int> */
    public function ids(): array
    {
        return array_keys($this->refs);
    }

    /** @return array<int, array{dl:bool, where:list<string>}> sorted by id */
    public function all(): array
    {
        $r = $this->refs;
        ksort($r, SORT_NUMERIC);
        return $r;
    }
}
