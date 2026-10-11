<?php

namespace App\Domain\Billing\Recurring;

use App\Domain\Billing\Issuing\InvoicingAccess;
use App\Models\SalesDocument;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Facturas programadas (PLAN-EMISION §3.1 y §6.2; E3): un borrador con una fecha de emisión futura
 * que se emite solo ese día (`app:billing-issue-scheduled`, por la mañana) y, si se eligió, se envía
 * al cliente. Sigue siendo un borrador hasta entonces: se edita, se reprograma, se desprograma o se
 * emite antes («Emitir ahora»).
 *
 * - Programar es emitir más tarde: hace falta manage-billing, y se emite en nombre de quien la
 *   programó (si ya no puede emitir, no se emite y lo dice).
 * - La fecha de la factura pasa a ser la programada (y el vencimiento se mueve lo mismo). Si la
 *   tarea corre después (un día sin servidor), se emite con la fecha de ese día.
 * - **Solo con la emisión encendida de verdad** (D-239): en modo de prueba no se emite nada sola.
 * - Si no se puede emitir (falta un dato, la serie no ha empezado…), sigue programada con el motivo
 *   y se vuelve a intentar al día siguiente; sale en «Requiere atención» del Resumen.
 */
final class ScheduledInvoices
{
    public function __construct(private readonly AutoIssue $auto) {}

    /**
     * @throws ValidationException
     */
    public function schedule(SalesDocument $document, CarbonImmutable $date, bool $send, User $by): void
    {
        if (! $document->isDraft()) {
            throw ValidationException::withMessages(['scheduled_for' => __('invoicing.errors.not_draft')]);
        }
        if ($date->toDateString() <= LocalTime::todayString()) {
            throw ValidationException::withMessages(['scheduled_for' => __('invoicing_work.scheduled.errors.future')]);
        }

        $this->moveDates($document, $date);
        $document->forceFill([
            'scheduled_for' => $date->toDateString(),
            'scheduled_send' => $send,
            'scheduled_by' => $by->id,
            'auto_issue_error' => null,
            'updated_by' => $by->id,
        ])->save();

        activity('invoicing')
            ->causedBy($by)
            ->performedOn($document)
            ->event('scheduled')
            ->withProperties(['date' => $date->toDateString(), 'send' => $send])
            ->log('invoicing.scheduled');
    }

    public function unschedule(SalesDocument $document, User $by): void
    {
        if (! $document->isScheduled()) {
            return;
        }

        $document->forceFill(['scheduled_for' => null, 'scheduled_send' => false, 'scheduled_by' => null, 'auto_issue_error' => null, 'updated_by' => $by->id])->save();

        activity('invoicing')
            ->causedBy($by)
            ->performedOn($document)
            ->event('unscheduled')
            ->log('invoicing.unscheduled');
    }

    /**
     * La tarea de cada mañana: emite (y envía) las programadas para hoy o antes.
     *
     * @return array{issued: int, failed: int}
     */
    public function runDue(?CarbonImmutable $today = null): array
    {
        $today ??= LocalTime::today();
        $result = ['issued' => 0, 'failed' => 0];

        $due = SalesDocument::query()->where('status', 'draft')->whereNotNull('scheduled_for')
            ->whereDate('scheduled_for', '<=', $today->toDateString())
            ->orderBy('scheduled_for')->orderBy('id')
            ->get();

        foreach ($due as $document) {
            $by = $document->scheduled_by !== null ? User::query()->find($document->scheduled_by) : null;
            if ($document->issue_date->toDateString() !== $today->toDateString()) {
                $this->moveDates($document, $today);
                $document->save();
            }

            $issued = $this->auto->issue($document, $by);
            if (is_string($issued)) {
                $result['failed']++;

                continue;
            }

            SalesDocument::query()->whereKey($issued->id)->update(['auto_issue_error' => null]);
            $result['issued']++;
            if ($document->scheduled_send && $by !== null) {
                $this->auto->send($issued, $by);
            }
        }

        return $result;
    }

    /** ¿Lo puede programar? (manage-billing: es emitir más tarde). */
    public static function allowed(?User $user): bool
    {
        return InvoicingAccess::manages($user);
    }

    /** La fecha de la factura pasa a la nueva y el vencimiento se mueve los mismos días. */
    private function moveDates(SalesDocument $document, CarbonImmutable $date): void
    {
        $days = (int) $document->issue_date->diffInDays($date, false);
        $document->forceFill([
            'issue_date' => $date->toDateString(),
            'due_date' => $document->due_date?->addDays($days)->toDateString(),
        ]);
    }
}
