<?php

namespace App\Http\Controllers\Privacy;

use App\Domain\Privacy\PersonalDataExporter;
use App\Domain\Privacy\PersonalDataExportList;
use App\Domain\Privacy\RetentionPolicy;
use App\Http\Controllers\Controller;
use App\Models\PersonalDataExport;
use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exportación de los datos personales (SPEC §15, D-075):
 * - /ajustes/mis-datos: las exportaciones propias y «Preparar mis datos» (una en curso como mucho),
 * - /datos-personales/{export}/descargar: con URL firmada (relativa, hasta que caduca) Y la política
 *   (la propia persona o un admin). Marca la descarga y la deja en la auditoría.
 */
class PersonalDataExportController extends Controller
{
    public function __construct(
        private readonly PersonalDataExporter $exporter,
        private readonly PersonalDataExportList $list,
    ) {}

    public function index(Request $request, RetentionPolicy $policy): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('settings/my-data', [
            'exports' => $this->list->for($user),
            'canRequest' => ! PersonalDataExporter::inProgress($user),
            'exportDays' => $policy->exportDays(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->exporter->request($user, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('privacy.exports.requested_own')]);

        return back();
    }

    public function download(Request $request, PersonalDataExport $export): StreamedResponse
    {
        Gate::authorize('download', $export);

        /** @var User $user */
        $user = $request->user();
        $disk = Storage::disk($export->disk);

        abort_unless($export->isDownloadable() && $export->path !== null && $disk->exists($export->path), 404);

        $this->exporter->recordDownload($export, $user);

        $export->loadMissing(['subject' => fn ($query) => $query->select(['id', 'name'])]);
        $name = Str::slug(__('privacy.exports.filename', ['name' => $export->subject->name]), '-', 'es').'-'.LocalTime::todayString().'.zip';

        return $disk->download($export->path, $name, [
            'Content-Type' => 'application/zip',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
