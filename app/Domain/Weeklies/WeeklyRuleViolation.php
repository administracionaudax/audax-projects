<?php

namespace App\Domain\Weeklies;

use DomainException;

/**
 * Una regla de la weekly impide la operación (D-150). El controlador la convierte en un error de
 * validación con userMessage().
 */
final class WeeklyRuleViolation extends DomainException
{
    public const string CYCLE_CLOSED = 'cycle_closed';

    public const string NOT_PARTICIPANT = 'not_participant';

    public const string EXEMPT = 'exempt';

    public const string ALREADY_ACTIVE = 'already_active';

    public function __construct(public readonly string $rule)
    {
        parent::__construct($rule);
    }

    public function userMessage(): string
    {
        return __("weeklies.errors.{$this->rule}");
    }
}
