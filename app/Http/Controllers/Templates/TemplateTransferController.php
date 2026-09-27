<?php

namespace App\Http\Controllers\Templates;

use App\Domain\Templates\ProjectTemplateService;
use App\Domain\Templates\TemplateTransfer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Templates\ImportTemplateRequest;
use App\Models\ProjectTemplate;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * Exportar una plantilla a un fichero JSON y crear otra importando uno (D-058, para copias). La
 * importada se abre en el editor para revisarla. Solo un admin.
 */
class TemplateTransferController extends Controller
{
    public function export(ProjectTemplate $template, TemplateTransfer $transfer): JsonResponse
    {
        Gate::authorize('export', $template);

        $filename = __('templates.export.filename', ['slug' => Str::slug($template->name) ?: 'plantilla']);

        return response()->json(
            $transfer->export($template),
            200,
            ['Content-Disposition' => 'attachment; filename="'.(is_string($filename) ? $filename : 'plantilla.json').'"'],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    public function import(ImportTemplateRequest $request, TemplateTransfer $transfer): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var UploadedFile $file */
        $file = $request->file('file');

        $fallback = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $default = __('templates.import.fallback_name');
        $data = $transfer->parse((string) $file->get(), $fallback !== '' ? $fallback : (is_string($default) ? $default : 'Plantilla'));

        $template = ProjectTemplate::query()->create([
            'name' => $this->freeName($data['name']),
            'description' => $data['description'],
            'structure' => $data['structure'],
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('templates.flash.imported', [
            'name' => $template->name,
            'count' => ProjectTemplateService::stats($template->structure)['tasks'],
        ])]);

        return to_route('templates.edit', $template);
    }

    /**
     * Si ya hay una plantilla (fuera de la papelera) con ese nombre, «Nombre (2)», «Nombre (3)»…
     */
    private function freeName(string $name): string
    {
        $taken = ProjectTemplate::query()->pluck('name')->map(fn ($value): string => mb_strtolower((string) $value))->all();

        if (! in_array(mb_strtolower($name), $taken, true)) {
            return $name;
        }

        for ($n = 2; ; $n++) {
            $candidate = __('templates.import.copy_suffix', ['name' => $name, 'n' => $n]);
            $candidate = is_string($candidate) ? $candidate : "{$name} ({$n})";

            if (! in_array(mb_strtolower($candidate), $taken, true)) {
                return mb_substr($candidate, 0, 255);
            }
        }
    }
}
