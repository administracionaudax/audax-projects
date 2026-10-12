<?php

use App\Domain\Billing\BillingSummary;
use App\Domain\Billing\Recurring\RecurringInvoiceGenerator;
use App\Domain\Billing\Recurring\RecurringInvoices;
use App\Domain\Weeklies\AppModules;
use App\Enums\AppModule;
use App\Enums\Permission;
use App\Enums\TimeEntryStatus;
use App\Models\Project;
use App\Models\RecurringInvoice;
use App\Models\SalesDocument;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Pantallas y permisos de E3 (PLAN-EMISION §3.2 y §6; D-450 en adelante): las recurrentes se ven con
| use-invoicing y se crean, cambian, pausan, omiten y generan con manage-billing; programar es de
| quien emite; «Crear borrador» desde el trabajo, de quien prepara. Responsables, plantilla,
| colaboradores, clientes del portal y el admin excluido de Facturación (D-245): nada.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-02-15 10:00:00', 'Europe/Madrid'));
    Storage::fake('local');
    Mail::fake();
    enableInvoicing();
    invoicingIssuer();
    $this->admin = userWithRole('admin');
    $this->client = invoicingClient(['billing_emails' => ['facturas@cliente.test']]);

    $this->finance = userWithRole('employee');
    $this->finance->givePermissionTo([Permission::ViewFinancials->value, Permission::ManageBilling->value]);
    $this->preparer = userWithRole('employee');
    $this->preparer->givePermissionTo(Permission::ViewFinancials->value);
    $this->excluded = userWithRole('admin');
    AppModules::setExcluded(AppModule::Billing, [$this->excluded->id]);

    $this->recurring = app(RecurringInvoices::class)->save(null, [
        'name' => 'Fee mensual', 'client_id' => $this->client->id, 'periodicity' => 'monthly', 'day_of_month' => 28,
        'start_date' => '2027-02-01', 'mode' => 'draft', 'series_id' => invoicingSeries('PRU')->id,
        'lines' => [invoicingLine('1', '1200', description: 'Fee de [mes]')],
    ], $this->admin);
    $this->draft = invoicingDraft($this->admin, $this->client, null, ['series_id' => invoicingSeries('PRU')->id]);

    $project = Project::factory()->create(['client_id' => $this->client->id, 'hourly_rate' => '60.00']);
    $task = Task::factory()->create(['project_id' => $project->id]);
    $this->entry = TimeEntry::factory()->forTask($task)->minutes(90)->status(TimeEntryStatus::Approved)->on('2027-02-03')
        ->create(['user_id' => $this->admin->id, 'is_billable' => true, 'hourly_rate_snapshot' => '60.00']);
});

function recurringPayload(int $clientId, array $overrides = []): array
{
    return [
        'name' => 'Mantenimiento', 'client_id' => $clientId, 'periodicity' => 'quarterly', 'day_of_month' => 1, 'start_date' => '2027-04-01',
        'end' => 'count', 'max_occurrences' => 4, 'mode' => 'draft', 'auto_send' => false,
        'lines' => [['kind' => 'item', 'description' => 'Mantenimiento del [trimestre]', 'quantity' => '1', 'unit' => 'unit', 'unit_price' => '450', 'discount_pct' => '0', 'tax_rate_id' => invoicingTax('iva_21')->id]],
        ...$overrides,
    ];
}

/**
 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
 */
function e3Requests(int $recurring, int $draft, int $client, int $entry): array
{
    return [
        'listado' => ['get', '/facturacion/recurrentes', []],
        'ficha' => ['get', "/facturacion/recurrentes/{$recurring}", []],
        'nueva' => ['get', '/facturacion/recurrentes/nueva', []],
        'crear' => ['post', '/facturacion/recurrentes', recurringPayload($client)],
        'editar' => ['get', "/facturacion/recurrentes/{$recurring}/editar", []],
        'cambiar' => ['put', "/facturacion/recurrentes/{$recurring}", recurringPayload($client, ['name' => 'Cambiada'])],
        'omitir' => ['post', "/facturacion/recurrentes/{$recurring}/omitir", ['period' => '2027-03-28', 'skip' => true]],
        'pausar' => ['post', "/facturacion/recurrentes/{$recurring}/pausar", []],
        'reanudar' => ['post', "/facturacion/recurrentes/{$recurring}/reanudar", []],
        'generar' => ['post', "/facturacion/recurrentes/{$recurring}/generar", []],
        'programar' => ['post', "/facturacion/documentos/{$draft}/programar", ['scheduled_for' => '2027-02-20', 'scheduled_send' => true]],
        'panel' => ['getJson', "/facturacion/por-facturar/clientes/{$client}/borrador", []],
        'crear borrador' => ['post', "/facturacion/por-facturar/clientes/{$client}/borrador", ['agrupar' => 'task', 'entries' => [$entry]]],
    ];
}

function e3Status(User $user, array $request): int
{
    [$method, $url, $data] = $request;

    return test()->actingAs($user)->{$method}($url, $data)->status();
}

it('admin y finanzas con manage-billing hacen todo lo de E3', function (string $who) {
    $user = $who === 'admin' ? $this->admin : $this->finance;

    foreach (e3Requests($this->recurring->id, $this->draft->id, $this->client->id, $this->entry->id) as $name => $request) {
        expect(e3Status($user, $request))->toBeIn([200, 302], "{$who}: {$name}");
    }

    expect(RecurringInvoice::query()->count())->toBe(2)
        ->and($this->recurring->refresh()->name)->toBe('Cambiada')
        ->and($this->draft->refresh()->isScheduled())->toBeTrue()
        ->and(SalesDocument::query()->where('recurring_invoice_id', $this->recurring->id)->count())->toBe(1)
        ->and(SalesDocument::query()->whereHas('lines', fn ($q) => $q->where('origin', 'hours'))->count())->toBe(1);
})->with(['admin', 'finanzas']);

it('quien solo prepara ve las recurrentes y crea borradores desde el trabajo, pero no gestiona recurrentes ni programa', function () {
    $requests = e3Requests($this->recurring->id, $this->draft->id, $this->client->id, $this->entry->id);

    foreach (['listado', 'ficha', 'panel', 'crear borrador'] as $name) {
        expect(e3Status($this->preparer, $requests[$name]))->toBeIn([200, 302], $name);
    }
    foreach (['nueva', 'crear', 'editar', 'cambiar', 'omitir', 'pausar', 'reanudar', 'generar', 'programar'] as $name) {
        expect(e3Status($this->preparer, $requests[$name]))->toBe(403, $name);
    }

    $this->actingAs($this->preparer)->get('/facturacion/recurrentes')
        ->assertInertia(fn (Assert $page) => $page->component('billing/recurring/index')->where('can_manage', false)->has('recurrences', 1));
    // Una programada ya no la edita ni la borra quien solo prepara (se emitirá sola).
    $this->actingAs($this->admin)->post("/facturacion/documentos/{$this->draft->id}/programar", ['scheduled_for' => '2027-02-20']);
    $this->actingAs($this->preparer)->get("/facturacion/documentos/{$this->draft->id}/editar")->assertForbidden();
    $this->actingAs($this->preparer)->get("/facturacion/documentos/{$this->draft->id}")
        ->assertInertia(fn (Assert $page) => $page->where('actions', ['view', 'duplicate', 'download_pdf', 'edit_non_fiscal', 'attach'])
            ->where('document.scheduled.for', '2027-02-20'));
});

it('responsables, plantilla, colaboradores, clientes del portal y el admin excluido no tocan nada', function () {
    $users = [
        'responsable' => userWithRole('department_manager'),
        'empleado' => userWithRole('employee'),
        'colaborador' => User::factory()->collaborator()->create(),
        'portal' => User::factory()->portalOf($this->client)->create(),
        'admin excluido' => $this->excluded,
    ];

    foreach ($users as $who => $user) {
        foreach (e3Requests($this->recurring->id, $this->draft->id, $this->client->id, $this->entry->id) as $name => $request) {
            expect(e3Status($user, $request))->toBeIn([302, 403, 404], "{$who}: {$name}");
        }
    }

    expect(RecurringInvoice::query()->count())->toBe(1)
        ->and($this->recurring->refresh()->isPaused())->toBeFalse()
        ->and($this->draft->refresh()->isScheduled())->toBeFalse()
        ->and(SalesDocument::query()->count())->toBe(1);
});

it('con la emisión apagada, nada de E3 existe (404)', function () {
    enableInvoicing(false);

    foreach (e3Requests($this->recurring->id, $this->draft->id, $this->client->id, $this->entry->id) as $name => $request) {
        expect(e3Status($this->admin, $request))->toBe(404, $name);
    }
});

it('las pantallas: listado con las pestañas de Facturas, ficha con el calendario y el editor', function () {
    $this->actingAs($this->admin)->get('/facturacion/recurrentes')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('billing/recurring/index')
            ->where('counts', ['activas' => 1, 'pausadas' => 0, 'acabadas' => 0, 'todas' => 1])
            ->where('recurrences.0.name', 'Fee mensual')
            ->where('recurrences.0.next_run_on', '2027-02-28')
            ->where('recurrences.0.subtotal', '1200.00')
            ->where('auto_active', true)
            ->has('views.programadas'));

    $this->actingAs($this->admin)->get("/facturacion/recurrentes/{$this->recurring->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('billing/recurring/show')
            ->has('recurring.upcoming', 12)
            ->where('recurring.upcoming.1.date', '2027-03-28')
            ->where('recurring.lines.0.description', 'Fee de [mes]'));

    // «Hacer recurrente» desde una factura: el editor llega con su cliente y sus líneas.
    $this->actingAs($this->admin)->get("/facturacion/recurrentes/nueva?desde={$this->draft->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('billing/recurring/edit')
            ->where('recurring.client_id', $this->client->id)
            ->has('recurring.lines', 1));

    // La pestaña «Recurrentes» en Facturas.
    $this->actingAs($this->admin)->get('/facturacion/facturas')->assertInertia(fn (Assert $page) => $page->where('recurring_count', 1));
});

it('la vista «Programadas» de Facturas y el aviso en Requiere atención', function () {
    $this->actingAs($this->admin)->post("/facturacion/documentos/{$this->draft->id}/programar", ['scheduled_for' => '2027-02-16'])->assertRedirect();

    $this->actingAs($this->admin)->get('/facturacion/facturas?vista=pruebas')
        ->assertInertia(fn (Assert $page) => $page->where('invoices.data.0.scheduled_for', '2027-02-16'));

    $this->travelTo(CarbonImmutable::parse('2027-02-16 07:00:00', 'Europe/Madrid'));
    app(RecurringInvoiceGenerator::class)->generateNow($this->recurring, $this->admin);
    $summary = app(BillingSummary::class)->summary($this->admin, ['key' => 'anio', 'from' => '2027-01-01', 'to' => '2027-12-31']);
    expect($summary['attention']['scheduled_today']['count'])->toBe(1)
        ->and($summary['attention']['recurring_drafts']['count'])->toBe(1)
        ->and($summary['attention']['auto_failed']['count'])->toBe(0);
});
