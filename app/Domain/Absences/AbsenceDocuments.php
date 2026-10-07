<?php

namespace App\Domain\Absences;

use App\Domain\People\PeopleAccess;
use App\Enums\AbsenceStatus;
use App\Models\Absence;
use App\Models\AbsenceDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Justificantes de las ausencias (Fase 11, R3; W-071; L-24; D-368), en el disco privado y con el
 * acceso más restringido de la app (art. 9 RGPD: un justificante puede llevar datos de salud):
 *
 * - **Los ven y los descargan** la propia persona y RR. HH. (`manage-people`) y, solo si el tipo
 *   **no** es de salud, su responsable (quien aprueba sus ausencias), que los necesita para aprobar
 *   un permiso (el certificado de matrimonio, la citación…). De un tipo de salud, el responsable
 *   solo sabe si se ha entregado. Nadie más, nunca el equipo. Un admin sin `manage-people` tampoco.
 * - **Los sube** la persona (o RR. HH. por ella); los **borra** quien lo subió o RR. HH., mientras
 *   la ausencia no esté cancelada o rechazada (después quedan como estaban).
 * - Cada subida, descarga y borrado queda en la auditoría (`absence-documents`), sin el nombre del
 *   fichero (puede decir el motivo).
 * - PDF o imagen (JPG, PNG, WebP o HEIC), 10 MB como mucho y 5 por ausencia. Se guarda su SHA-256.
 */
final class AbsenceDocuments
{
    public const string DISK = 'local';

    public const int MAX_KILOBYTES = 10 * 1024;

    public const int MAX_PER_ABSENCE = 5;

    /** @var list<string> */
    public const array MIMES = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'heic'];

    public static function canView(User $viewer, Absence $absence): bool
    {
        if (! LeaveMode::on($viewer)) {
            return false;
        }

        if ($viewer->id === $absence->user_id || PeopleAccess::managesAll($viewer)) {
            return true;
        }

        $type = $absence->relationLoaded('leaveType') ? $absence->leaveType : $absence->leaveType()->first();
        $owner = $absence->relationLoaded('user') ? $absence->user : User::query()->find($absence->user_id);

        return $type !== null && ! $type->health_data && $owner !== null && $viewer->supervises($owner);
    }

    public static function canUpload(User $viewer, Absence $absence): bool
    {
        return LeaveMode::on($viewer)
            && in_array($absence->status, [AbsenceStatus::Requested, AbsenceStatus::Approved], true)
            && ($viewer->id === $absence->user_id || PeopleAccess::managesAll($viewer));
    }

    public static function canDelete(User $viewer, AbsenceDocument $document, Absence $absence): bool
    {
        return LeaveMode::on($viewer)
            && in_array($absence->status, [AbsenceStatus::Requested, AbsenceStatus::Approved], true)
            && ($viewer->id === $document->uploaded_by || PeopleAccess::managesAll($viewer));
    }

    /**
     * @throws ValidationException
     */
    public function store(User $actor, Absence $absence, UploadedFile $file): AbsenceDocument
    {
        abort_unless(self::canUpload($actor, $absence), 403);

        $document = DB::transaction(function () use ($actor, $absence, $file): AbsenceDocument {
            Absence::query()->whereKey($absence->id)->lockForUpdate()->value('id');

            if (AbsenceDocument::query()->where('absence_id', $absence->id)->count() >= self::MAX_PER_ABSENCE) {
                throw ValidationException::withMessages(['file' => __('leave.errors.too_many_documents', ['max' => self::MAX_PER_ABSENCE])]);
            }

            $extension = strtolower($file->getClientOriginalExtension() ?: ($file->extension() ?? 'bin'));
            $path = sprintf('people/justificantes/%d/%s.%s', $absence->user_id, Str::uuid()->toString(), $extension);
            $contents = (string) file_get_contents($file->getRealPath());
            Storage::disk(self::DISK)->put($path, $contents);

            $document = new AbsenceDocument;
            $document->forceFill([
                'absence_id' => $absence->id,
                'user_id' => $absence->user_id,
                'uploaded_by' => $actor->id,
                'path' => $path,
                'original_name' => mb_substr(self::cleanName($file->getClientOriginalName()), 0, 255),
                'mime' => (string) ($file->getMimeType() ?? 'application/octet-stream'),
                'size' => strlen($contents),
                'sha256' => hash('sha256', $contents),
                'created_at' => now(),
            ])->save();

            return $document;
        });

        $this->log($actor, $document, 'document_uploaded');

        return $document;
    }

    public function delete(User $actor, AbsenceDocument $document): void
    {
        $absence = $document->absence;
        abort_unless(self::canDelete($actor, $document, $absence), 403);

        DB::transaction(function () use ($actor, $document): void {
            $this->log($actor, $document, 'document_deleted');
            $document->delete();
        });

        Storage::disk(self::DISK)->delete($document->path);
    }

    /** Lo que se descarga (y lo anota en la auditoría). null si el fichero ya no está. */
    public function contents(User $actor, AbsenceDocument $document): ?string
    {
        abort_unless(self::canView($actor, $document->absence), 403);

        $contents = Storage::disk(self::DISK)->get($document->path);

        if ($contents === null) {
            return null;
        }

        $this->log($actor, $document, 'document_downloaded');

        return $contents;
    }

    /**
     * Las ausencias que piden justificante y aún no tienen ninguno (el informe «Justificantes
     * pendientes» y el aviso): aprobadas o pendientes, de tipos que lo piden.
     *
     * @param  list<int>|null  $userIds
     * @return Collection<int, Absence>
     */
    public static function missing(?array $userIds = null, ?string $from = null, ?string $to = null): Collection
    {
        return Absence::query()
            ->with(['user:id,name,department_id', 'leaveType'])
            ->whereIn('status', [AbsenceStatus::Requested->value, AbsenceStatus::Approved->value])
            ->whereHas('leaveType', fn ($query) => $query->where('requires_document', true))
            ->whereDoesntHave('documents')
            ->when($userIds !== null, fn ($query) => $query->whereIn('user_id', $userIds ?: [0]))
            ->when($from !== null, fn ($query) => $query->where('end_date', '>=', $from))
            ->when($to !== null, fn ($query) => $query->where('start_date', '<=', $to))
            ->orderBy('start_date')
            ->orderBy('id')
            ->get();
    }

    private static function cleanName(string $name): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', ' ', $name));

        return $name === '' ? 'justificante' : $name;
    }

    private function log(User $actor, AbsenceDocument $document, string $event): void
    {
        activity('absence-documents')
            ->causedBy($actor)
            ->performedOn($document)
            ->event($event)
            ->withProperties([
                'absence_id' => $document->absence_id,
                'user_id' => $document->user_id,
                'size' => $document->size,
                'sha256' => $document->sha256,
            ])
            ->log('leave.'.$event);
    }
}
