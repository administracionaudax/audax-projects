<?php

namespace App\Http\Requests\Settings;

use App\Domain\Notifications\NotificationCatalog;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Preferencias de notificación (/ajustes/notificaciones, D-073):
 *   events       → kind → canal (app, email, push) → activado,
 *   daily_digest → resumen diario por email en lugar de los emails sueltos.
 * Solo valida la forma. Qué se guarda lo decide NotificationPreferences::update, que ignora los
 * eventos que no existen o no se ofrecen a la persona, los obligatorios y los canales que el
 * evento no ofrece.
 *
 * La forma de cada evento se comprueba con una regla propia y no con comodines (events.*.*): las
 * claves de los eventos llevan puntos («task.due») y los mensajes propios de los comodines no se
 * aplican a claves con puntos.
 */
class NotificationSettingsRequest extends FormRequest
{
    /** Tope de eventos por envío: el catálogo tiene muchos menos; evita cuerpos enormes. */
    public const int MAX_EVENTS = 100;

    /** Lo que admite la regla boolean de Laravel. */
    private const array BOOLEANS = [true, false, 0, 1, '0', '1'];

    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->isInternal();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'events' => ['present', 'array', 'max:'.self::MAX_EVENTS, $this->eventsShape(...)],
            'daily_digest' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'events' => __('notifications.settings.attributes.events'),
            'daily_digest' => __('notifications.settings.attributes.daily_digest'),
        ];
    }

    /**
     * Cada evento: canales conocidos (app, email, push), cada uno activado o desactivado.
     *
     * @param  Closure(string): mixed  $fail
     */
    private function eventsShape(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            return;
        }

        foreach ($value as $channels) {
            if (! is_array($channels) || array_diff(array_map('strval', array_keys($channels)), NotificationCatalog::CHANNELS) !== []) {
                $fail(__('notifications.settings.errors.channels'));

                return;
            }

            foreach ($channels as $enabled) {
                if (! in_array($enabled, self::BOOLEANS, true)) {
                    $fail(__('notifications.settings.errors.value'));

                    return;
                }
            }
        }
    }

    /**
     * Los eventos validados, con sus valores como booleanos de verdad («0» → false).
     *
     * @return array<string, array<string, bool>>
     */
    public function events(): array
    {
        $validated = $this->validated('events', []);
        $events = [];

        foreach (is_array($validated) ? $validated : [] as $kind => $channels) {
            foreach (is_array($channels) ? $channels : [] as $channel => $enabled) {
                $events[(string) $kind][(string) $channel] = filter_var($enabled, FILTER_VALIDATE_BOOL);
            }
        }

        return $events;
    }

    public function dailyDigest(): bool
    {
        return filter_var($this->validated('daily_digest'), FILTER_VALIDATE_BOOL);
    }
}
