<?php

namespace App\Domain\Audit;

/**
 * Elemento al que se refiere una entrada de la auditoría: su nombre legible (una plantilla de
 * lang/es/audit.php, subjects.*), el enlace a su página si sigue existiendo y si está en la
 * papelera. :person se sustituye por el nombre de la persona $personId (AuditValues).
 */
final readonly class AuditSubject
{
    /**
     * @param  array<string, string>  $replace
     */
    public function __construct(
        public string $template,
        public array $replace = [],
        public ?string $url = null,
        public bool $deleted = false,
        public ?int $personId = null,
    ) {}

    public static function named(string $name, ?string $url, bool $deleted = false): self
    {
        return new self('audit.subjects.named', ['name' => $name], $deleted ? null : $url, $deleted);
    }

    public function label(AuditValues $values): string
    {
        $replace = $this->replace;

        if ($this->personId !== null) {
            $replace['person'] = $values->userName($this->personId) ?? '—';
        }

        $label = __($this->template, $replace);

        return is_string($label) ? $label : $this->template;
    }
}
