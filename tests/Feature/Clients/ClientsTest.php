<?php

use App\Domain\HourBanks\HourBankCommitment;
use App\Domain\HourBanks\HourBankRenewal;
use App\Enums\HourBankStatus;
use App\Enums\ProjectStatus;
use App\Models\Client;
use App\Models\Department;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Clientes (SPEC §6, D-021, D-022, D-037): listado con búsqueda y filtro, alta y edición, ficha con
| proyectos, bolsas y horas, desactivación y opciones para los selectores.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 10:00:00', 'Europe/Madrid'));
    $this->admin = userWithRole('admin');
    $this->manager = userWithRole('department_manager');
    $this->employee = userWithRole('employee');
});

describe('listado', function () {
    test('lista los clientes activos con sus proyectos activos y las horas del mes, sin N+1', function () {
        $hotels = Client::factory()->create(['name' => 'Hoteles Mediterráneo', 'default_hourly_rate' => '55.00']);
        $dental = Client::factory()->create(['name' => 'Clínica Dental Sonríe']);
        Client::factory()->inactive()->create(['name' => 'Antiguo SA']);

        $web = Project::factory()->create(['client_id' => $hotels->id]);
        Project::factory()->create(['client_id' => $hotels->id, 'status' => ProjectStatus::Completed]);
        Project::factory()->create(['client_id' => $dental->id]);

        $task = Task::factory()->create(['project_id' => $web->id]);
        TimeEntry::factory()->forTask($task)->minutes(90)->on('2026-09-10')->create();
        TimeEntry::factory()->forTask($task)->minutes(30)->on('2026-09-24')->create();
        TimeEntry::factory()->forTask($task)->minutes(600)->on('2026-08-31')->create();

        $this->actingAs($this->employee)
            ->get('/clientes')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('clients/index')
                ->has('clients.data', 2)
                ->where('clients.data.0.name', 'Clínica Dental Sonríe')
                ->where('clients.data.0.active_projects_count', 1)
                ->where('clients.data.0.month_minutes', 0)
                ->where('clients.data.1.name', 'Hoteles Mediterráneo')
                ->where('clients.data.1.active_projects_count', 1)
                ->where('clients.data.1.month_minutes', 120)
                ->missing('clients.data.1.default_hourly_rate')
                ->where('filters.estado', 'activos'));
    });

    test('la tarifa solo aparece con view-financials', function () {
        Client::factory()->create(['name' => 'Hoteles', 'default_hourly_rate' => '55.00']);

        $this->actingAs($this->admin)
            ->get('/clientes')
            ->assertInertia(fn (Assert $page) => $page->where('clients.data.0.default_hourly_rate', '55.00'));

        $this->actingAs($this->manager)
            ->get('/clientes')
            ->assertInertia(fn (Assert $page) => $page->missing('clients.data.0.default_hourly_rate'));
    });

    test('busca por nombre, NIF o contacto y filtra por estado', function () {
        Client::factory()->create(['name' => 'Hoteles Mediterráneo', 'tax_id' => 'B12345678', 'contact_name' => 'Ana', 'contact_email' => 'ana@hoteles.es']);
        Client::factory()->create(['name' => 'Bodegas Lur', 'tax_id' => null, 'contact_name' => 'Iñaki', 'contact_email' => 'inaki@lur.eus']);
        Client::factory()->inactive()->create(['name' => 'Antiguo SA', 'contact_name' => null, 'contact_email' => null]);

        $names = fn (string $query) => collect($this->actingAs($this->employee)->get('/clientes'.$query)->inertiaProps('clients.data'))->pluck('name')->all();

        expect($names('?q=hotel'))->toBe(['Hoteles Mediterráneo'])
            ->and($names('?q=b1234'))->toBe(['Hoteles Mediterráneo'])
            ->and($names('?q=LUR.EUS'))->toBe(['Bodegas Lur'])
            ->and($names('?estado=inactivos'))->toBe(['Antiguo SA'])
            ->and($names('?estado=todos'))->toBe(['Antiguo SA', 'Bodegas Lur', 'Hoteles Mediterráneo'])
            ->and($names('?q=antiguo'))->toBe([]);
    });

    test('pagina de 25 en 25', function () {
        Client::factory()->count(27)->create();

        $this->actingAs($this->employee)
            ->get('/clientes?page=2')
            ->assertInertia(fn (Assert $page) => $page
                ->has('clients.data', 2)
                ->where('clients.meta.total', 27));
    });
});

describe('alta y edición', function () {
    test('un responsable crea un cliente; solo el nombre es obligatorio', function () {
        $this->actingAs($this->manager)
            ->post('/clientes', [
                'name' => '  Hoteles   Mediterráneo ',
                'tax_id' => 'b12345678',
                'contact_email' => ' Ana@Hoteles.ES ',
                'default_hourly_rate' => '99',
            ])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'success');

        $client = Client::query()->sole();

        expect($client->name)->toBe('Hoteles Mediterráneo')
            ->and($client->tax_id)->toBe('B12345678')
            ->and($client->contact_email)->toBe('ana@hoteles.es')
            ->and($client->is_active)->toBeTrue()
            // Sin view-financials, la tarifa se ignora.
            ->and($client->default_hourly_rate)->toBeNull();
    });

    test('un admin fija la tarifa, con decimales a la española', function () {
        $this->actingAs($this->admin)
            ->post('/clientes', ['name' => 'Bodegas Lur', 'default_hourly_rate' => '52,50'])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/clientes/'.Client::query()->sole()->id);

        expect(Client::query()->sole()->default_hourly_rate)->toBe('52.50');
    });

    test('valida los datos: nombre obligatorio y único, correo y tarifa', function () {
        Client::factory()->create(['name' => 'Bodegas Lur']);

        $this->actingAs($this->admin)
            ->post('/clientes', ['name' => '', 'contact_email' => 'no-es-correo', 'default_hourly_rate' => '-3'])
            ->assertSessionHasErrors(['name', 'contact_email', 'default_hourly_rate']);

        $this->actingAs($this->admin)
            ->post('/clientes', ['name' => 'BODEGAS LUR'])
            ->assertSessionHasErrors(['name']);

        expect(Client::query()->count())->toBe(1);
    });

    test('edita los datos; un responsable no cambia la tarifa que puso un admin', function () {
        $client = Client::factory()->create(['name' => 'Hoteles', 'default_hourly_rate' => '55.00']);

        $this->actingAs($this->manager)
            ->put("/clientes/{$client->id}", ['name' => 'Hoteles Mediterráneo', 'notes' => "Factura trimestral.\nPedir OC.", 'default_hourly_rate' => '1'])
            ->assertSessionHasNoErrors();

        $client->refresh();
        expect($client->name)->toBe('Hoteles Mediterráneo')
            ->and($client->notes)->toBe("Factura trimestral.\nPedir OC.")
            ->and($client->default_hourly_rate)->toBe('55.00');

        // Y queda en la auditoría (LogsDomainActivity).
        expect(DB::table('activity_log')->where('subject_type', $client->getMorphClass())->where('subject_id', $client->id)->where('event', 'updated')->exists())->toBeTrue();
    });

    test('desactivar y reactivar; nunca se borra', function () {
        $client = Client::factory()->create();

        $this->actingAs($this->admin)
            ->post("/clientes/{$client->id}/desactivar")
            ->assertInertiaFlash('toast.type', 'success');
        expect($client->fresh()?->is_active)->toBeFalse();

        $this->actingAs($this->admin)->post("/clientes/{$client->id}/reactivar");
        expect($client->fresh()?->is_active)->toBeTrue();

        $this->actingAs($this->admin)->delete("/clientes/{$client->id}")->assertMethodNotAllowed();
        expect(Client::withTrashed()->whereKey($client->id)->exists())->toBeTrue();
    });
});

describe('ficha', function () {
    beforeEach(function () {
        $this->client = Client::factory()->create(['name' => 'Hoteles Mediterráneo', 'default_hourly_rate' => '55.00']);
        $this->web = Project::factory()->hourBank()->create(['client_id' => $this->client->id, 'name' => 'Web', 'code' => 'HOT-WEB']);
        $this->mobile = Project::factory()->create(['client_id' => $this->client->id, 'name' => 'App', 'status' => ProjectStatus::Planned]);
        $this->old = Project::factory()->create(['client_id' => $this->client->id, 'name' => 'Antiguo', 'status' => ProjectStatus::Archived]);
        $this->design = Department::factory()->create(['name' => 'Diseño']);

        $this->active = HourBank::factory()->hours(10)->forDepartment($this->design)->create(['project_id' => $this->web->id, 'name' => 'Bolsa Q4', 'price_amount' => '500.00']);
        $this->exhausted = HourBank::factory()->hours(1)->create(['project_id' => $this->web->id, 'name' => 'Bolsa soporte']);
        $this->renewed = HourBank::factory()->hours(20)->create(['project_id' => $this->web->id, 'name' => 'Bolsa Q3', 'status' => HourBankStatus::Renewed, 'start_date' => '2026-06-01', 'end_date' => '2026-08-31']);
        $this->closed = HourBank::factory()->hours(5)->closed()->create(['project_id' => $this->web->id, 'name' => 'Bolsa cerrada', 'closed_remaining_minutes' => 120]);

        $task = Task::factory()->inBank($this->active)->create(['estimated_minutes' => 300]);
        TimeEntry::factory()->forTask($task)->minutes(120)->on('2026-09-02')->create();
        TimeEntry::factory()->forTask($task)->minutes(60)->on('2026-01-15')->create();
        $support = Task::factory()->inBank($this->exhausted)->create();
        TimeEntry::factory()->forTask($support)->minutes(90)->on('2026-09-20')->create();
        TimeEntry::factory()->forTask(Task::factory()->create(['project_id' => $this->mobile->id]))->minutes(45)->on('2025-12-31')->create();

        // Tarea padre con subtareas estimadas: cuentan las subtareas, no el padre.
        $parent = Task::factory()->inBank($this->active)->create(['estimated_minutes' => 999]);
        Task::factory()->subtaskOf($parent)->create(['estimated_minutes' => 60]);
        Task::factory()->subtaskOf($parent)->completed()->create(['estimated_minutes' => 600]);
    });

    test('trae datos, proyectos, bolsas abiertas con comprometidas, histórico y horas del mes y del año', function () {
        $this->actingAs($this->employee)
            ->get("/clientes/{$this->client->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('clients/show')
                ->where('client.name', 'Hoteles Mediterráneo')
                ->missing('client.default_hourly_rate')
                ->has('projects', 3)
                ->where('projects.0.name', 'Web')
                ->where('projects.1.name', 'App')
                ->where('projects.2.status', 'archived')
                ->has('projects.0.owner')
                ->has('hourBanks', 2)
                ->where('hourBanks.0.name', 'Bolsa Q4')
                ->where('hourBanks.0.consumed_minutes', 180)
                ->where('hourBanks.0.committed_minutes', (300 - 180) + 60)
                ->where('hourBanks.0.department.name', 'Diseño')
                ->where('hourBanks.0.project.code', 'HOT-WEB')
                ->missing('hourBanks.0.price_amount')
                ->where('hourBanks.1.status', 'exhausted')
                ->where('hourBanks.1.overage_minutes', 30)
                ->has('hourBankHistory', 2)
                ->where('hours.month_minutes', 210)
                ->where('hours.year_minutes', 270)
                ->where('hours.month_start', '2026-09-01')
                ->where('hours.year', 2026)
                ->where('can.update', false));
    });

    test('las horas comprometidas coinciden con las del detalle de la bolsa, también tras renovar moviendo tareas con horas (BRN-03)', function () {
        // Una tarea de 10 h con 8 h imputadas en la bolsa antigua se mueve al renovar: en la nueva
        // queda comprometida entera (lo imputado antes es de la bolsa antigua, D-039).
        $bank = HourBank::factory()->hours(10)->create(['project_id' => $this->web->id, 'name' => 'Bolsa T4', 'start_date' => '2026-09-01']);
        $moved = Task::factory()->inBank($bank)->create(['estimated_minutes' => 600]);
        TimeEntry::factory()->forTask($moved)->minutes(480)->on('2026-09-01')->create();
        // Un hito con estimación cuenta 0 (D-039).
        $milestone = Task::factory()->inBank($bank)->milestone()->create();
        DB::table('tasks')->where('id', $milestone->id)->update(['estimated_minutes' => 120]);
        $renewal = app(HourBankRenewal::class)->renew($bank->fresh(), [], true)['bank'];

        $detail = app(HourBankCommitment::class)->committedFor($renewal);
        expect($detail)->toBe(600);

        $this->actingAs($this->employee)
            ->get("/clientes/{$this->client->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('hourBanks', fn ($banks) => collect($banks)->firstWhere('id', $renewal->id)['committed_minutes'] === $detail));
    });

    test('con view-financials llegan la tarifa del cliente y los importes de las bolsas', function () {
        $this->actingAs($this->admin)
            ->get("/clientes/{$this->client->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('client.default_hourly_rate', '55.00')
                ->where('hourBanks.0.price_amount', '500.00')
                ->where('can.update', true));
    });

    test('un cliente sin proyectos se ve vacío, sin errores', function () {
        $empty = Client::factory()->create();

        $this->actingAs($this->manager)
            ->get("/clientes/{$empty->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->has('projects', 0)
                ->has('hourBanks', 0)
                ->where('hours.month_minutes', 0)
                ->where('can.update', true));
    });
});

describe('opciones para los selectores', function () {
    test('devuelve solo los clientes activos, ordenados, y el indicado en ?incluir', function () {
        $b = Client::factory()->create(['name' => 'Bodegas Lur']);
        $a = Client::factory()->create(['name' => 'Agencia Uno']);
        $gone = Client::factory()->inactive()->create(['name' => 'Antiguo SA']);

        $this->actingAs($this->employee)
            ->getJson('/clientes/opciones')
            ->assertOk()
            ->assertExactJson([
                ['id' => $a->id, 'name' => 'Agencia Uno'],
                ['id' => $b->id, 'name' => 'Bodegas Lur'],
            ]);

        $this->actingAs($this->employee)
            ->getJson("/clientes/opciones?incluir={$gone->id}")
            ->assertJsonCount(3)
            ->assertJsonFragment(['id' => $gone->id, 'name' => 'Antiguo SA']);
    });
});
