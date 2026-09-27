<?php

namespace Tests\Feature\Reports;

use App\Domain\HourBanks\Events\HourBankOverageRecorded;
use App\Domain\HourBanks\Events\HourBankThresholdReached;
use App\Enums\TimeEntryStatus;
use App\Models\Client;
use App\Models\Department;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Escenario calculado a mano de R3: el mismo de tests/Feature/Reports/MetricsTest.php (semana del
 * 21 al 27/09/2026), más un gestor de proyecto y un cliente para la matriz de permisos (D-044).
 *
 * - Diseño: Ana (8 h/día, coste 20 €/h, tarifa 50 €/h) y Luis (4 h/día, coste 30 €/h). Responsable: Raúl.
 * - «Por horas» (cliente a 60 €/h): Ana 300 min aprobados el 22 (instantáneas 55 y 20 €/h) y 120 min
 *   en borrador el 23. Tarea estimada en 300 min, completada el 23.
 * - «Bolsa» 600 min, precio 1000 €, tarifa 70 €/h: Luis 500 min el 22 y 200 min el 24 (100 de exceso).
 * - «Interno»: Luis 60 min no facturables el 25.
 * - «Precio cerrado» 3000 €, presupuesto 1200 min: Ana 240 min el 24 (y 60 min el 10/08).
 * - Gestor: Gema, empleada sin departamento, gestora del proyecto de la bolsa (ve sus 700 min).
 * Totales de la semana: 1420 min imputados, 1360 facturables, 100 de exceso.
 */
final class R3Scenario
{
    public function __construct(
        public readonly Department $design,
        public readonly User $ana,
        public readonly User $luis,
        public readonly User $admin,
        public readonly User $head,
        public readonly User $manager,
        public readonly User $client,
        public readonly Project $tm,
        public readonly Task $tmTask,
        public readonly HourBank $bank,
        public readonly Project $internal,
        public readonly Project $fixed,
    ) {}

    public static function build(TestCase $test): self
    {
        Event::fake([HourBankThresholdReached::class, HourBankOverageRecorded::class]);
        $test->travelTo(CarbonImmutable::parse('2026-09-25 12:00', 'Europe/Madrid'));
        TaskStatus::ensureDefaults();

        $design = Department::factory()->create(['name' => 'Diseño']);
        $ana = User::factory()->employee()->create(['name' => 'Ana', 'department_id' => $design->id, 'hourly_cost' => '20.00', 'default_hourly_rate' => '50.00']);
        $luis = User::factory()->employee()->create(['name' => 'Luis', 'department_id' => $design->id, 'hourly_cost' => '30.00']);
        WorkSchedule::factory()->for($ana)->create(['valid_from' => '2026-01-01']);
        WorkSchedule::factory()->for($luis)->create(['valid_from' => '2026-01-01', 'mon_minutes' => 240, 'tue_minutes' => 240, 'wed_minutes' => 240, 'thu_minutes' => 240, 'fri_minutes' => 240]);
        $admin = User::factory()->admin()->create(['name' => 'Adela']);
        $head = User::factory()->departmentManager()->create(['name' => 'Raúl']);
        $design->managers()->attach($head);

        $client = Client::factory()->create(['default_hourly_rate' => '60.00', 'name' => 'Cliente Uno']);
        $tm = Project::factory()->create(['client_id' => $client->id, 'billing_type' => 'time_and_materials', 'hourly_rate' => null, 'name' => 'Por horas', 'code' => 'TM']);
        $tmTask = Task::factory()->create(['project_id' => $tm->id, 'estimated_minutes' => 300, 'assignee_user_id' => $ana->id, 'title' => 'Maquetación']);
        TimeEntry::factory()->forTask($tmTask)->on('2026-09-22')->minutes(300)->create([
            'user_id' => $ana->id, 'status' => TimeEntryStatus::Approved,
            'hourly_rate_snapshot' => '55.00', 'hourly_cost_snapshot' => '20.00',
            'description' => 'Primera versión',
        ]);
        TimeEntry::factory()->forTask($tmTask)->on('2026-09-23')->minutes(120)->create(['user_id' => $ana->id, 'description' => 'Ajustes']);
        $test->travelTo(CarbonImmutable::parse('2026-09-23 12:00', 'Europe/Madrid'));
        $tmTask->update(['status_id' => TaskStatus::query()->where('category', 'done')->value('id')]);
        $test->travelTo(CarbonImmutable::parse('2026-09-25 12:00', 'Europe/Madrid'));

        $bank = HourBank::factory()->create(['total_minutes' => 600, 'price_amount' => '1000.00', 'hourly_rate' => '70.00', 'name' => 'Bolsa anual']);
        $bank->project->update(['code' => 'BOL', 'name' => 'Bolsa']);
        $bankTask = Task::factory()->inBank($bank)->create(['title' => 'Soporte']);
        TimeEntry::factory()->forTask($bankTask)->on('2026-09-22')->minutes(500)->create(['user_id' => $luis->id]);
        TimeEntry::factory()->forTask($bankTask)->on('2026-09-24')->minutes(200)->create(['user_id' => $luis->id]);

        $internal = Project::factory()->internal()->create(['name' => 'Interno', 'code' => 'INT']);
        $internalTask = Task::factory()->create(['project_id' => $internal->id, 'is_billable' => false, 'title' => 'Reunión']);
        TimeEntry::factory()->forTask($internalTask)->on('2026-09-25')->minutes(60)->create(['user_id' => $luis->id, 'is_billable' => false]);

        $fixed = Project::factory()->fixedPrice()->create(['fixed_price_amount' => '3000.00', 'budget_minutes' => 1200, 'name' => 'Precio cerrado', 'code' => 'FIX']);
        $fixedTask = Task::factory()->create(['project_id' => $fixed->id, 'estimated_minutes' => 600, 'title' => 'App']);
        TimeEntry::factory()->forTask($fixedTask)->on('2026-09-24')->minutes(240)->create(['user_id' => $ana->id]);
        TimeEntry::factory()->forTask($fixedTask)->on('2026-08-10')->minutes(60)->create(['user_id' => $ana->id]);

        $manager = User::factory()->employee()->create(['name' => 'Gema']);
        $bank->project->addMember($manager, isManager: true);
        $clientUser = User::factory()->client()->create(['client_id' => $client->id]);

        return new self($design, $ana, $luis, $admin, $head, $manager, $clientUser, $tm, $tmTask, $bank->refresh(), $internal, $fixed);
    }

    /**
     * Query de la semana del escenario con otros parámetros.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public static function week(array $extra = []): array
    {
        return ['periodo' => 'semana', 'fecha' => '2026-09-21', ...$extra];
    }
}
