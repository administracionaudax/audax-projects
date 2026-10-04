<?php

namespace App\Http\Controllers\Reports;

use App\Domain\Integrations\Google\GoogleNotConnected;
use App\Domain\Integrations\Google\GoogleOAuth;
use App\Domain\Integrations\Google\GoogleSheetsUploader;
use App\Domain\Integrations\Google\GoogleUnavailable;
use App\Domain\Reports\Delivery\ExportFormat;
use App\Domain\Reports\Delivery\ReportFileGenerator;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReportRequestPayload;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * POST /informes/sheets (Fase 9, D-142): genera el XLSX del informe con los permisos de quien lo
 * pide (ReportFileGenerator, D-139; 403 si no puede verlo), lo sube a su Drive convertido a hoja
 * de cálculo de Google, borra el temporal y devuelve `{url}` para abrirla.
 *
 * Errores claros: sin conexión, 409 («Conecta tu cuenta de Google en Ajustes → Integraciones»);
 * Google caído, 502. Cada subida queda en la auditoría (report-delivery, evento «sheets»). Sin
 * credenciales de Google, 404: la opción no se ofrece.
 */
class SheetsExportController extends Controller
{
    public function __invoke(ReportRequestPayload $request, GoogleSheetsUploader $uploader): JsonResponse
    {
        abort_unless(GoogleOAuth::configured(), 404);

        /** @var User $user */
        $user = $request->user();

        // Antes de generar nada: sin conexión no hay a dónde subirlo.
        if (! $user->googleConnection()->exists()) {
            return $this->error(new GoogleNotConnected, 409);
        }

        $report = $request->reportRequest();
        // Se resuelve aquí, y no en la firma, para que sin credenciales o sin conexión no haga falta.
        $file = app(ReportFileGenerator::class)->generate($report, ExportFormat::Xlsx, $user);

        try {
            $url = $uploader->upload($user, $file, $file->title);
        } catch (GoogleNotConnected $exception) {
            return $this->error($exception, 409);
        } catch (GoogleUnavailable $exception) {
            report($exception);

            return $this->error($exception, 502);
        } finally {
            if (is_file($file->path)) {
                @unlink($file->path);
            }
        }

        activity('report-delivery')
            ->causedBy($user)
            ->event('sheets')
            ->withProperties([
                'kind' => $report->kind->value,
                'route_params' => $report->routeParams,
                'query' => $report->query,
                'title' => $file->title,
            ])
            ->log('report.sheets');

        return response()->json(['url' => $url]);
    }

    private function error(GoogleNotConnected|GoogleUnavailable $exception, int $status): JsonResponse
    {
        return response()->json(['message' => $exception->userMessage()], $status);
    }
}
