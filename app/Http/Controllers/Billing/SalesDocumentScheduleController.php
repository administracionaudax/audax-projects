<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\Issuing\DocumentActions;
use App\Domain\Billing\Recurring\ScheduledInvoices;
use App\Http\Controllers\Controller;
use App\Models\SalesDocument;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Programar la emisión de un borrador (o cambiar la fecha) y desprogramarlo (PLAN-EMISION §6.2; E3).
 * La matriz DocumentActions lo da a quien emite (manage-billing): se emitirá en su nombre.
 */
class SalesDocumentScheduleController extends Controller
{
    public function store(Request $request, SalesDocument $document, ScheduledInvoices $scheduled): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(DocumentActions::allows($document, $user, 'schedule'), 403);
        $data = $request->validate([
            'scheduled_for' => ['required', 'date_format:Y-m-d'],
            'scheduled_send' => ['boolean'],
        ]);

        $date = CarbonImmutable::parse((string) $data['scheduled_for']);
        $send = $request->boolean('scheduled_send');
        $scheduled->schedule($document, $date, $send, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __($send ? 'invoicing_work.scheduled.flash.scheduled_send' : 'invoicing_work.scheduled.flash.scheduled', ['date' => $date->format('d/m/Y')])]);

        return redirect($document->url());
    }

    public function destroy(Request $request, SalesDocument $document, ScheduledInvoices $scheduled): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(DocumentActions::allows($document, $user, 'unschedule'), 403);

        $scheduled->unschedule($document, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('invoicing_work.scheduled.flash.unscheduled')]);

        return redirect($document->url());
    }
}
