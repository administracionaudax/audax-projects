<?php

namespace App\Domain\Privacy;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ajustes de /admin/privacidad además del texto (D-075 y D-076): plazos de retención, días para
 * descargar los datos personales y umbrales de aviso de disco y adjuntos.
 *
 * Al guardar, solo se escriben los que cambian y quedan en la auditoría: los de retención y
 * exportación en el log «privacy» y los umbrales de aviso en el log «settings».
 */
final class PrivacySettings
{
    public const int EXPORT_DAYS_MIN = 1;

    public const int EXPORT_DAYS_MAX = 30;

    public const int DISK_PERCENT_MIN = 50;

    public const int DISK_PERCENT_MAX = 99;

    public const int ATTACHMENTS_GB_MIN = 1;

    public const int ATTACHMENTS_GB_MAX = 100000;

    /** Ajustes que van al log «settings» (el resto, al «privacy»). */
    private const array STORAGE_KEYS = ['disk_warning_percent', 'attachments_warning_gb'];

    public function __construct(private readonly RetentionPolicy $policy) {}

    /**
     * Valores vigentes: los plazos ya acotados por RetentionPolicy (null = sin límite).
     *
     * @return array<string, int|null>
     */
    public function current(): array
    {
        $values = [];

        foreach (RetentionPolicy::SETTINGS as $type => $key) {
            $values[$key] = $this->policy->months($type);
        }

        $attachments = Setting::get('attachments_warning_gb');

        return [
            ...$values,
            'personal_data_export_days' => $this->policy->exportDays(),
            'disk_warning_percent' => (int) Setting::get('disk_warning_percent', 85),
            'attachments_warning_gb' => is_numeric($attachments) && (int) $attachments > 0 ? (int) $attachments : null,
        ];
    }

    /**
     * Plazos de /privacidad: cada tipo de dato con sus meses (null = sin límite).
     *
     * @return list<array{type: string, months: int|null}>
     */
    public function retention(): array
    {
        return array_map(
            fn (string $type): array => ['type' => $type, 'months' => $this->policy->months($type)],
            array_keys(RetentionPolicy::SETTINGS),
        );
    }

    /**
     * Guarda los valores que cambian y los deja en la auditoría.
     *
     * @param  array<string, int|null>  $values  claves de current()
     * @return list<string> ajustes que han cambiado
     */
    public function update(array $values, User $by): array
    {
        $before = $this->current();
        $changed = [];

        foreach ($before as $key => $old) {
            if (! array_key_exists($key, $values) || $values[$key] === $old) {
                continue;
            }

            $changed[$key] = ['old' => $old, 'new' => $values[$key]];
        }

        if ($changed === []) {
            return [];
        }

        DB::transaction(function () use ($changed, $by): void {
            foreach ($changed as $key => $change) {
                Setting::set($key, $change['new']);
            }

            $privacy = array_diff_key($changed, array_flip(self::STORAGE_KEYS));
            $storage = array_intersect_key($changed, array_flip(self::STORAGE_KEYS));

            if ($privacy !== []) {
                $this->log('privacy', 'retention.updated', $privacy, $by);
            }

            if ($storage !== []) {
                $this->log('settings', 'settings.updated', $storage, $by);
            }
        });

        return array_keys($changed);
    }

    /**
     * @param  array<string, array{old: int|null, new: int|null}>  $changes
     */
    private function log(string $logName, string $description, array $changes, User $by): void
    {
        activity($logName)
            ->causedBy($by)
            ->event('updated')
            ->withProperties([
                'old' => array_map(fn (array $change): ?int => $change['old'], $changes),
                'attributes' => array_map(fn (array $change): ?int => $change['new'], $changes),
            ])
            ->log($description);
    }
}
