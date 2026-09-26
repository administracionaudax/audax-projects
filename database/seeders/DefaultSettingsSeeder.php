<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Ajustes por defecto del SPEC. Idempotente: solo crea los que faltan y nunca pisa valores ya editados.
 */
class DefaultSettingsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Setting::DEFAULTS as $key => $value) {
            Setting::query()->firstOrCreate(['key' => $key], ['value' => $value]);
        }

        Setting::flushCache();
    }
}
