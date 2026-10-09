<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\InvoiceList;
use App\Domain\Billing\Issuing\DocumentActions;
use App\Domain\Billing\Issuing\DraftWriter;
use App\Domain\Billing\Issuing\EditorOptions;
use App\Domain\Billing\Issuing\InvoiceCorrections;
use App\Domain\Billing\Issuing\InvoiceIssuer;
use App\Domain\Billing\Issuing\InvoicePdf;
use App\Domain\Billing\Issuing\SalesDocumentPresenter;
use App\Enums\SalesDocumentStatus;
use App\Enums\SalesDocumentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\SalesDocumentRequest;
use App\Models\BillingDocument;
use App\Models\HoldedInvoice;
use App\Models\Project;
use App\Models\SalesDocument;
use App\Models\SalesDocumentLink;
use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Facturas propias (PLAN-EMISION E1, §6.2; D-417 a D-428): el editor (nueva y borrador), la ficha,
 * su PDF y las acciones de la matriz (DocumentActions): emitir, duplicar, eliminar un borrador,
 * anular, rectificar, anular el registro y lo no fiscal (nota interna, proyecto y bolsa).
 * Todo detrás de los módulos billing e invoicing y de use-invoicing (en la ruta); emitir, anular y
 * rectificar, además manage-billing; anular el registro, un admin.
 */
class SalesDocumentController extends Controller
{
    public function create(Request $request): Response
    {
        $client = $request->integer('cliente') ?: null;
        $project = $request->integer('proyecto') ?: null;
        if ($project !== null) {
            $client ??= Project::query()->whereKey($project)->value('client_id');
        }
        $options = EditorOptions::all();
        $default = Arr::first($options['payment_methods'], fn (array $method): bool => $method['is_default']);

        return Inertia::render('billing/documents/edit', [
            'document' => null,
            'defaults' => [
                'client_id' => $client,
                'project_id' => $project,
                'series_id' => DraftWriter::defaultSeries(SalesDocumentType::Invoice, LocalTime::today())?->id,
                'issue_date' => LocalTime::today()->toDateString(),
                'payment_method_id' => $default['id'] ?? null,
            ],
            'options' => $options,
            'preview_token' => csrf_token(),
        ]);
    }

    public function store(SalesDocumentRequest $request, DraftWriter $writer): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $document = $writer->save(null, $request->draft(), $user); // @phpstan-ignore argument.type

        return $this->afterSave($request, $document);
    }

    public function edit(SalesDocument $document): Response|RedirectResponse
    {
        if (! $document->isDraft()) {
            return redirect($document->url());
        }

        return Inertia::render('billing/documents/edit', [
            'document' => SalesDocumentPresenter::form($document),
            'defaults' => null,
            'options' => EditorOptions::all(),
            'preview_token' => csrf_token(),
        ]);
    }

    public function update(SalesDocumentRequest $request, SalesDocument $document, DraftWriter $writer): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $writer->save($document, $request->draft(), $user); // @phpstan-ignore argument.type

        return $this->afterSave($request, $document);
    }

    private function afterSave(SalesDocumentRequest $request, SalesDocument $document): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => __('invoicing.flash.saved')]);

        return redirect($document->url().($request->input('after') === 'emitir' ? '?emitir=1' : ''));
    }

    public function show(Request $request, SalesDocument $document, InvoiceIssuer $issuer): Response
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array<string, mixed> $input */
        $input = $request->query();
        $list = InvoiceList::fromQuery($input);
        $neighbours = $list->neighbours(-$document->id);
        $query = $list->toQuery();
        $actions = DocumentActions::forDocument($document, $user);

        return Inertia::render('billing/documents/show', [
            'document' => SalesDocumentPresenter::detail($document),
            'actions' => $actions,
            'issue' => in_array('issue', $actions, true) ? $issuer->preview($document) : null,
            'void_problems' => in_array('void', $actions, true) ? InvoiceCorrections::voidProblems($document) : [],
            'projects' => in_array('edit_non_fiscal', $actions, true) ? EditorOptions::projects() : [],
            'open_issue' => $request->boolean('emitir') && in_array('issue', $actions, true),
            'today' => LocalTime::today()->toDateString(),
            'list' => [
                'back' => '/facturacion/facturas'.self::queryString([...$query, ...($neighbours['page'] > 1 ? ['pagina' => $neighbours['page']] : [])]),
                'previous' => $neighbours['previous'] === null ? null : BillingDocument::urlFor($neighbours['previous']).self::queryString($query),
                'next' => $neighbours['next'] === null ? null : BillingDocument::urlFor($neighbours['next']).self::queryString($query),
                'position' => $neighbours['position'],
                'total' => $neighbours['total'],
            ],
        ]);
    }

    /**
     * El PDF: el archivado de una emitida (generándolo si aún no está) o la vista previa de un
     * borrador, con la marca «Borrador».
     */
    public function pdf(Request $request, SalesDocument $document, InvoicePdf $pdf): SymfonyResponse
    {
        $content = $document->isDraft() ? $pdf->render($document) : $pdf->archive($document);
        $extension = $document->pdf_path !== null ? pathinfo($document->pdf_path, PATHINFO_EXTENSION) : $pdf->extension();

        return self::file($content, $document->pdfFilename($extension), $extension === 'pdf' ? 'application/pdf' : 'text/html; charset=UTF-8', $request->boolean('descargar'));
    }

    /**
     * Vista previa del editor sin guardar (D-428): el formulario tal cual, como un borrador que no se
     * guarda (nada se escribe: la transacción se deshace).
     */
    public function preview(SalesDocumentRequest $request, DraftWriter $writer, InvoicePdf $pdf): SymfonyResponse
    {
        /** @var User $user */
        $user = $request->user();
        $content = '';

        DB::beginTransaction();
        try {
            $document = $writer->save(null, $request->draft(), $user); // @phpstan-ignore argument.type
            $content = $pdf->render($document->fresh() ?? $document);
        } finally {
            DB::rollBack();
        }

        return self::file($content, 'vista-previa.'.$pdf->extension(), $pdf->mime(), false);
    }

    public function destroy(Request $request, SalesDocument $document): RedirectResponse
    {
        abort_unless(DocumentActions::allows($document, $request->user(), 'delete'), 403);

        $document->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => __('invoicing.flash.deleted')]);

        return redirect('/facturacion/facturas?vista=borradores');
    }

    public function issue(Request $request, SalesDocument $document, InvoiceIssuer $issuer): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(DocumentActions::allows($document, $user, 'issue'), 403);

        $issued = $issuer->issue($document, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('invoicing.flash.issued', ['number' => $issued->full_number])]);

        return redirect($issued->url());
    }

    public function duplicate(Request $request, SalesDocument $document, DraftWriter $writer): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(DocumentActions::allows($document, $user, 'duplicate'), 403);

        $copy = $writer->duplicate($document, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('invoicing.flash.duplicated')]);

        return redirect('/facturacion/documentos/'.$copy->id.'/editar');
    }

    /** Duplicar una factura de Holded como borrador propio (el mes en paralelo, §7.3). */
    public function duplicateHolded(Request $request, HoldedInvoice $invoice, DraftWriter $writer): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $copy = $writer->duplicate($invoice, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('invoicing.flash.duplicated')]);

        return redirect('/facturacion/documentos/'.$copy->id.'/editar');
    }

    public function cancel(Request $request, SalesDocument $document, InvoiceCorrections $corrections): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(DocumentActions::allows($document, $user, 'cancel'), 403);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'code' => ['nullable', Rule::in(InvoiceCorrections::CODES)],
        ]);

        $credit = $corrections->cancel($document, $user, $data['reason'], $data['code'] ?? 'R1');
        Inertia::flash('toast', ['type' => 'success', 'message' => __('invoicing.flash.cancelled', ['number' => $credit->full_number])]);

        return redirect($credit->url());
    }

    public function rectify(Request $request, SalesDocument $document, InvoiceCorrections $corrections): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(DocumentActions::allows($document, $user, 'rectify'), 403);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'code' => ['nullable', Rule::in(InvoiceCorrections::CODES)],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.line_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'between:-999999,999999'],
            'lines.*.unit_price' => ['required', 'numeric', 'between:-9999999,9999999'],
        ]);

        $credit = $corrections->rectify($document, $user, $data['reason'], array_values(array_map(fn (array $line): array => [
            'line_id' => (int) $line['line_id'],
            'quantity' => (string) $line['quantity'],
            'unit_price' => (string) $line['unit_price'],
        ], $data['lines'])), $data['code'] ?? 'R1');
        Inertia::flash('toast', ['type' => 'success', 'message' => __('invoicing.flash.rectified', ['number' => $credit->full_number])]);

        return redirect($credit->url());
    }

    public function void(Request $request, SalesDocument $document, InvoiceCorrections $corrections): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(DocumentActions::allows($document, $user, 'void'), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        $voided = $corrections->void($document, $user, $data['reason']);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('invoicing.flash.voided', ['number' => $voided->full_number])]);

        return redirect($voided->url());
    }

    /**
     * Lo no fiscal, también de una emitida: la nota interna y el proyecto y la bolsa (D-421).
     */
    public function updateNonFiscal(Request $request, SalesDocument $document): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(DocumentActions::allows($document, $user, 'edit_non_fiscal'), 403);
        $data = $request->validate([
            'internal_note' => ['nullable', 'string', 'max:5000'],
            'links' => ['present', 'array', 'max:10'],
            'links.*.project_id' => ['required', 'integer', 'exists:projects,id'],
            'links.*.hour_bank_id' => ['nullable', 'integer', 'exists:hour_banks,id'],
        ]);

        DB::transaction(function () use ($document, $data, $user): void {
            $note = isset($data['internal_note']) ? trim((string) $data['internal_note']) : '';
            $document->forceFill(['internal_note' => $note === '' ? null : $note, 'updated_by' => $user->id])->save();

            /** @var Collection<int, array{project_id: int, hour_bank_id: int|null}> $wanted */
            $wanted = collect(is_array($data['links']) ? $data['links'] : [])->map(fn (array $link): array => ['project_id' => (int) $link['project_id'], 'hour_bank_id' => isset($link['hour_bank_id']) ? (int) $link['hour_bank_id'] : null])
                ->unique(fn (array $link): string => $link['project_id'].'-'.($link['hour_bank_id'] ?? 0));
            $current = $document->links()->get();
            foreach ($current as $link) {
                if (! $wanted->contains(fn (array $one): bool => $one['project_id'] === $link->project_id && $one['hour_bank_id'] === $link->hour_bank_id)) {
                    $link->delete();
                }
            }
            foreach ($wanted as $one) {
                if (! $current->contains(fn (SalesDocumentLink $link): bool => $link->project_id === $one['project_id'] && $link->hour_bank_id === $one['hour_bank_id'])) {
                    SalesDocumentLink::query()->create([...$one, 'sales_document_id' => $document->id, 'method' => 'manual', 'created_by' => $user->id]);
                }
            }
        });

        activity('invoicing')->causedBy($user)->performedOn($document)->event('non_fiscal_updated')->log('invoicing.non_fiscal_updated');
        Inertia::flash('toast', ['type' => 'success', 'message' => __('invoicing.flash.note_saved')]);

        return back();
    }

    /**
     * «No necesita proyecto» (D-431) de una propia: como las de Holded (NoProjectNeededController),
     * solo sin enlaces y sin anular. No es fiscal: se cambia también emitida.
     */
    public function markNoProject(Request $request, SalesDocument $document): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(DocumentActions::allows($document, $user, 'edit_non_fiscal'), 403);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:'.SalesDocument::NO_PROJECT_NOTE_MAX]]);
        abort_if(in_array($document->status, [SalesDocumentStatus::Cancelled, SalesDocumentStatus::Voided], true) || $document->links()->exists(), 422, __('billing_rules.no_project.not_allowed'));

        $document->markNoProjectNeeded($user, isset($data['note']) ? (string) $data['note'] : null);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('billing_rules.no_project.marked', ['invoice' => $document->full_number ?? __('billing_rules.no_project.draft')])]);

        return back();
    }

    public function clearNoProject(Request $request, SalesDocument $document): RedirectResponse
    {
        abort_unless(DocumentActions::allows($document, $request->user(), 'edit_non_fiscal'), 403);

        $document->clearNoProjectNeeded();
        Inertia::flash('toast', ['type' => 'success', 'message' => __('billing_rules.no_project.cleared', ['invoice' => $document->full_number ?? __('billing_rules.no_project.draft')])]);

        return back();
    }

    private static function file(string $content, string $filename, string $mime, bool $download): SymfonyResponse
    {
        return response($content, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => HeaderUtils::makeDisposition($download ? HeaderUtils::DISPOSITION_ATTACHMENT : HeaderUtils::DISPOSITION_INLINE, $filename),
            'Content-Length' => (string) strlen($content),
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private static function queryString(array $query): string
    {
        $string = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return $string === '' ? '' : '?'.$string;
    }
}
