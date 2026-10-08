<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\BillingPanel;
use App\Domain\Billing\SoldVsActualQuery;
use App\Domain\Reports\ReportScope;
use App\Enums\BillingPaymentMethod;
use App\Enums\TaxRegime;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Reports\Concerns\BuildsReportScope;
use App\Models\Client;
use App\Models\ClientBillingProfile;
use App\Models\HoldedContact;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Facturación del cliente (Fase 12, D-381 y D-392): /clientes/{id}/facturacion con su ficha fiscal
 * (editable), sus contactos de Holded, su «Vendido frente a real» y sus facturas. Quién: view-billing
 * (en la ruta) y ver el cliente.
 */
class ClientBillingController extends Controller
{
    use AuthorizesRequests, BuildsReportScope;

    public function show(Request $request, Client $client, BillingPanel $panel): Response
    {
        $this->authorize('view', $client);

        /** @var User $user */
        $user = $request->user();
        $query = SoldVsActualQuery::fromQuery(SoldVsActualController::defaultPeriod($request->query()));
        $filters = $this->filterProps(new ReportScope($user, $query->filters));
        $filters['query'] = $query->filters->toQuery();
        $filters['can_see_financials'] = true;

        $profile = $client->billingProfile ?? new ClientBillingProfile(['client_id' => $client->id]);

        return Inertia::render('clients/billing', [
            'client' => ['id' => $client->id, 'name' => $client->name, 'is_active' => $client->is_active, 'tax_id' => $client->tax_id],
            'profile' => self::profile($profile),
            'contacts' => HoldedContact::query()->where('client_id', $client->id)->orderBy('name')->get()
                ->map(fn (HoldedContact $contact): array => [
                    'id' => $contact->id,
                    'name' => $contact->name,
                    'tax_id' => $contact->tax_id,
                    'match_method' => $contact->match_method,
                ])->values()->all(),
            'filters' => $filters,
            'panel' => $panel->forClient($client, $user, $query),
            'options' => [
                'tax_regimes' => array_map(fn (TaxRegime $regime): array => ['value' => $regime->value, 'label' => $regime->label()], TaxRegime::cases()),
                'payment_methods' => array_map(fn (BillingPaymentMethod $method): array => ['value' => $method->value, 'label' => $method->label()], BillingPaymentMethod::cases()),
            ],
            'can' => ['update' => $user->can('update', $client)],
        ]);
    }

    public function update(Request $request, Client $client): RedirectResponse
    {
        $this->authorize('view', $client);

        $data = $request->validate([
            'tax_id' => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9 .\-]+$/'],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'eu_vat_number' => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z]{2}[A-Za-z0-9 .\-]{2,30}$/'],
            'address' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:16'],
            'city' => ['nullable', 'string', 'max:120'],
            'province' => ['nullable', 'string', 'max:120'],
            'country_code' => ['required', 'string', 'size:2', 'alpha'],
            'tax_regime' => ['required', Rule::enum(TaxRegime::class)],
            'payment_method' => ['nullable', Rule::enum(BillingPaymentMethod::class)],
            'payment_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'payment_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'language' => ['required', 'string', 'in:es,en,ca,fr,de,it,pt'],
            'billing_emails' => ['nullable', 'array', 'max:10'],
            'billing_emails.*' => ['required', 'email:rfc', 'max:255'],
        ]);

        $clean = fn (?string $value): ?string => $value === null || trim($value) === '' ? null : trim($value);

        $taxId = $clean($data['tax_id'] ?? null);
        $client->tax_id = $taxId === null ? null : strtoupper((string) preg_replace('/[\s.\-]/', '', $taxId));
        $client->save();

        $profile = ClientBillingProfile::query()->firstOrNew(['client_id' => $client->id]);
        $profile->fill([
            'legal_name' => $clean($data['legal_name'] ?? null),
            'eu_vat_number' => ($vat = $clean($data['eu_vat_number'] ?? null)) === null ? null : strtoupper((string) preg_replace('/[\s.\-]/', '', $vat)),
            'address' => $clean($data['address'] ?? null),
            'postal_code' => $clean($data['postal_code'] ?? null),
            'city' => $clean($data['city'] ?? null),
            'province' => $clean($data['province'] ?? null),
            'country_code' => strtoupper((string) $data['country_code']),
            'tax_regime' => $data['tax_regime'],
            'payment_method' => $data['payment_method'] ?? null,
            'payment_days' => $data['payment_days'] ?? null,
            'payment_day' => $data['payment_day'] ?? null,
            'language' => $data['language'],
            'billing_emails' => array_values(array_unique(array_map(fn (string $email): string => mb_strtolower(trim($email)), $data['billing_emails'] ?? []))) ?: null,
        ]);
        $profile->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('billing.profile.saved')]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private static function profile(ClientBillingProfile $profile): array
    {
        return [
            'legal_name' => $profile->legal_name,
            'eu_vat_number' => $profile->eu_vat_number,
            'address' => $profile->address,
            'postal_code' => $profile->postal_code,
            'city' => $profile->city,
            'province' => $profile->province,
            'country_code' => $profile->country_code,
            'tax_regime' => $profile->tax_regime->value,
            'payment_method' => $profile->payment_method?->value,
            'payment_days' => $profile->payment_days,
            'payment_day' => $profile->payment_day,
            'language' => $profile->language,
            'billing_emails' => $profile->billing_emails ?? [],
            'complete' => $profile->isComplete(),
        ];
    }
}
