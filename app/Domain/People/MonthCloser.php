<?php

namespace App\Domain\People;

use App\Domain\People\Reports\PeopleFormat;
use App\Domain\People\Reports\PeopleReports;
use App\Domain\People\Reports\RegisterDataset;
use App\Domain\People\Reports\RegisterDocument;
use App\Domain\People\Reports\RegisterFiles;
use App\Domain\Weeklies\AppModules;
use App\Enums\AppModule;
use App\Enums\MonthCloseStatus;
use App\Models\ClockEvent;
use App\Models\EmploymentProfile;
use App\Models\MonthClose;
use App\Models\User;
use App\Notifications\People\MonthCloseDisagreed;
use App\Notifications\People\MonthCloseReady;
use App\Notifications\People\MonthCloseReminder;
use App\Notifications\People\MonthCloseReopened;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Cierre mensual del registro (PLAN-FASE-11 §7.5; D-347; W-084, W-085 y W-114), como «Confirmar
 * jornadas» de Woffu:
 *
 * - **Generar** (el día 1, `people:close-months`, o a mano su responsable o RR. HH.): congela los
 *   totales y el diario del mes tal como los calcula WorkdayCalculator (con la clasificación de las
 *   horas extra), el punto de la cadena al que corresponden y el PDF del resumen con su SHA-256, que
 *   queda en «Mi registro». Es la copia de los arts. 12.4.c y 35.5 ET.
 * - **Confirmar** o **no estar de acuerdo** (con motivo): solo la persona. El desacuerdo no bloquea
 *   nada; la confirmación **bloquea el mes**: no se corrige ni se clasifica nada de él
 *   (assertMonthOpen).
 * - **Desconfirmar**: su responsable o RR. HH., nunca ella misma, con motivo (queda en el cierre y
 *   en la auditoría). El mes vuelve a admitir correcciones y el siguiente cierre es una versión
 *   nueva.
 * - Si cambia el registro de un mes con el cierre aún sin confirmar (se acepta una corrección o se
 *   clasifica una hora extra), el cierre se **regenera** solo (versión nueva) y se avisa a la
 *   persona: nunca confirma unos totales que ya no son los del registro.
 * - Recordatorios a los 3 y a los 7 días mientras siga pendiente.
 *
 * Nada se borra: las versiones sustituidas y desconfirmadas se conservan con su PDF.
 */
final class MonthCloser
{
    public const string DISK = 'local';

    /** Días tras la generación en los que se recuerda confirmar (W-114). */
    public const array REMINDER_DAYS = [3, 7];

    public const string LOG = 'month_closes';

    public function __construct(
        private readonly RegisterDataset $dataset,
        private readonly PeopleReports $reports,
        private readonly RegisterFiles $files,
        private readonly RegisterHasher $hasher,
    ) {}

    /**
     * Genera (o regenera) el cierre de un mes ya acabado.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function generate(User $subject, CarbonImmutable $month, ?User $by = null, bool $notify = true): MonthClose
    {
        $month = $month->startOfMonth();

        if ($by !== null && ! PeopleAccess::decidesFor($by, $subject)) {
            throw new AuthorizationException;
        }

        if ($month->format('Y-m') >= LocalTime::today()->format('Y-m')) {
            throw ValidationException::withMessages(['month' => __('people.errors.close_month_not_over')]);
        }

        $replaced = null;

        $close = DB::transaction(function () use ($subject, $month, $by, &$replaced): MonthClose {
            User::query()->whereKey($subject->id)->lockForUpdate()->value('id');

            $current = MonthClose::query()->current()->where('user_id', $subject->id)->where('month', $month->toDateString())->lockForUpdate()->first();

            if ($current !== null && $current->status === MonthCloseStatus::Confirmed) {
                throw ValidationException::withMessages(['month' => __('people.errors.close_confirmed')]);
            }

            if ($current !== null) {
                $current->forceFill(['status' => MonthCloseStatus::Superseded])->save();
                $replaced = $current;
            }

            $version = (int) MonthClose::query()->where('user_id', $subject->id)->where('month', $month->toDateString())->max('version') + 1;

            return $this->freeze($subject, $month, $version, $by);
        });

        activity(self::LOG)
            ->causedBy($by)
            ->performedOn($close)
            ->event($replaced === null ? 'generated' : 'regenerated')
            ->withProperties(['month' => $close->monthKey(), 'version' => $close->version, 'user_id' => $subject->id, 'pdf_sha256' => $close->pdf_sha256])
            ->log('month_close.generated');

        if ($notify) {
            PeopleNotifier::send([$subject], new MonthCloseReady($close, $replaced !== null));
        }

        return $close;
    }

    /**
     * La persona confirma su mes.
     *
     * @throws AuthorizationException
     */
    public function confirm(User $actor, MonthClose $close): MonthClose
    {
        return $this->answer($actor, $close, function (MonthClose $locked): void {
            if (! in_array($locked->status, [MonthCloseStatus::Pending, MonthCloseStatus::Disagreed], true)) {
                throw ValidationException::withMessages(['close' => __('people.errors.close_not_pending')]);
            }

            $locked->forceFill(['status' => MonthCloseStatus::Confirmed, 'confirmed_at' => CarbonImmutable::now()])->save();
        }, 'confirmed');
    }

    /**
     * La persona no está de acuerdo, con su motivo (no bloquea nada).
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function disagree(User $actor, MonthClose $close, string $note): MonthClose
    {
        $note = trim($note);

        if (mb_strlen($note) < 5) {
            throw ValidationException::withMessages(['note' => __('people.errors.close_note_required')]);
        }

        $answered = $this->answer($actor, $close, function (MonthClose $locked) use ($note): void {
            if ($locked->status !== MonthCloseStatus::Pending) {
                throw ValidationException::withMessages(['close' => __('people.errors.close_not_pending')]);
            }

            $locked->forceFill(['status' => MonthCloseStatus::Disagreed, 'disagreed_at' => CarbonImmutable::now(), 'disagreement_note' => $note])->save();
        }, 'disagreed');

        PeopleNotifier::send(PeopleAccess::companyDeciders($actor), new MonthCloseDisagreed($answered, $actor->name));

        return $answered;
    }

    /**
     * Su responsable o RR. HH. desconfirma el mes con un motivo: vuelve a admitir correcciones.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function reopen(User $actor, MonthClose $close, string $reason): MonthClose
    {
        $reason = trim($reason);
        $subject = User::query()->findOrFail($close->user_id);

        if (! PeopleAccess::decidesFor($actor, $subject)) {
            throw new AuthorizationException;
        }

        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['reason' => __('people.errors.reopen_reason_required')]);
        }

        $reopened = DB::transaction(function () use ($actor, $close, $reason): MonthClose {
            $locked = MonthClose::query()->whereKey($close->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== MonthCloseStatus::Confirmed) {
                throw ValidationException::withMessages(['close' => __('people.errors.close_not_confirmed')]);
            }

            $locked->forceFill([
                'status' => MonthCloseStatus::Reopened,
                'reopened_at' => CarbonImmutable::now(),
                'reopened_by' => $actor->id,
                'reopen_reason' => $reason,
            ])->save();

            return $locked;
        });

        activity(self::LOG)
            ->causedBy($actor)
            ->performedOn($reopened)
            ->event('reopened')
            ->withProperties(['month' => $reopened->monthKey(), 'version' => $reopened->version, 'user_id' => $reopened->user_id, 'reason' => $reason])
            ->log('month_close.reopened');

        PeopleNotifier::send([$subject], new MonthCloseReopened($reopened, $actor->name));

        return $reopened;
    }

    /**
     * Tras un cambio en el registro de un día (corrección aceptada, hora extra clasificada): si el
     * mes tiene un cierre sin confirmar, se regenera para que la persona no confirme totales viejos.
     */
    public function refreshAfterChange(User $subject, string $date): void
    {
        $month = CarbonImmutable::parse($date)->startOfMonth();

        $pending = MonthClose::query()
            ->where('user_id', $subject->id)
            ->where('month', $month->toDateString())
            ->whereIn('status', [MonthCloseStatus::Pending->value, MonthCloseStatus::Disagreed->value])
            ->exists();

        if ($pending) {
            $this->generate($subject, $month);
        }
    }

    /** ¿Está confirmado el mes de ese día (bloqueado)? */
    public static function isConfirmed(int $userId, string $date): bool
    {
        return MonthClose::query()
            ->where('user_id', $userId)
            ->where('month', substr($date, 0, 7).'-01')
            ->where('status', MonthCloseStatus::Confirmed->value)
            ->exists();
    }

    /**
     * Un día de un mes confirmado no se corrige ni se clasifica sin desconfirmarlo antes
     * (PLAN-FASE-11 §7.4; W-085).
     *
     * @throws ValidationException
     */
    public static function assertMonthOpen(int $userId, string $date): void
    {
        if (self::isConfirmed($userId, $date)) {
            throw ValidationException::withMessages(['date' => __('people.errors.month_confirmed', ['month' => PeopleFormat::month($date)])]);
        }
    }

    /**
     * El día 1 (o el primer día que pase la orden): genera el cierre del mes anterior de quien aún no
     * lo tiene, y recuerda a los 3 y a los 7 días los que siguen pendientes. Nada con el módulo
     * apagado de verdad.
     *
     * @return array{generated: int, reminded: int}
     */
    public function runDue(?CarbonImmutable $now = null): array
    {
        if (! AppModules::enabled(AppModule::People)) {
            return ['generated' => 0, 'reminded' => 0];
        }

        $now ??= CarbonImmutable::now();
        $month = $now->setTimezone(LocalTime::timezone())->startOfMonth()->subMonth();
        $generated = 0;

        foreach ($this->dueSubjects($month) as $subject) {
            $this->generate($subject, $month);
            $generated++;
        }

        return ['generated' => $generated, 'reminded' => $this->remind($now)];
    }

    /**
     * Quién necesita el cierre de $month: la plantilla sujeta al registro que tuvo jornada teórica o
     * fichajes ese mes y aún no tiene ningún cierre de él (si se desconfirmó, lo regenera su
     * responsable o RR. HH.: no se vuelve a cerrar solo).
     *
     * @return list<User>
     */
    public function dueSubjects(CarbonImmutable $month): array
    {
        $from = $month->startOfMonth()->toDateString();
        $to = $month->endOfMonth()->toDateString();
        $start = RegisterStart::date();

        if ($start === null || $start > $to) {
            return [];
        }

        $closed = MonthClose::query()->where('month', $from)->distinct()->pluck('user_id')->all();
        $candidates = PeopleAccess::registerSubjects()->whereNotIn('id', $closed)->with('employmentProfile')->get();
        $due = [];

        foreach ($candidates as $user) {
            if (! $user->isActive() && ! ClockEvent::query()->where('user_id', $user->id)->whereBetween('occurred_at', self::bounds($month))->exists()) {
                continue;
            }

            /** @var EmploymentProfile|null $profile */
            $profile = $user->employmentProfile;

            if ($profile !== null && ($profile->hire_date?->toDateString() > $to || ($profile->termination_date !== null && $profile->termination_date->toDateString() < $from))) {
                continue;
            }

            $due[] = $user;
        }

        return $due;
    }

    /**
     * Recordatorios a los 3 y a los 7 días de cada cierre pendiente.
     */
    public function remind(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $sent = 0;

        $pending = MonthClose::query()
            ->with('user')
            ->where('status', MonthCloseStatus::Pending->value)
            ->where('reminders', '<', count(self::REMINDER_DAYS))
            ->get();

        foreach ($pending as $close) {
            $due = self::REMINDER_DAYS[$close->reminders] ?? null;

            if ($due === null || $close->generated_at->addDays($due)->greaterThan($now) || ! $close->user->isActive()) {
                continue;
            }

            $close->forceFill(['reminders' => $close->reminders + 1, 'reminded_at' => $now])->save();
            PeopleNotifier::send([$close->user], new MonthCloseReminder($close));
            $sent++;
        }

        return $sent;
    }

    /** Los bytes del PDF guardado de un cierre. */
    public function pdfContents(MonthClose $close): ?string
    {
        $disk = Storage::disk(self::DISK);

        return $disk->exists($close->pdf_path) ? $disk->get($close->pdf_path) : null;
    }

    /**
     * Congela el mes: el diario y los totales (RegisterDataset), el punto de la cadena, el sello y
     * el PDF.
     */
    private function freeze(User $subject, CarbonImmutable $month, int $version, ?User $by): MonthClose
    {
        $from = $month->toDateString();
        $to = $month->endOfMonth()->toDateString();
        $now = CarbonImmutable::now()->startOfSecond();
        $data = $this->dataset->build([$subject], $from, $to)[$subject->id];
        $head = ClockEvent::query()->where('user_id', $subject->id)->orderByDesc('seq')->first(['seq', 'hash']);
        $totals = $data['totals'];

        $days = [];
        foreach ($data['lines'] as $line) {
            $days[] = [
                'date' => $line['date'],
                'expected_minutes' => $line['expected_minutes'],
                'worked_minutes' => $line['worked_minutes'],
                'pause_minutes' => $line['pause_minutes'],
                'difference_minutes' => $line['difference_minutes'],
                'excess_minutes' => $line['excess_minutes'],
                'overtime_minutes' => $line['overtime_minutes'],
                'complementary_minutes' => $line['complementary_minutes'],
                'flex_minutes' => $line['flex_minutes'],
                'unclassified_minutes' => $line['unclassified_minutes'],
                'destination' => $line['destination'],
                'first_in' => $line['first_in'],
                'last_out' => $line['last_out'],
                'modes' => $line['modes'],
                'incidents' => $line['incidents'],
                'status' => $line['status'],
                'holiday' => $line['holiday'],
                'absence' => $line['absence'],
            ];
        }

        $close = new MonthClose;
        $close->forceFill([
            'user_id' => $subject->id,
            'month' => $from,
            'version' => $version,
            'status' => MonthCloseStatus::Pending,
            'worked_minutes' => $totals['worked_minutes'],
            'expected_minutes' => $totals['expected_minutes'],
            'difference_minutes' => $totals['difference_minutes'],
            'overtime_minutes' => $totals['overtime_minutes'],
            'totals' => [...$totals, 'part_time' => (bool) ($subject->employmentProfile->part_time ?? false)],
            'days' => $days,
            'register_seq' => $head?->seq,
            'register_hash' => $head?->hash,
            'generated_at' => $now,
            'generated_by' => $by?->id,
            'pdf_path' => '',
            'pdf_sha256' => '',
            'content_hash' => '',
        ]);
        $close->content_hash = $this->hasher->closeContent($close);

        $document = $this->document($subject, $from, $to, $close);
        $bytes = $this->files->pdf($document, $close->content_hash, $now, $by?->name);
        $path = sprintf('people/cierres/%d/%s-v%d.%s', $subject->id, $month->format('Y-m'), $version, $this->files->extension());
        Storage::disk(self::DISK)->put($path, $bytes);

        $close->pdf_path = $path;
        $close->pdf_sha256 = hash('sha256', $bytes);
        $close->save();

        return $close;
    }

    /**
     * El resumen del mes en PDF: el de «Mi registro» del mes, con el título del cierre y la nota de
     * cómo se confirma.
     */
    private function document(User $subject, string $from, string $to, MonthClose $close): RegisterDocument
    {
        $register = $this->reports->personRegister($subject, $from, $to, 'month_close');
        $title = (string) __('people.closes.pdf_title', ['month' => PeopleFormat::month($from)]);
        $cover = $register->cover;
        $cover['title'] = $title;
        $cover['facts'][] = [(string) __('people.closes.version'), (string) $close->version];
        $cover['facts'][] = [(string) __('people.closes.chain_point'), $close->register_seq === null ? '—' : '#'.$close->register_seq.' · '.substr((string) $close->register_hash, 0, 16).'…'];
        $cover['note'] = (string) __('people.closes.pdf_note');

        return new RegisterDocument(
            kind: 'month_close',
            title: $title,
            basename: $title.' '.$subject->name,
            cover: $cover,
            kpis: $register->kpis,
            sections: $register->sections,
            headers: $register->headers,
            rows: $register->rows,
            notes: [(string) __('people.closes.pdf_answer'), ...$register->notes],
        );
    }

    /**
     * @param  callable(MonthClose): void  $change
     *
     * @throws AuthorizationException
     */
    private function answer(User $actor, MonthClose $close, callable $change, string $event): MonthClose
    {
        if ($actor->id !== $close->user_id) {
            throw new AuthorizationException;
        }

        $answered = DB::transaction(function () use ($close, $change): MonthClose {
            $locked = MonthClose::query()->whereKey($close->id)->lockForUpdate()->firstOrFail();
            $change($locked);

            return $locked;
        });

        activity(self::LOG)
            ->causedBy($actor)
            ->performedOn($answered)
            ->event($event)
            ->withProperties(['month' => $answered->monthKey(), 'version' => $answered->version, 'user_id' => $answered->user_id, 'note' => $answered->disagreement_note])
            ->log("month_close.{$event}");

        return $answered;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private static function bounds(CarbonImmutable $month): array
    {
        $zone = LocalTime::timezone();

        return [
            CarbonImmutable::parse($month->toDateString(), $zone)->startOfMonth()->utc(),
            CarbonImmutable::parse($month->toDateString(), $zone)->endOfMonth()->utc(),
        ];
    }
}
