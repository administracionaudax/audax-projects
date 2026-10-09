<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\HoldedClientCreator;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\HoldedContact;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * «Crear cliente» desde un contacto de Holded sin cliente (D-430, cambia D-387), en «Por revisar» y
 * en el directorio de Ajustes:
 * - GET (JSON): los datos del diálogo ya rellenos y los clientes que ya podrían ser él (mismo NIF o
 *   nombre muy parecido), para casarlo con uno de ellos en vez de crear;
 * - POST: crea el cliente activo con su ficha fiscal y casa el contacto «a mano». Si hay un cliente
 *   con el mismo NIF o un nombre muy parecido, solo con `confirmed` (lo has visto y es otro).
 * Quién: view-billing (en la ruta) y, además, quien puede crear clientes (ClientPolicy::create,
 * D-022: admins y responsables).
 */
class HoldedContactClientController extends Controller
{
    use AuthorizesRequests;

    public function show(HoldedContact $contact, HoldedClientCreator $creator): JsonResponse
    {
        $this->authorize('create', Client::class);
        abort_unless($contact->client_id === null, 409, __('billing_rules.create_client.already_matched'));

        return response()->json($creator->draft($contact));
    }

    public function store(Request $request, HoldedContact $contact, HoldedClientCreator $creator): RedirectResponse
    {
        $this->authorize('create', Client::class);
        abort_unless($contact->client_id === null, 409, __('billing_rules.create_client.already_matched'));

        $clean = function (mixed $value): ?string {
            return is_string($value) && trim($value) !== '' ? trim($value) : null;
        };
        $request->merge([
            'name' => $clean($request->input('name')),
            'tax_id' => ($taxId = $clean($request->input('tax_id'))) === null ? null : Str::upper((string) preg_replace('/[\s.\-]/', '', $taxId)),
            'email' => ($email = $clean($request->input('email'))) === null ? null : Str::lower($email),
            'country_code' => ($country = $clean($request->input('country_code'))) === null ? 'ES' : Str::upper($country),
            ...collect(['legal_name', 'address', 'postal_code', 'city', 'province'])->mapWithKeys(fn (string $field): array => [$field => $clean($request->input($field))])->all(),
        ]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && Client::query()->whereRaw('lower(name) = ?', [mb_strtolower($value)])->exists()) {
                    $fail(__('clients.errors.name_taken'));
                }
            }],
            'tax_id' => ['nullable', 'string', 'max:32', 'regex:/^[A-Z0-9]+$/'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'address' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:16'],
            'city' => ['nullable', 'string', 'max:120'],
            'province' => ['nullable', 'string', 'max:120'],
            'country_code' => ['required', 'string', 'size:2', 'alpha'],
            'confirmed' => ['sometimes', 'boolean'],
        ]);

        // Ya hay un cliente con ese NIF o un nombre muy parecido: solo si se confirma que es otro.
        $candidates = $creator->candidates($contact, $data['name'], $data['tax_id'] ?? null);
        if ($candidates !== [] && ! $request->boolean('confirmed')) {
            throw ValidationException::withMessages(['candidates' => __('billing_rules.create_client.duplicates', ['clients' => implode(', ', array_column($candidates, 'name'))])]);
        }

        /** @var User $user */
        $user = $request->user();
        $client = $creator->create($contact, [
            'name' => (string) $data['name'],
            'tax_id' => $data['tax_id'] ?? null,
            'email' => $data['email'] ?? null,
            'legal_name' => $data['legal_name'] ?? null,
            'address' => $data['address'] ?? null,
            'postal_code' => $data['postal_code'] ?? null,
            'city' => $data['city'] ?? null,
            'province' => $data['province'] ?? null,
            'country_code' => (string) $data['country_code'],
        ], $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('billing_rules.create_client.created', ['client' => $client->name, 'contact' => $contact->name])]);

        return back();
    }
}
