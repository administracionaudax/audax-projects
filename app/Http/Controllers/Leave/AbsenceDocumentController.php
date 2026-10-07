<?php

namespace App\Http\Controllers\Leave;

use App\Domain\Absences\AbsenceDocuments;
use App\Http\Controllers\Absences\AbsencesController;
use App\Models\Absence;
use App\Models\AbsenceDocument;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Justificantes (Fase 11, R3; W-071; D-368): subir (`POST /ausencias/{ausencia}/justificantes`),
 * descargar (`GET /ausencias/justificantes/{justificante}`) y borrar. Quién puede cada cosa lo
 * decide AbsenceDocuments (la persona, RR. HH. y, si el tipo no es de salud, su responsable).
 */
class AbsenceDocumentController extends AbsencesController
{
    public function __construct(private readonly AbsenceDocuments $documents) {}

    public function store(Request $request, Absence $absence): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(AbsenceDocuments::canUpload($user, $absence), 403);

        $request->validate([
            'file' => ['required', 'file', 'max:'.AbsenceDocuments::MAX_KILOBYTES, 'mimes:'.implode(',', AbsenceDocuments::MIMES)],
        ], [], (array) __('leave.attributes'));

        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);

        $this->documents->store($user, $absence, $file);
        $this->toast((string) __('leave.flash.document_uploaded'));

        return back();
    }

    public function show(Request $request, AbsenceDocument $document): Response
    {
        /** @var User $user */
        $user = $request->user();
        $contents = $this->documents->contents($user, $document);
        abort_if($contents === null, 404);

        return response($contents, 200, [
            'Content-Type' => $document->mime,
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $document->original_name, 'justificante-'.$document->id.'.'.pathinfo($document->path, PATHINFO_EXTENSION)),
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'X-Content-SHA256' => $document->sha256,
        ]);
    }

    public function destroy(Request $request, AbsenceDocument $document): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->documents->delete($user, $document);
        $this->toast((string) __('leave.flash.document_deleted'));

        return back();
    }
}
