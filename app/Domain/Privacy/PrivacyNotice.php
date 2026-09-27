<?php

namespace App\Domain\Privacy;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Texto informativo de privacidad para la plantilla (SPEC §15, D-075). Se guarda en markdown en
 * los ajustes (privacy_notice) con su versión; mientras no haya texto propio se usa el borrador de
 * lang/es/privacy.php, marcado «pendiente de asesor». Cada persona interna debe leer la versión
 * vigente: si el admin cambia el texto, sube la versión y se le vuelve a mostrar el aviso.
 * El markdown se pinta SIEMPRE saneado (sin HTML crudo) en el navegador.
 */
final class PrivacyNotice
{
    /** Longitud máxima del texto (validación de /admin/privacidad). */
    public const int MAX_LENGTH = 20000;

    public function text(): string
    {
        $custom = Setting::get('privacy_notice');

        return is_string($custom) && trim($custom) !== '' ? $custom : (string) __('privacy.default_notice');
    }

    /** Sigue siendo el borrador por defecto (pendiente de asesor). */
    public function isDraft(): bool
    {
        $custom = Setting::get('privacy_notice');

        return ! is_string($custom) || trim($custom) === '';
    }

    public function version(): int
    {
        return max(1, (int) Setting::get('privacy_notice_version', 1));
    }

    /** Los clientes del portal no la ven: el texto es para la plantilla. */
    public function needsAcknowledgement(User $user): bool
    {
        return $user->isInternal() && (int) ($user->privacy_acknowledged_version ?? 0) < $this->version();
    }

    public function acknowledge(User $user): void
    {
        $user->forceFill([
            'privacy_acknowledged_version' => $this->version(),
            'privacy_acknowledged_at' => now(),
        ])->save();
    }

    /**
     * Guarda un texto nuevo (solo si cambia): sube la versión, con lo que todos vuelven a ver el
     * aviso, y lo deja en la auditoría (log «privacy»).
     */
    public function update(string $markdown, User $by): void
    {
        $markdown = trim($markdown);

        if ($markdown === trim($this->text())) {
            return;
        }

        $from = $this->version();

        DB::transaction(function () use ($markdown, $from): void {
            Setting::set('privacy_notice', $markdown);
            Setting::set('privacy_notice_version', $from + 1);
        });

        activity('privacy')
            ->causedBy($by)
            ->event('updated')
            ->withProperties(['old' => ['version' => $from], 'attributes' => ['version' => $from + 1]])
            ->log('privacy_notice.updated');
    }
}
