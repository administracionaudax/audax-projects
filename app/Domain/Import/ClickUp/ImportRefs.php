<?php

namespace App\Domain\Import\ClickUp;

use App\Models\ImportRef;

/**
 * Correspondencias de la importación (tabla import_refs, D-136), cargadas en memoria al empezar
 * para no consultar la base por cada objeto. (source, kind, external_id) → modelo local.
 */
final class ImportRefs
{
    /** @var array<string, array<string, int>> kind => external_id => local_id */
    private array $map = [];

    public function __construct(private readonly string $source) {}

    public function load(): void
    {
        $this->map = [];

        foreach (ImportRef::query()->where('source', $this->source)->toBase()->cursor() as $row) {
            /** @var object{kind: string, external_id: string, local_id: int|string} $row */
            $this->map[$row->kind][$row->external_id] = (int) $row->local_id;
        }
    }

    public function find(string $kind, string $externalId): ?int
    {
        return $this->map[$kind][$externalId] ?? null;
    }

    public function put(string $kind, string $externalId, string $localType, int $localId): void
    {
        if (($this->map[$kind][$externalId] ?? null) === $localId) {
            return;
        }

        $now = now();

        // El mapa en memoria refleja la tabla (se carga entera al empezar): si no está, no existe.
        if (! isset($this->map[$kind][$externalId])) {
            ImportRef::query()->insert([
                'source' => $this->source,
                'kind' => $kind,
                'external_id' => $externalId,
                'local_type' => $localType,
                'local_id' => $localId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            ImportRef::query()
                ->where(['source' => $this->source, 'kind' => $kind, 'external_id' => $externalId])
                ->update(['local_type' => $localType, 'local_id' => $localId, 'updated_at' => $now]);
        }

        $this->map[$kind][$externalId] = $localId;
    }

    /**
     * @return array<string, int> external_id => local_id
     */
    public function all(string $kind): array
    {
        return $this->map[$kind] ?? [];
    }
}
