<?php

namespace App\Models;

use App\Enums\RecurringPeriodicity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Factura recurrente (PLAN-EMISION §3.1 y §4.2, H-057 a H-061; E3): la plantilla de la que sale una
 * factura cada periodo (RecurringInvoiceGenerator), en borrador por defecto (como Audax hace hoy en
 * Holded: alguien la revisa y la emite) o emitida y enviada sola. Las líneas llevan palabras
 * dinámicas ([mes], [trimestre], [año]…, DynamicWords). Se pausa, se reanuda y se omite un periodo.
 * Las importadas de Holded llegan en pausa hasta el corte (app:import-holded-recurring).
 *
 * @property int $id
 * @property string $name
 * @property int $client_id
 * @property int|null $project_id
 * @property int|null $hour_bank_id
 * @property RecurringPeriodicity $periodicity
 * @property int $interval_months
 * @property int $day_of_month
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable|null $end_date
 * @property int|null $max_occurrences
 * @property int $occurrences_count
 * @property CarbonImmutable|null $next_run_on
 * @property string $mode draft | issue
 * @property bool $auto_send
 * @property int|null $issue_as
 * @property int|null $series_id
 * @property int|null $payment_method_id
 * @property int|null $due_days
 * @property int|null $withholding_rate_id
 * @property string|null $body
 * @property string|null $internal_note
 * @property string|null $customer_reference
 * @property list<string>|null $skipped_dates
 * @property CarbonImmutable|null $paused_at
 * @property int|null $paused_by
 * @property CarbonImmutable|null $finished_at
 * @property string|null $last_error
 * @property CarbonImmutable|null $last_error_at
 * @property int|null $last_document_id
 * @property string|null $holded_recurring_id
 * @property array<string, mixed>|null $holded_snapshot
 * @property CarbonImmutable|null $reviewed_at
 * @property int|null $reviewed_by
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Client $client
 * @property-read Project|null $project
 * @property-read HourBank|null $hourBank
 * @property-read NumberingSeries|null $series
 * @property-read PaymentMethod|null $paymentMethod
 * @property-read Collection<int, RecurringInvoiceLine> $lines
 * @property-read Collection<int, RecurringInvoiceRun> $runs
 * @property-read Collection<int, SalesDocument> $documents
 * @property-read User|null $issuer
 * @property-read User|null $pauser
 * @property-read User|null $reviewer
 */
#[Fillable([
    'name', 'client_id', 'project_id', 'hour_bank_id', 'periodicity', 'interval_months', 'day_of_month', 'start_date', 'end_date',
    'max_occurrences', 'mode', 'auto_send', 'issue_as', 'series_id', 'payment_method_id', 'due_days', 'withholding_rate_id',
    'body', 'internal_note', 'customer_reference', 'holded_recurring_id', 'holded_snapshot', 'created_by', 'updated_by',
])]
class RecurringInvoice extends Model
{
    public const string MODE_DRAFT = 'draft';

    public const string MODE_ISSUE = 'issue';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'mode' => self::MODE_DRAFT,
        'auto_send' => false,
        'occurrences_count' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'periodicity' => RecurringPeriodicity::class,
            'interval_months' => 'integer',
            'day_of_month' => 'integer',
            'start_date' => 'immutable_date',
            'end_date' => 'immutable_date',
            'max_occurrences' => 'integer',
            'occurrences_count' => 'integer',
            'next_run_on' => 'immutable_date',
            'auto_send' => 'boolean',
            'due_days' => 'integer',
            'skipped_dates' => 'array',
            'paused_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'last_error_at' => 'immutable_datetime',
            'holded_snapshot' => 'array',
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    /**
     * Las que siguen generando (ni pausadas ni acabadas).
     *
     * @param  Builder<RecurringInvoice>  $query
     * @return Builder<RecurringInvoice>
     */
    public function scopeRunning(Builder $query): Builder
    {
        return $query->whereNull('paused_at')->whereNull('finished_at')->whereNotNull('next_run_on');
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    /**
     * @return BelongsTo<HourBank, $this>
     */
    public function hourBank(): BelongsTo
    {
        return $this->belongsTo(HourBank::class)->withTrashed();
    }

    /**
     * @return BelongsTo<NumberingSeries, $this>
     */
    public function series(): BelongsTo
    {
        return $this->belongsTo(NumberingSeries::class, 'series_id');
    }

    /**
     * @return BelongsTo<PaymentMethod, $this>
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /**
     * @return HasMany<RecurringInvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(RecurringInvoiceLine::class)->orderBy('position')->orderBy('id');
    }

    /**
     * El historial: lo generado y lo omitido, del más reciente al más antiguo.
     *
     * @return HasMany<RecurringInvoiceRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(RecurringInvoiceRun::class)->orderByDesc('period_on')->orderByDesc('id');
    }

    /**
     * @return HasMany<SalesDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(SalesDocument::class)->orderByDesc('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issue_as');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function pauser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paused_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isPaused(): bool
    {
        return $this->paused_at !== null;
    }

    public function isFinished(): bool
    {
        return $this->finished_at !== null || $this->next_run_on === null;
    }

    /** Estado para la interfaz: active, paused o finished. */
    public function state(): string
    {
        return match (true) {
            $this->isFinished() => 'finished',
            $this->isPaused() => 'paused',
            default => 'active',
        };
    }

    public function isSkipped(CarbonImmutable $period): bool
    {
        return in_array($period->toDateString(), $this->skipped_dates ?? [], true);
    }

    /** Ficha: /facturacion/recurrentes/{id}. */
    public function url(): string
    {
        return '/facturacion/recurrentes/'.$this->id;
    }
}
