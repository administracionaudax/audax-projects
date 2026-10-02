<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditCatalog;
use App\Domain\Audit\AuditEntries;
use App\Domain\Audit\AuditFilters;
use App\Domain\Audit\AuditLog;
use App\Domain\Reports\Export\TableExporter;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Auditoría visible (SPEC §14 y §15, D-074): /admin/auditoria con filtros en la URL (entidad,
 * persona, acción y fechas), paginación por cursor y el antes y el después de cada cambio, y
 * /admin/auditoria/exportar con los mismos filtros a CSV, en streaming y con el límite de filas de
 * las exportaciones de los informes (D-045). Solo el admin (role:admin en la ruta y aquí).
 * Los campos económicos se muestran: solo lo ve el admin.
 */
class AuditController extends Controller
{
    /** Entradas por bloque de la exportación (una consulta de nombres por tipo y bloque). */
    public const int CHUNK = 500;

    /**
     * @param  int  $maxRows  Filas como máximo, contando el aviso final (los tests lo reducen).
     */
    public function __construct(
        private readonly AuditLog $log,
        private readonly AuditEntries $entries,
        private readonly TableExporter $exporter,
        private readonly int $maxRows = TableExporter::MAX_ROWS,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->isAdmin() === true, 403);

        $people = $this->people();
        $filters = $this->filters($request, $people);

        $page = $this->log->query($filters)
            ->cursorPaginate(AuditLog::PER_PAGE)
            ->withPath(route('admin.audit.index', [], false))
            ->withQueryString();

        return Inertia::render('admin/audit', [
            'entries' => $this->entries->present($page->getCollection()),
            'pagination' => [
                'next' => $page->nextPageUrl(),
                'prev' => $page->previousPageUrl(),
            ],
            'filters' => $filters->toArray(),
            'options' => [
                'entities' => AuditCatalog::entityOptions(),
                'actions' => AuditCatalog::actionOptions(),
                'people' => array_values($people),
            ],
            'exportUrl' => route('admin.audit.export', $filters->query(), false),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->isAdmin() === true, 403);

        $filters = $this->filters($request, $this->people());
        $headers = array_map(
            fn (string $column): string => self::text("audit.export.columns.{$column}"),
            ['date', 'person', 'entity', 'subject', 'action', 'changes'],
        );

        return $this->exporter->download(self::text('audit.export.filename'), $headers, $this->rows($this->log->query($filters)), 'csv');
    }

    /**
     * Filas del CSV por bloques de id descendente (sin cargar toda la auditoría a la vez).
     *
     * @param  Builder<Activity>  $query
     * @return Generator<int, array<int, string|null>>
     */
    private function rows(Builder $query): Generator
    {
        $limit = min($this->maxRows, TableExporter::MAX_ROWS) - 1;
        $written = 0;
        $lastId = null;

        while (true) {
            $chunk = (clone $query)
                ->when($lastId !== null, fn (Builder $builder) => $builder->where('id', '<', $lastId))
                ->limit(self::CHUNK)
                ->get();

            if ($chunk->isEmpty()) {
                return;
            }

            foreach ($this->entries->present($chunk) as $entry) {
                if ($written === $limit) {
                    // La última fila avisa de que hay más (como las exportaciones de los informes).
                    yield [self::text('audit.export.truncated', ['count' => $limit])];

                    return;
                }

                $written++;

                yield [
                    $entry['created_at'] !== null ? CarbonImmutable::parse($entry['created_at'])->setTimezone(LocalTime::timezone())->format('d/m/Y H:i') : null,
                    $entry['causer']['name'] ?? self::text('audit.system'),
                    $entry['entity']['label'],
                    $entry['subject']['label'] ?? null,
                    $entry['event_label'],
                    AuditEntries::summary($entry['changes']),
                ];
            }

            $lastId = (int) $chunk->last()->id;

            if ($chunk->count() < self::CHUNK) {
                return;
            }
        }
    }

    /**
     * Filtros de la URL; una persona que no está en el selector se ignora como cualquier otro
     * valor que no se entiende.
     *
     * @param  array<int, array{id: int, name: string, is_active: bool}>  $people
     */
    private function filters(Request $request, array $people): AuditFilters
    {
        $filters = AuditFilters::fromRequest($request);

        return is_int($filters->person) && ! isset($people[$filters->person]) ? $filters->withoutPerson() : $filters;
    }

    /**
     * Personas del filtro «persona»: la plantilla (también las desactivadas, que siguen en la
     * auditoría), por nombre.
     *
     * @return array<int, array{id: int, name: string, is_active: bool}>
     */
    private function people(): array
    {
        $people = [];

        foreach (User::query()->internal()->orderBy('name')->orderBy('id')->get(['id', 'name', 'is_active']) as $user) {
            $people[$user->id] = ['id' => $user->id, 'name' => $user->name, 'is_active' => $user->is_active];
        }

        return $people;
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    private static function text(string $key, array $replace = []): string
    {
        $line = __($key, $replace);

        return is_string($line) ? $line : $key;
    }
}
