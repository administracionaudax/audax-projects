<?php

namespace App\Notifications\Reports;

use App\Models\Setting;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Support\Duration;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\HtmlString;

/**
 * Resumen semanal de productividad (SPEC §10, D-047) que envía reports:weekly-digest los lunes: en
 * la app (campana) y por email (cola `mail`, AppNotification::viaQueues), con el contenido que arma
 * App\Domain\Reports\WeeklyDigest. Guarda una foto de las cifras: el texto no cambia aunque las
 * horas se sigan editando. Lleva al informe detallado de esa semana, por persona y proyecto (y, para
 * un responsable, de su equipo).
 */
class WeeklyDigestNotification extends AppNotification
{
    /**
     * @param  array{scope: 'agency'|'team', department_ids: list<int>, from: string, to: string,
     *     unlogged: list<array{user_id: int, name: string, days: list<string>}>,
     *     high: list<array{user_id: int, name: string, occupancy: float, logged_minutes: int, capacity_minutes: int}>,
     *     low: list<array{user_id: int, name: string, occupancy: float, logged_minutes: int, capacity_minutes: int}>,
     *     banks: list<array{id: int, project_id: int, name: string, consumed_pct: float, remaining_minutes: int, overage_minutes: int}>,
     *     overdue: list<array{id: int, title: string, project: string, assignee: string|null, due_date: string}>,
     *     overdue_count: int, thresholds: array{low: int, high: int}}  $digest
     */
    public function __construct(public readonly array $digest) {}

    /** Elementos que se citan por sección en el email; del resto, «y N más». */
    public const int ITEMS_IN_MAIL = 10;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function kind(): string
    {
        return 'reports.weekly_digest';
    }

    public function title(object $notifiable): string
    {
        return $this->text('reports.r3.digest.title', ['from' => $this->day($this->digest['from']), 'to' => $this->day($this->digest['to'])]);
    }

    public function body(object $notifiable): ?string
    {
        $parts = [];

        if ($this->digest['unlogged'] !== []) {
            $parts[] = $this->choice('reports.r3.digest.count_unlogged', count($this->digest['unlogged']));
        }
        if ($this->digest['high'] !== [] || $this->digest['low'] !== []) {
            $parts[] = $this->choice('reports.r3.digest.count_occupancy', count($this->digest['high']) + count($this->digest['low']));
        }
        if ($this->digest['banks'] !== []) {
            $parts[] = $this->choice('reports.r3.digest.count_banks', count($this->digest['banks']));
        }
        if ($this->digest['overdue_count'] > 0) {
            $parts[] = $this->choice('reports.r3.digest.count_overdue', $this->digest['overdue_count']);
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /**
     * El informe detallado de esa semana, por persona y proyecto. Para un responsable, filtrado por
     * los departamentos que dirige: su alcance también incluye las horas de los proyectos que
     * gestiona, de personas de fuera de su equipo, que el email no cuenta.
     */
    public function url(object $notifiable): ?string
    {
        $query = [
            'periodo' => 'semana',
            'fecha' => $this->digest['from'],
            'filas' => 'persona',
            'columnas' => 'proyecto',
        ];

        if ($this->digest['scope'] === 'team' && $this->digest['department_ids'] !== []) {
            $query['departamento'] = $this->digest['department_ids'];
        }

        return route('reports.detail', $query, absolute: false);
    }

    public function icon(): ?string
    {
        return 'gauge';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $notifiable instanceof User ? $notifiable->name : '';
        $d = $this->digest;
        $range = ['from' => $this->day($d['from']), 'to' => $this->day($d['to'])];

        $message = (new MailMessage)
            ->subject($this->text('reports.r3.digest.subject', $range))
            ->greeting($this->text('reports.r3.digest.greeting', ['name' => $name]))
            ->line($this->text($d['scope'] === 'agency' ? 'reports.r3.digest.intro_agency' : 'reports.r3.digest.intro_team', $range));

        if ($d['unlogged'] !== []) {
            $message->line($this->section('reports.r3.digest.unlogged', array_map(fn (array $person): string => $this->text('reports.r3.digest.unlogged_item', [
                'name' => $person['name'],
                'days' => implode(', ', array_map(fn (string $date): string => $this->weekday($date), $person['days'])),
            ]), $d['unlogged'])));
        }

        foreach (['high', 'low'] as $kind) {
            if ($d[$kind] !== []) {
                $message->line($this->section("reports.r3.digest.{$kind}", array_map(fn (array $person): string => $this->text('reports.r3.digest.occupancy_item', [
                    'name' => $person['name'],
                    'occupancy' => $this->percent($person['occupancy'] * 100),
                    'logged' => Duration::format($person['logged_minutes']),
                    'capacity' => Duration::format($person['capacity_minutes']),
                ]), $d[$kind]), ['threshold' => $d['thresholds'][$kind]]));
            }
        }

        if ($d['banks'] !== []) {
            $message->line($this->section('reports.r3.digest.banks', array_map(fn (array $bank): string => $this->text(
                $bank['overage_minutes'] > 0 ? 'reports.r3.digest.bank_item_overage' : 'reports.r3.digest.bank_item',
                [
                    'name' => $bank['name'],
                    'consumed' => $this->percent($bank['consumed_pct']),
                    'remaining' => Duration::format($bank['remaining_minutes']),
                    'overage' => Duration::format($bank['overage_minutes']),
                ],
            ), $d['banks'])));
        }

        if ($d['overdue_count'] > 0) {
            $message->line($this->section('reports.r3.digest.overdue', array_map(fn (array $task): string => $this->text(
                $task['assignee'] !== null ? 'reports.r3.digest.overdue_item' : 'reports.r3.digest.overdue_item_unassigned',
                [
                    'title' => $task['title'],
                    'project' => $task['project'],
                    'assignee' => (string) $task['assignee'],
                    'date' => $this->day($task['due_date'], 'd/m/Y'),
                ],
            ), $d['overdue']), ['count' => $d['overdue_count']], $d['overdue_count']));
        }

        return $message
            ->action($this->text('reports.r3.digest.action'), url((string) $this->url($notifiable)))
            ->line($this->text('reports.r3.digest.settings_hint'))
            ->salutation($this->text('reports.r3.digest.salutation', [
                'company' => (string) Setting::get('company_name', config('app.name')),
            ]));
    }

    /**
     * Una sección del email: el título en negrita y una lista (Markdown) con los primeros elementos.
     * Va como HtmlString para conservar los saltos de línea de la lista (MailMessage::line() los
     * juntaría), así que cada texto se escapa aquí: nombres y títulos nunca se interpretan como HTML
     * ni como Markdown.
     *
     * @param  list<string>  $items
     * @param  array<string, string|int>  $replace
     */
    private function section(string $titleKey, array $items, array $replace = [], ?int $total = null): HtmlString
    {
        $shown = array_slice($items, 0, self::ITEMS_IN_MAIL);
        $rest = ($total ?? count($items)) - count($shown);
        $lines = array_map(fn (string $item): string => '- '.self::escape($item), $shown);

        if ($rest > 0) {
            $lines[] = '- '.self::escape($this->text('reports.r3.digest.more', ['count' => $rest]));
        }

        return new HtmlString('**'.self::escape($this->text($titleKey, $replace))."**\n\n".implode("\n", $lines));
    }

    /**
     * Texto seguro dentro del Markdown del email: sin HTML y sin marcas de Markdown.
     *
     * - HTML: solo & < > (ENT_NOQUOTES). Las comillas no necesitan escaparse en el texto, y la
     *   entidad del apóstrofo (&#039;) se rompería al escapar «#» para el Markdown y se vería tal
     *   cual («D&#039;Amico»).
     * - Markdown: las marcas en línea y, al principio, lo que abriría otra lista dentro de la lista
     *   («- », «+ », «1. », «1) »).
     */
    public static function escape(string $text): string
    {
        $text = htmlspecialchars(trim((string) preg_replace('/\s+/u', ' ', $text)), ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $text = (string) preg_replace('/([\\\\`*_\[\]#|~])/', '\\\\$1', $text);

        return (string) preg_replace(['/^([-+])(?=\s|$)/', '/^(\d+)([.)])(?=\s|$)/'], ['\\\\$1', '$1\\\\$2'], $text);
    }

    private function day(string $date, string $format = 'd/m'): string
    {
        return CarbonImmutable::parse($date)->format($format);
    }

    private function weekday(string $date): string
    {
        return CarbonImmutable::parse($date)->settings(['locale' => 'es'])->isoFormat('dddd D/MM');
    }

    private function percent(float $value): string
    {
        return number_format(round($value), 0, ',', '.').' %';
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    private function text(string $key, array $replace = []): string
    {
        $line = __($key, $replace);

        return is_string($line) ? $line : $key;
    }

    private function choice(string $key, int $count): string
    {
        return trans_choice($key, $count, ['count' => $count]);
    }
}
