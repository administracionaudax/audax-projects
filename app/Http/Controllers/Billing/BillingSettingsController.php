<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\Holded\HoldedConnection;
use App\Domain\Billing\Holded\HoldedSync;
use App\Domain\Weeklies\AppModules;
use App\Enums\AppModule;
use App\Http\Controllers\Controller;
use App\Jobs\SyncHolded;
use App\Models\HoldedSyncRun;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ajustes de Facturación (Fase 12, F1; D-383 y D-387): los datos fiscales del emisor (Audax, ajuste
 * `billing_issuer`), el estado de la conexión con Holded (sin la clave) y las últimas
 * sincronizaciones, con «Sincronizar ahora» para los admins.
 */
class BillingSettingsController extends Controller
{
    /** Campos del emisor (PLAN-FACTURACION §6.2, company_billing_settings). */
    public const array ISSUER_FIELDS = ['legal_name', 'tax_id', 'address', 'postal_code', 'city', 'province', 'country_code', 'registry', 'iban', 'email', 'phone'];

    public function edit(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('billing/settings', [
            'issuer' => self::issuer(),
            'holded' => [
                ...HoldedConnection::summary(),
                'running' => HoldedSync::busy(),
                // En modo de prueba (D-239) la sincronización de la noche no corre.
                'scheduled' => AppModules::enabled(AppModule::Billing),
            ],
            'runs' => HoldedSyncRun::query()->with('user:id,name')->orderByDesc('started_at')->orderByDesc('id')->limit(10)->get()
                ->map(fn (HoldedSyncRun $run): array => [
                    'id' => $run->id,
                    'trigger' => $run->trigger,
                    'user' => $run->user?->name,
                    'status' => $run->status,
                    'stats' => $run->stats ?? [],
                    'error' => $run->error,
                    'started_at' => $run->started_at->utc()->toIso8601ZuluString(),
                    'finished_at' => $run->finished_at?->utc()->toIso8601ZuluString(),
                ])->values()->all(),
            'can' => ['sync' => $user->can('sync-holded')],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'legal_name' => ['nullable', 'string', 'max:200'],
            'tax_id' => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9 .\-]+$/'],
            'address' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:16'],
            'city' => ['nullable', 'string', 'max:120'],
            'province' => ['nullable', 'string', 'max:120'],
            'country_code' => ['required', 'string', 'size:2', 'alpha'],
            'registry' => ['nullable', 'string', 'max:255'],
            'iban' => ['nullable', 'string', 'max:42', 'regex:/^[A-Za-z]{2}[0-9]{2}[A-Za-z0-9 ]{10,38}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
        ]);

        $issuer = [];
        foreach (self::ISSUER_FIELDS as $field) {
            $value = isset($data[$field]) ? trim((string) $data[$field]) : null;
            $issuer[$field] = $value === '' ? null : $value;
        }
        $issuer['country_code'] = strtoupper((string) $issuer['country_code']);
        if ($issuer['tax_id'] !== null) {
            $issuer['tax_id'] = strtoupper((string) preg_replace('/[\s.\-]/', '', $issuer['tax_id']));
        }
        if ($issuer['iban'] !== null) {
            $issuer['iban'] = strtoupper((string) preg_replace('/\s+/', '', $issuer['iban']));
        }

        $old = Setting::get('billing_issuer');
        if ($old !== $issuer) {
            Setting::set('billing_issuer', $issuer);
            // Auditoría visible (D-074), como el resto de ajustes.
            activity('settings')
                ->causedBy($request->user())
                ->event('updated')
                ->withProperties(['old' => ['billing_issuer' => $old], 'attributes' => ['billing_issuer' => $issuer]])
                ->log('settings.updated');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('billing.settings.saved')]);

        return back();
    }

    /** «Sincronizar ahora» (admins): a la cola; el resultado sale en la lista de sincronizaciones. */
    public function sync(Request $request): RedirectResponse
    {
        if (! HoldedConnection::configured()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('billing.holded.errors.not_configured')]);

            return back();
        }

        if (HoldedSync::busy()) {
            Inertia::flash('toast', ['type' => 'info', 'message' => __('billing.holded.errors.busy')]);

            return back();
        }

        /** @var User $user */
        $user = $request->user();
        SyncHolded::dispatch($user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('billing.settings.sync_started')]);

        return back();
    }

    /**
     * Datos del emisor guardados (con todas las claves).
     *
     * @return array<string, string|null>
     */
    public static function issuer(): array
    {
        $stored = Setting::get('billing_issuer');
        $stored = is_array($stored) ? $stored : [];
        $issuer = [];
        foreach (self::ISSUER_FIELDS as $field) {
            $value = $stored[$field] ?? null;
            $issuer[$field] = is_string($value) ? $value : null;
        }
        $issuer['country_code'] ??= 'ES';

        return $issuer;
    }

    /**
     * La última sincronización (para los listados).
     *
     * @return array{status: string, finished_at: string|null, started_at: string}|null
     */
    public static function lastSync(): ?array
    {
        $run = HoldedSyncRun::query()->orderByDesc('started_at')->orderByDesc('id')->first();

        return $run === null ? null : [
            'status' => $run->status,
            'started_at' => $run->started_at->utc()->toIso8601ZuluString(),
            'finished_at' => $run->finished_at?->utc()->toIso8601ZuluString(),
        ];
    }
}
