<?php

namespace App\Http\Controllers\People;

use App\Domain\People\PeopleAccess;
use App\Domain\People\RegisterHasher;
use App\Domain\People\Reports\PeopleReportKind;
use App\Domain\People\Reports\PeopleReports;
use App\Domain\People\Reports\RegisterFiles;
use App\Domain\People\Reports\RegisterScope;
use App\Domain\Reports\Delivery\ExportFormat;
use App\Http\Controllers\Controller;
use App\Http\Controllers\People\Concerns\HandlesRegisterFiles;
use App\Models\Department;
use App\Models\PeopleExport;
use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * «Informes» del registro (`/personas/informes`, PLAN-FASE-11 §6.3.2; D-351; W-089 a W-093), solo
 * para RR. HH. (`manage-people`): «Registro mensual de la jornada», «Anexo de horas», «Presencia
 * diaria», «Presencia mensual», «Fichajes» e «Incidencias». En pantalla (las primeras filas) y en
 * PDF, Excel y CSV, cada fichero con su huella y anotado en la auditoría. El ámbito: toda la
 * plantilla sujeta al registro (también quien ya no está), un departamento o unas personas.
 */
class RegisterReportController extends Controller
{
    use HandlesRegisterFiles;

    /** Filas que se ven en pantalla (el fichero lleva todas). */
    public const int PREVIEW_ROWS = 200;

    public function __construct(private readonly PeopleReports $reports) {}

    public function index(Request $request): Response
    {
        $kind = PeopleReportKind::tryFrom((string) $request->query('informe')) ?? PeopleReportKind::MonthlyRegister;
        $scope = $this->scope($request, $kind);
        $document = $this->reports->build($kind, $scope);
        $rows = $document->rows;

        return Inertia::render('people/reports', [
            'kinds' => array_map(fn (PeopleReportKind $item): array => ['value' => $item->value, 'title' => $item->title(), 'monthly' => $item->monthly()], PeopleReportKind::cases()),
            'kind' => $kind->value,
            'filters' => [
                'month' => substr($scope->from, 0, 7),
                'from' => $scope->from,
                'to' => $scope->to,
                'user_ids' => $this->userIds($request),
                'department_id' => $this->departmentId($request),
            ],
            'today' => LocalTime::todayString(),
            'people' => PeopleAccess::registerSubjects()->get(['id', 'name', 'is_active'])->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name, 'active' => $user->isActive()])->all(),
            'departments' => Department::query()->orderBy('name')->get(['id', 'name'])->map(fn (Department $department): array => ['id' => $department->id, 'name' => $department->name])->all(),
            'preview' => [
                'title' => $document->title,
                'headers' => $document->headers,
                'rows' => array_slice($rows, 0, self::PREVIEW_ROWS),
                'total' => count($rows),
                'content_hash' => RegisterHasher::contentHash($document->content()),
            ],
            'exports' => PeopleExport::query()
                ->with(['user:id,name', 'inspectionAccess:id,name'])
                ->latest('id')
                ->limit(15)
                ->get()
                ->map(fn (PeopleExport $export): array => self::export($export))
                ->all(),
        ]);
    }

    /** GET /personas/informes/{informe}?formato=pdf|xlsx|csv&mes=…|desde=…&hasta=…&personas[]=… */
    public function download(Request $request, string $report, RegisterFiles $files): HttpResponse
    {
        /** @var User $viewer */
        $viewer = $request->user();
        $kind = PeopleReportKind::tryFrom($report);
        abort_if($kind === null, 404);

        $format = ExportFormat::tryFrom((string) $request->query('formato')) ?? ExportFormat::Pdf;
        $scope = $this->scope($request, $kind);
        $document = $this->reports->build($kind, $scope);

        return $this->sendRegisterFile($files->write($document, $format, $scope->params(), $viewer));
    }

    /**
     * @return array<string, mixed>
     */
    public static function export(PeopleExport $export): array
    {
        return [
            'id' => $export->id,
            'kind' => $export->kind,
            'format' => $export->format,
            'filename' => $export->filename,
            'sha256' => $export->sha256,
            'content_hash' => $export->content_hash,
            'size' => $export->size,
            'by' => $export->user->name ?? ($export->inspectionAccess === null ? null : $export->inspectionAccess->name.' (Inspección)'),
            'created_at' => $export->created_at?->toIso8601ZuluString(),
        ];
    }

    private function scope(Request $request, PeopleReportKind $kind): RegisterScope
    {
        if ($kind->monthly()) {
            $month = $this->monthFrom($request, LocalTime::today()->startOfMonth()->subMonth());
            $from = $month->startOfMonth()->toDateString();
            $to = min($month->endOfMonth()->toDateString(), LocalTime::todayString());
        } else {
            [$from, $to] = $this->periodFrom($request);
        }

        $ids = $this->userIds($request);
        $departmentId = $this->departmentId($request);
        $users = array_values(PeopleAccess::registerSubjects()
            ->when($ids !== [], fn ($query) => $query->whereKey($ids))
            ->when($departmentId !== null, fn ($query) => $query->where('department_id', $departmentId))
            ->get()
            ->all());

        $label = match (true) {
            $ids !== [] => implode(', ', array_map(fn (User $user): string => $user->name, $users)),
            $departmentId !== null => (string) Department::query()->whereKey($departmentId)->value('name'),
            default => (string) __('people.reports.scope_all'),
        };

        return new RegisterScope($users, $from, $to, $label);
    }

    /**
     * @return list<int>
     */
    private function userIds(Request $request): array
    {
        $value = $request->query('personas');

        return is_array($value) ? array_values(array_unique(array_filter(array_map(intval(...), $value), fn (int $id): bool => $id > 0))) : [];
    }

    private function departmentId(Request $request): ?int
    {
        $value = $request->query('departamento');

        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }
}
