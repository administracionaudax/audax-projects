<?php

namespace App\Domain\Audit;

use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Filtros de la auditoría en la URL (D-074): ?entidad=task&persona=12&accion=updated&desde=2026-09-01&hasta=2026-09-30.
 *
 * - persona: el id de quien hizo el cambio o «sistema» (cambios sin persona: comandos y tareas
 *   programadas).
 * - desde / hasta: días de Madrid (incluidos); se convierten a instantes UTC para consultar
 *   created_at. Si llegan al revés, se intercambian.
 * - Lo que no se entiende se ignora (como en el resto de listados): no hay errores de validación.
 */
final readonly class AuditFilters
{
    public const string SYSTEM = 'sistema';

    public function __construct(
        public ?string $entity = null,
        public int|string|null $person = null,
        public ?string $action = null,
        public ?string $from = null,
        public ?string $to = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $entity = self::string($request, 'entidad');
        $action = self::string($request, 'accion');
        $person = self::string($request, 'persona');
        $from = self::date(self::string($request, 'desde'));
        $to = self::date(self::string($request, 'hasta'));

        if ($from !== null && $to !== null && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        return new self(
            entity: $entity !== null && array_key_exists($entity, AuditCatalog::ENTITIES) ? $entity : null,
            person: match (true) {
                $person === self::SYSTEM => self::SYSTEM,
                $person !== null && ctype_digit($person) && (int) $person > 0 && strlen($person) < 10 => (int) $person,
                default => null,
            },
            action: $action !== null && array_key_exists($action, AuditCatalog::ACTIONS) ? $action : null,
            from: $from,
            to: $to,
        );
    }

    /**
     * Copia sin la persona (cuando el id no es de nadie que se pueda elegir).
     */
    public function withoutPerson(): self
    {
        return new self($this->entity, null, $this->action, $this->from, $this->to);
    }

    public function isEmpty(): bool
    {
        return $this->entity === null && $this->person === null && $this->action === null
            && $this->from === null && $this->to === null;
    }

    /** Primer instante incluido (00:00 de Madrid del día «desde», en UTC). */
    public function fromUtc(): ?CarbonImmutable
    {
        return $this->from === null ? null : CarbonImmutable::parse($this->from, LocalTime::timezone())->startOfDay()->utc();
    }

    /** Primer instante excluido (00:00 de Madrid del día siguiente a «hasta», en UTC). */
    public function toUtc(): ?CarbonImmutable
    {
        return $this->to === null ? null : CarbonImmutable::parse($this->to, LocalTime::timezone())->addDay()->startOfDay()->utc();
    }

    /**
     * Valores para la página (los selectores) y para las URL (paginación y CSV).
     *
     * @return array{entidad: string|null, persona: string|null, accion: string|null, desde: string|null, hasta: string|null}
     */
    public function toArray(): array
    {
        return [
            'entidad' => $this->entity,
            'persona' => $this->person === null ? null : (string) $this->person,
            'accion' => $this->action,
            'desde' => $this->from,
            'hasta' => $this->to,
        ];
    }

    /**
     * Solo los filtros puestos, para construir una URL.
     *
     * @return array<string, string>
     */
    public function query(): array
    {
        return array_filter($this->toArray(), fn (?string $value): bool => $value !== null);
    }

    private static function string(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function date(?string $value): ?string
    {
        return $value !== null
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
            && CarbonImmutable::canBeCreatedFromFormat($value, 'Y-m-d')
            && CarbonImmutable::createFromFormat('Y-m-d', $value)?->format('Y-m-d') === $value
                ? $value
                : null;
    }
}
