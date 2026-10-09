<?php

use App\Domain\Billing\Holded\FakeHolded;
use App\Domain\Billing\Holded\HoldedApi;
use App\Domain\Billing\Holded\HoldedSync;
use App\Enums\Permission;
use App\Models\Client;
use App\Models\HoldedSyncRun;
use App\Models\Setting;
use App\Models\User;

/*
| Utilidades de los tests de Facturación (Fase 12, F1). Holded siempre falso (HOLDED_DRIVER=fake en
| phpunit.xml): cada test monta el suyo con holdedFake() y lo sincroniza con syncHolded().
*/

/** Enciende (o apaga) el módulo `billing` (apagado por defecto). */
function enableBilling(bool $enabled = true): void
{
    Setting::set('modules', [...(array) Setting::get('modules', []), 'billing' => $enabled]);
}

/**
 * Contacto de Holded con los nombres de campo de la API v2.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function holdedContact(string $id, string $name, ?string $taxId = null, array $overrides = []): array
{
    return [
        'id' => $id,
        'name' => $name,
        'code' => $taxId,
        'email' => null,
        'type' => 'client',
        'billing_address' => ['address' => 'Calle Mayor, 1', 'city' => 'Valencia', 'postal_code' => '46001', 'province' => 'Valencia', 'country_code' => 'ES'],
        ...$overrides,
    ];
}

/**
 * Factura de Holded (API v2). Importe sin IVA; el IVA, al 21 %.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function holdedInvoice(string $id, string $number, string $contactId, string $date, string $subtotal, array $overrides = []): array
{
    $tax = bcmul($subtotal, '0.21', 2);
    $total = bcadd($subtotal, $tax, 2);

    return [
        'id' => $id,
        'document_number' => $number,
        'contact_id' => $contactId,
        'contact_name' => 'Contacto '.$contactId,
        'date' => $date,
        'due_date' => date('Y-m-d', strtotime($date.' +30 days')),
        'currency' => 'eur',
        'subtotal' => $subtotal,
        'tax' => $tax,
        'total' => $total,
        'status' => 'pending',
        'approval_status' => 'approved',
        'draft' => false,
        'payments_total' => '0.00',
        'payments_pending' => $total,
        'items' => [['name' => 'Servicio', 'units' => 1, 'price' => $subtotal, 'subtotal' => $subtotal, 'taxes' => ['s_iva_21'], 'project_id' => null]],
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $data  contacts, projects, invoices, creditNotes, payments
 */
function holdedFake(array $data = []): FakeHolded
{
    return new FakeHolded(
        $data['contacts'] ?? [],
        $data['projects'] ?? [],
        $data['invoices'] ?? [],
        $data['creditNotes'] ?? [],
        $data['payments'] ?? [],
    );
}

/**
 * Quién mira, para las matrices de permisos de las pantallas de Facturación (I1, I5 e I10): admin,
 * finanzas (view-financials), responsable, empleado, colaborador externo, cliente del portal y un
 * admin excluido de Facturación (D-245; se excluye en Ajustes antes de llamar aquí).
 *
 * @return array<string, User>
 */
function billingActors(User $excludedAdmin): array
{
    $finance = userWithRole('employee');
    $finance->givePermissionTo(Permission::ViewFinancials->value);
    $client = Client::factory()->create();

    return [
        'admin' => User::query()->whereKeyNot($excludedAdmin->id)->whereHas('roles', fn ($q) => $q->where('name', 'admin'))->orderBy('id')->firstOrFail(),
        'finanzas' => $finance,
        'responsable' => userWithRole('department_manager'),
        'empleado' => userWithRole('employee'),
        'colaborador' => User::factory()->collaborator()->create(),
        'cliente' => User::factory()->portalOf($client)->create(),
        'admin excluido' => $excludedAdmin,
    ];
}

/** Sincroniza con ese Holded (o el del contenedor). */
function syncHolded(?HoldedApi $api = null, bool $pdfs = false): HoldedSyncRun
{
    return app(HoldedSync::class)->run($api ?? app(HoldedApi::class), 'command', null, $pdfs);
}
