<?php

namespace App\Domain\Billing\Holded;

use App\Domain\Reports\Money;
use App\Domain\Weeklies\Report\WeeklyProjectStatus;
use App\Enums\BillingType;
use App\Enums\HoldedDocumentKind;
use App\Enums\TimeEntryStatus;
use App\Models\Client;
use App\Models\HourBank;
use App\Models\Project;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Holded falso (Fase 12, F1; D-384) para los tests y el desarrollo local (HOLDED_DRIVER=fake). Habla
 * con los mismos nombres de campo de la API v2 que HttpHoldedClient y FakeHolded::fromDatabase()
 * fabrica un Holded coherente con los datos de Audax:
 * - un contacto por cliente (con su NIF; uno con el prefijo «ES-» y otro sin NIF, que casa por el
 *   nombre), más un contacto que no es de ningún cliente y un proveedor, que no se lee,
 * - un proyecto de Holded por cada precio cerrado y fee («CÓDIGO · nombre») y uno antiguo sin pareja,
 * - una factura por bolsa vendida con su código F como número, dos por precio cerrado (50 % y 50 %),
 *   una al mes por fee, una de horas sin proyecto (para enlazarla a mano) y una rectificativa,
 * - cobros: lo antiguo cobrado, una cobrada a medias, una vencida y lo reciente pendiente.
 * Determinista: con los mismos datos, el mismo Holded.
 */
class FakeHolded implements HoldedApi
{
    private int $requests = 0;

    /**
     * @param  list<array<string, mixed>>  $contacts
     * @param  list<array<string, mixed>>  $projects
     * @param  list<array<string, mixed>>  $invoices
     * @param  list<array<string, mixed>>  $creditNotes
     * @param  list<array<string, mixed>>  $payments
     * @param  array<string, string>  $pdfs  id → contenido; si falta, uno generado
     */
    public function __construct(
        private array $contacts = [],
        private array $projects = [],
        private array $invoices = [],
        private array $creditNotes = [],
        private array $payments = [],
        private array $pdfs = [],
    ) {}

    public function contacts(): iterable
    {
        $this->requests++;

        return $this->contacts;
    }

    public function projects(): iterable
    {
        $this->requests++;

        return $this->projects;
    }

    public function invoices(): iterable
    {
        $this->requests++;

        return $this->invoices;
    }

    public function creditNotes(): iterable
    {
        $this->requests++;

        return $this->creditNotes;
    }

    public function payments(): iterable
    {
        $this->requests++;

        return $this->payments;
    }

    public function pdf(string $holdedId, HoldedDocumentKind $kind): string
    {
        $this->requests++;

        if (isset($this->pdfs[$holdedId])) {
            return $this->pdfs[$holdedId];
        }

        $documents = $kind === HoldedDocumentKind::CreditNote ? $this->creditNotes : $this->invoices;
        foreach ($documents as $document) {
            if (HoldedPayload::id($document) === $holdedId) {
                return self::pdfFor($document);
            }
        }

        throw HoldedRequestFailed::forStatus(404, '/invoices/'.$holdedId.'/pdf');
    }

    public function requestCount(): int
    {
        return $this->requests;
    }

    /**
     * Cambia las facturas (para probar la segunda sincronización).
     *
     * @param  list<array<string, mixed>>  $invoices
     */
    public function withInvoices(array $invoices): self
    {
        $clone = clone $this;
        $clone->invoices = array_values($invoices);

        return $clone;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function invoiceList(): array
    {
        return $this->invoices;
    }

    /** Id de Holded determinista (24 caracteres hexadecimales, como los de Holded). */
    public static function holdedId(string $seed): string
    {
        return substr(sha1('holded-fake:'.$seed), 0, 24);
    }

    /**
     * PDF de ejemplo de un documento: número, cliente, fecha, líneas y total.
     *
     * @param  array<string, mixed>  $document
     */
    public static function pdfFor(array $document): string
    {
        $number = HoldedPayload::string($document, 'document_number', 'docNumber') ?? 'Borrador';
        $lines = [
            'Cliente: '.(HoldedPayload::string($document, 'contact_name') ?? '—'),
            'Fecha: '.(HoldedPayload::date($document, 'date')?->format('d/m/Y') ?? '—'),
            '',
        ];
        foreach (HoldedPayload::list($document, 'items') as $item) {
            $lines[] = (HoldedPayload::string($item, 'name') ?? '').' · '.number_format((float) (HoldedPayload::money($item, 'subtotal', 'price') ?? '0'), 2, ',', '.').' EUR';
        }
        $lines[] = '';
        $lines[] = 'Base imponible: '.number_format((float) (HoldedPayload::money($document, 'subtotal') ?? '0'), 2, ',', '.').' EUR';
        $lines[] = 'Total: '.number_format((float) (HoldedPayload::money($document, 'total') ?? '0'), 2, ',', '.').' EUR';
        $lines[] = 'Documento de ejemplo (Holded falso): no es una factura real.';

        return SimplePdf::make('Audax Studio · Factura '.$number, $lines);
    }

    /**
     * Un Holded coherente con lo que hay en la base de datos (local y DemoDataSeeder).
     */
    public static function fromDatabase(?CarbonImmutable $today = null): self
    {
        $today ??= LocalTime::today();
        $contacts = [];
        $contactOf = [];

        $clients = Client::query()->withTrashed()->orderBy('id')->get();
        foreach ($clients as $index => $client) {
            $id = self::holdedId('contact-'.$client->id);
            $contactOf[$client->id] = ['id' => $id, 'name' => $client->name];
            $taxId = $client->tax_id;
            // Uno con el prefijo del NIF-IVA y guion, otro sin NIF (casa por el nombre con «, S.A.»).
            if ($index === 0 && $taxId !== null) {
                $taxId = 'ES-'.$taxId;
            }
            $name = $client->name;
            if ($index === 2) {
                $taxId = null;
                $name = $client->name.', S.A.';
            }

            $contacts[] = [
                'id' => $id,
                'name' => $name,
                'trade_name' => $client->name,
                'code' => $taxId,
                'email' => $client->contact_email,
                'type' => 'client',
                'billing_address' => [
                    'address' => 'Calle Mayor, '.(10 + $index * 3),
                    'city' => ['Logroño', 'Madrid', 'Las Palmas de Gran Canaria', 'A Coruña', 'Santander', 'Barcelona', 'Huesca', 'Valencia'][$index % 8],
                    'postal_code' => ['26001', '28013', '35001', '15001', '39001', '08001', '22001', '46001'][$index % 8],
                    'province' => ['La Rioja', 'Madrid', 'Las Palmas', 'A Coruña', 'Cantabria', 'Barcelona', 'Huesca', 'Valencia'][$index % 8],
                    'country_code' => 'ES',
                ],
            ];
        }
        $contacts[] = ['id' => self::holdedId('contact-nebula'), 'name' => 'Estudio Nébula, S.L.', 'trade_name' => 'Nébula', 'code' => 'B46000999', 'email' => 'hola@nebula.example', 'type' => 'client',
            'billing_address' => ['address' => 'Avenida del Puerto, 4', 'city' => 'Valencia', 'postal_code' => '46023', 'province' => 'Valencia', 'country_code' => 'ES']];
        $contacts[] = ['id' => self::holdedId('contact-supplier'), 'name' => 'Papelería Ruiz', 'code' => 'B46111222', 'type' => 'supplier'];

        $projects = [];
        $holdedProjectOf = [];
        $sold = Project::query()->withTrashed()->whereNotNull('client_id')->orderBy('id')->get();
        foreach ($sold as $project) {
            if ($project->billing_type === BillingType::FixedPrice || self::isFee($project)) {
                $id = self::holdedId('project-'.$project->id);
                $holdedProjectOf[$project->id] = $id;
                $projects[] = ['id' => $id, 'name' => $project->code.' · '.$project->name];
            }
        }
        $projects[] = ['id' => self::holdedId('project-legacy'), 'name' => 'Proyecto antiguo 2024'];

        /** @var list<array{date: CarbonImmutable, number: string|null, contact: array{id: string, name: string}, lines: list<array<string, mixed>>, seed: string, tags?: list<string>, draft?: bool}> $drafts */
        $drafts = [];

        $banks = HourBank::query()->withTrashed()->with(['project' => fn ($query) => $query->withTrashed()])
            ->whereNotNull('price_amount')->orderBy('start_date')->orderBy('id')->get();
        foreach ($banks as $bank) {
            $project = $bank->project;
            if ($project->client_id === null || ! isset($contactOf[$project->client_id]) || $bank->start_date->greaterThan($today)) {
                continue;
            }
            $drafts[] = [
                'date' => $bank->start_date,
                'number' => self::fCode($bank->invoice_reference),
                'contact' => $contactOf[$project->client_id],
                'seed' => 'bank-'.$bank->id,
                'tags' => ['#bolsadehoras'],
                // Como en Holded (D-396): «bolsadehoras» con las HORAS como unidades y el €/h como precio;
                // una de cada tres, con un 15 % de descuento de línea.
                'lines' => [self::bankLine($bank, $project->name, count($drafts) % 3 === 1)],
            ];
        }

        foreach ($sold as $project) {
            if (! isset($contactOf[(int) $project->client_id])) {
                continue;
            }
            $contact = $contactOf[(int) $project->client_id];
            $start = $project->start_date ?? CarbonImmutable::parse($project->created_at ?? $today);

            if ($project->billing_type === BillingType::FixedPrice && $project->fixed_price_amount !== null && $start->lessThanOrEqualTo($today)) {
                $half = bcdiv((string) $project->fixed_price_amount, '2', 2);
                $drafts[] = ['date' => $start, 'number' => self::fCodeIn($project->description), 'contact' => $contact, 'seed' => 'fixed-a-'.$project->id, 'tags' => ['#productodigital'],
                    'lines' => [self::line('Diseño Producto UX/UI', $half, $holdedProjectOf[$project->id] ?? null, 'Pago inicio de proyecto · '.$project->name, code: 'D_UX_UI')]];
                $second = $project->due_date ?? $start->addMonthsNoOverflow(3);
                if ($second->lessThanOrEqualTo($today)) {
                    $drafts[] = ['date' => $second, 'number' => null, 'contact' => $contact, 'seed' => 'fixed-b-'.$project->id, 'tags' => ['#productodigital'],
                        'lines' => [self::line('Diseño Producto UX/UI', Money::round(Money::sub((string) $project->fixed_price_amount, $half)), $holdedProjectOf[$project->id] ?? null, 'Pago a la entrega · '.$project->name, code: 'D_UX_UI')]];
                }
            }

            if (self::isFee($project)) {
                $amount = $project->monthly_fee_amount !== null ? (string) $project->monthly_fee_amount : '1200.00';
                $floor = $today->subMonthsNoOverflow(12)->startOfMonth();
                $month = $start->startOfMonth()->lessThan($floor) ? $floor : $start->startOfMonth();
                $end = $project->due_date !== null && $project->due_date->lessThan($today) ? $project->due_date : $today;
                for (; $month->lessThan($end->startOfMonth()); $month = $month->addMonthNoOverflow()) {
                    // Las recurrentes no llevan proyecto de Holded: se enlazan con la sugerencia (D-388).
                    $drafts[] = ['date' => $month, 'number' => null, 'contact' => $contact, 'seed' => 'fee-'.$project->id.'-'.$month->format('Ym'), 'tags' => ['#fee', '#productodigital'],
                        'lines' => [self::line('Fee Producto digital', $amount, null, 'Sprint de '.$month->locale('es')->translatedFormat('F Y').' · '.$project->name, code: 'F_UX')]];
                }
                // El borrador que la recurrente genera para el mes siguiente (sin número hasta aprobarlo).
                $next = $today->addMonthNoOverflow()->startOfMonth();
                $drafts[] = ['date' => $today, 'number' => null, 'contact' => $contact, 'seed' => 'fee-draft-'.$project->id, 'tags' => ['#fee'], 'draft' => true,
                    'lines' => [self::line('Fee Producto digital', $amount, null, 'Sprint de '.$next->locale('es')->translatedFormat('F Y').' · '.$project->name, code: 'F_UX')]];
            }

            if ($project->billing_type === BillingType::TimeAndMaterials && ! self::isFee($project) && $project->hourly_rate !== null) {
                $hoursMonth = $today->subMonthsNoOverflow(2)->startOfMonth();
                $minutes = (int) $project->timeEntries()
                    ->whereIn('status', [TimeEntryStatus::Approved->value, TimeEntryStatus::Locked->value])
                    ->whereBetween('date', [$hoursMonth->toDateString(), $hoursMonth->endOfMonth()->toDateString()])
                    ->sum('minutes');
                if ($minutes > 0) {
                    $drafts[] = ['date' => $hoursMonth->endOfMonth()->startOfDay(), 'number' => null, 'contact' => $contact, 'seed' => 'hours-'.$project->id, 'tags' => ['#desarrollo'],
                        'lines' => [self::line('Desarrollo', Money::round(Money::forMinutes($minutes, (string) $project->hourly_rate)), null, 'Horas de '.$hoursMonth->locale('es')->translatedFormat('F Y'), round($minutes / 60, 2), (string) $project->hourly_rate, code: 'DES')]];
                }
            }
        }

        // Una factura de un contacto que no es de ningún cliente de Audax (se resuelve a mano).
        $drafts[] = ['date' => $today->subMonthsNoOverflow(2)->startOfMonth()->addDays(9), 'number' => null, 'contact' => ['id' => self::holdedId('contact-nebula'), 'name' => 'Estudio Nébula, S.L.'],
            'seed' => 'nebula', 'tags' => ['#productodigital'], 'lines' => [self::line('Auditoría UX y CRO', '2400.00', null, 'Auditoría de la tienda online', code: 'AUX')]];

        usort($drafts, fn (array $a, array $b): int => [$a['date']->toDateString(), $a['seed']] <=> [$b['date']->toDateString(), $b['seed']]);

        $counters = [];
        $invoices = [];
        $payments = [];
        $feeSeen = 0;
        $creditNotes = [];
        $overdueDone = false;
        $partialDone = false;

        foreach ($drafts as $draft) {
            $isDraft = $draft['draft'] ?? false;
            $year = $draft['date']->format('y');
            $counters[$year] ??= 300;
            $number = $isDraft ? null : ($draft['number'] ?? 'F'.$year.sprintf('%04d', ++$counters[$year]));
            $id = self::holdedId('invoice-'.$draft['seed']);
            $subtotal = Money::round(Money::add(...array_map(fn (array $line): string => (string) $line['subtotal'], $draft['lines'])));
            $tax = Money::round(Money::mul($subtotal, '0.21'));
            $total = Money::round(Money::add($subtotal, $tax));
            $age = (int) $draft['date']->diffInDays($today);
            $due = $draft['date']->addDays(30);

            // Lo antiguo, cobrado; una vencida sin cobrar; una cobrada a medias; lo reciente, pendiente.
            $paid = '0.00';
            if ($isDraft) {
                // Un borrador no se cobra.
            } elseif ($age > 75 && ! $overdueDone && str_starts_with($draft['seed'], 'fee-')) {
                $overdueDone = true;
            } elseif ($age > 60) {
                $paid = $total;
            } elseif ($age > 35 && ! $partialDone) {
                $partialDone = true;
                $paid = Money::round(bcdiv($total, '2', 6));
            }

            if (bccomp($paid, '0', 2) > 0) {
                $payments[] = ['id' => self::holdedId('payment-'.$draft['seed']), 'document_id' => $id, 'document_type' => 'invoice',
                    'date' => $draft['date']->addDays(min(28, $age))->toDateString(), 'amount' => $paid, 'payment_method' => 'Transferencia'];
            }

            $invoice = [
                'id' => $id,
                'document_number' => $number,
                'contact_id' => $draft['contact']['id'],
                'contact_name' => $draft['contact']['name'],
                'date' => $draft['date']->toDateString(),
                'due_date' => $due->toDateString(),
                'currency' => 'eur',
                'subtotal' => $subtotal,
                'tax' => $tax,
                'total' => $total,
                'status' => bccomp($paid, $total, 2) === 0 ? 'completed' : (bccomp($paid, '0', 2) > 0 ? 'partial' : 'pending'),
                'approval_status' => $isDraft ? 'draft' : 'approved',
                'draft' => $isDraft,
                'tags' => [...($draft['tags'] ?? []), '#'.Str::slug($draft['contact']['name'], '')],
                'payments_total' => $paid,
                'payments_pending' => Money::round(Money::sub($total, $paid)),
                'items' => $draft['lines'],
                'notes' => str_starts_with($draft['seed'], 'fee-') ? 'PEDIDO DE COMPRA Nº '.(4500 + count($invoices)) : null,
            ];
            $invoices[] = $invoice;

            // Una rectificativa del 10 % de la segunda factura de fee (descuento acordado).
            if (! $isDraft && str_starts_with($draft['seed'], 'fee-') && ++$feeSeen === 2) {
                $creditSubtotal = Money::round(Money::mul($subtotal, '-0.1'));
                $creditTax = Money::round(Money::mul($creditSubtotal, '0.21'));
                $creditDate = $draft['date']->addDays(12);
                $creditNotes[] = [
                    'id' => self::holdedId('credit-'.$draft['seed']),
                    'document_number' => 'CN'.$creditDate->format('y').'0001',
                    'contact_id' => $draft['contact']['id'],
                    'contact_name' => $draft['contact']['name'],
                    'date' => $creditDate->toDateString(),
                    'currency' => 'eur',
                    'subtotal' => $creditSubtotal,
                    'tax' => $creditTax,
                    'total' => Money::round(Money::add($creditSubtotal, $creditTax)),
                    'status' => 'completed',
                    'approval_status' => 'approved',
                    'payments_total' => '0.00',
                    'payments_pending' => '0.00',
                    'rectified_document_id' => $id,
                    'items' => [self::line('Fee Producto digital', $creditSubtotal, null, 'Descuento acordado del 10 %', code: 'F_UX')],
                    'notes' => 'Rectificación por diferencias.',
                ];
            }
        }

        // La factura de horas sin proyecto, para enlazarla a mano (E2E).
        return new self($contacts, $projects, $invoices, $creditNotes, $payments);
    }

    /**
     * @return array<string, mixed>
     */
    private static function line(string $name, string $subtotal, ?string $projectId, ?string $description = null, ?float $units = null, ?string $price = null, ?string $code = null, string $discount = '0'): array
    {
        return [
            'name' => $name,
            'code' => $code,
            'description' => $description,
            'units' => $units ?? 1,
            'price' => $price ?? $subtotal,
            'discount' => $discount,
            'subtotal' => $subtotal,
            'taxes' => ['s_iva_21'],
            'project_id' => $projectId,
        ];
    }

    /**
     * La línea «bolsadehoras» de una bolsa: horas × €/h (y, si toca, un 15 % de descuento) que dan
     * exactamente su precio.
     *
     * @return array<string, mixed>
     */
    private static function bankLine(HourBank $bank, string $projectName, bool $discount): array
    {
        $hours = max(1, intdiv($bank->total_minutes, 60));
        $factor = $discount ? '0.85' : '1';
        $price = bcdiv((string) $bank->price_amount, bcmul((string) $hours, $factor, 4), 4);
        $month = $bank->start_date->locale('es')->translatedFormat('F Y');

        return self::line('bolsadehoras', (string) $bank->price_amount, null, 'Bolsa de horas '.$hours.'h '.$month.' · '.$projectName, (float) $hours, $price, 'BDH', $discount ? '15' : '0');
    }

    private static function isFee(Project $project): bool
    {
        return $project->billing_type === BillingType::MonthlyFee
            || ($project->billing_type === BillingType::TimeAndMaterials && WeeklyProjectStatus::isMonthlyFee($project));
    }

    /** El código F de la bolsa si tiene la forma de la serie de Holded (F260170). */
    private static function fCode(?string $reference): ?string
    {
        $value = HoldedPayload::normalizeNumber($reference);

        return $value !== null && preg_match('/^F\d{6}$/', $value) === 1 ? $value : null;
    }

    /** «Factura: F260045.» en la descripción de un proyecto (D-135). */
    private static function fCodeIn(?string $description): ?string
    {
        return preg_match('/Factura:\s*(F\d{6})/i', (string) $description, $match) === 1 ? strtoupper($match[1]) : null;
    }
}
