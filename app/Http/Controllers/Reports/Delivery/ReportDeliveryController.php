<?php

namespace App\Http\Controllers\Reports\Delivery;

use App\Domain\Reports\Delivery\DeliveryRecipients;
use App\Domain\Reports\Delivery\DeliveryStatus;
use App\Domain\Reports\Delivery\ReportAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\SendReportRequest;
use App\Jobs\SendReportDelivery;
use App\Models\ReportDelivery;
use App\Models\ReportSchedule;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Enviar un informe por correo ahora (D-141): lo puede enviar quien puede ver el informe (403 si
 * no). El fichero se genera en la cola `mail` con los permisos de quien lo envía
 * (SendReportDelivery) y el envío queda en el historial y en la auditoría.
 */
class ReportDeliveryController extends Controller
{
    public function store(SendReportRequest $request, ReportAccess $access): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $report = $request->reportRequest();

        abort_unless($access->allows($report, $user), 403);

        $delivery = ReportDelivery::query()->create([
            'schedule_id' => null,
            'sender_user_id' => $user->id,
            'title' => (string) $request->validated('title'),
            'request' => $report->toArray(),
            'formats' => $request->formats(),
            'recipient_user_ids' => $request->recipientUserIds(),
            'recipient_emails' => $request->recipientEmails(),
            'subject' => $request->validated('subject'),
            'message' => $request->validated('message'),
            'status' => DeliveryStatus::Queued,
        ]);

        SendReportDelivery::dispatch($delivery->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('report_deliveries.flash.sent', $delivery->recipientCount(), ['count' => $delivery->recipientCount()])]);

        return back();
    }

    /**
     * Personas que pueden recibir informes (el buscador de destinatarios).
     */
    public function people(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('create', ReportSchedule::class) ?? false, 403);

        $people = DeliveryRecipients::eligibleUsers()->orderBy('name')->get(['id', 'name'])
            ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])
            ->values()
            ->all();

        return response()->json(['people' => $people]);
    }
}
