<?php

namespace Tests\Feature\Reports;

use App\Domain\HourBanks\Events\HourBankOverageRecorded;
use App\Domain\HourBanks\Events\HourBankThresholdReached;
use App\Enums\HourBankStatus;
use App\Enums\TimeEntryStatus;
use App\Models\Client;
use App\Models\Department;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TaskType;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Escenario CALCULADO A MANO de los informes de R2 (cliente, proyecto, facturación y PDF de bolsa).
 * Semana del 21 al 27/09/2026 (hoy, viernes 25). Cliente «Bodega Ñandú» (60 €/h) con:
 *
 * NAN-WEB «Web corporativa» (bolsas; gestora: Gema):
 *  - B0 «Bolsa 2025» 300 min, sin precio, RENOVADA por B1. E0: Ana 300 min aprobados el 10/08
 *    (instantáneas 50 €/h y 20 €/h) en T0 «Diseño 2025».
 *  - B1 «Bolsa Diseño ñ» 600 min, precio 1000 €, tarifa 70 €/h (renueva a B0):
 *    · T1 «Diseño de la home» (Diseño UI, estimada 500, vence el 24/09) con S1 «Versión móvil»
 *      (Diseño UI, 200) y S2 «Versión escritorio» (Maquetación, sin estimar),
 *    · T2 «Maquetación» (Maquetación, 240, asignada a Luis, completada el 24/09),
 *    · E1: Ana 300 min aprobados el 22/09 en S1 (instantáneas 55 y 20) «¿Qué tal? ¡Sí! 12 €»,
 *      E2: Luis 400 min bloqueados el 23/09 en T2 (instantáneas 70 y 30),
 *      E3: Ana 90 min en borrador el 24/09 en T1.
 *    Exceso (D-019, D-035): la bloqueada E2 no cambia nunca (sus 400 van dentro), así que a E1
 *    le quedan 200 dentro y 100 de exceso, y E3 va entera en exceso → B1: 790 consumidos, 600
 *    dentro y 190 de exceso.
 *  - Hitos: M3 «Arranque» (01/09, completado), M1 «Entrega de diseño» (20/09, vencido) y
 *    M2 «Lanzamiento» (15/10).
 * NAN-CAMP «Campaña otoño» (por horas, sin tarifa: la del cliente; gestora: Olga): T4 «Plan de
 *  medios»; E4: Marta (Desarrollo) 120 min aprobados el 22/09 (instantáneas 58 y 25); E5: Ana 30 min
 *  NO facturables en borrador el 25/09.
 * OTR-WEB (otro cliente): E6: Ana 45 min el 23/09.
 *
 * Personas: Ana (Diseño, coste 20, tarifa 50), Luis (Diseño, coste 30), Marta (Desarrollo, coste
 * 25); Raúl, responsable de Diseño; Gema, gestora de NAN-WEB; Olga, gestora de NAN-CAMP.
 *
 * Cifras de la semana para el cliente (admin): imputadas 940, facturables 910, exceso 190,
 * ingreso 1000 × 600/600 + 100 × 55/60 (el exceso de E1, aprobada, a su tarifa congelada: D-043,
 * BIZ-01) + 90 × 70/60 (E3, borrador, a la vigente) + 120 × 58/60 = 1312,67 €, coste 100 + 200 +
 * 30 + 50 + 10 = 390,00 €, rentabilidad 922,67 € (70,29 %). NAN-WEB: 1196,67 €.
 */
final class R2Scenario
{
    public Department $design;

    public Department $development;

    public User $admin;

    public User $raul;

    public User $ana;

    public User $luis;

    public User $marta;

    public User $gema;

    public User $olga;

    public Client $client;

    public Client $otherClient;

    public Project $web;

    public Project $campaign;

    public Project $other;

    public HourBank $b0;

    public HourBank $b1;

    public TaskType $designType;

    public TaskType $layoutType;

    public Task $t0;

    public Task $t1;

    public Task $s1;

    public Task $s2;

    public Task $t2;

    public Task $t4;

    public TimeEntry $e0;

    public TimeEntry $e1;

    public TimeEntry $e2;

    public TimeEntry $e3;

    public TimeEntry $e4;

    public TimeEntry $e5;

    public static function build(TestCase $test): self
    {
        Event::fake([HourBankThresholdReached::class, HourBankOverageRecorded::class]);
        $test->travelTo(CarbonImmutable::parse('2026-09-25 12:00', 'Europe/Madrid'));
        TaskStatus::ensureDefaults();

        $s = new self;
        $s->design = Department::factory()->create(['name' => 'Diseño']);
        $s->development = Department::factory()->create(['name' => 'Desarrollo']);
        $s->admin = User::factory()->admin()->create(['name' => 'Admin']);
        $s->raul = User::factory()->departmentManager()->create(['name' => 'Raúl']);
        $s->design->managers()->attach($s->raul);
        $s->ana = User::factory()->employee()->create(['name' => 'Ana', 'department_id' => $s->design->id, 'hourly_cost' => '20.00', 'default_hourly_rate' => '50.00']);
        $s->luis = User::factory()->employee()->create(['name' => 'Luis', 'department_id' => $s->design->id, 'hourly_cost' => '30.00']);
        $s->marta = User::factory()->employee()->create(['name' => 'Marta', 'department_id' => $s->development->id, 'hourly_cost' => '25.00']);
        $s->gema = User::factory()->employee()->create(['name' => 'Gema']);
        $s->olga = User::factory()->employee()->create(['name' => 'Olga']);
        foreach ([$s->ana, $s->luis, $s->marta] as $person) {
            WorkSchedule::factory()->for($person)->create(['valid_from' => '2026-01-01']);
        }

        $s->designType = TaskType::factory()->create(['name' => 'Diseño UI', 'color' => '#0171FF']);
        $s->layoutType = TaskType::factory()->create(['name' => 'Maquetación', 'color' => '#179FA5']);

        $s->client = Client::factory()->create(['name' => 'Bodega Ñandú', 'default_hourly_rate' => '60.00']);
        $s->otherClient = Client::factory()->create(['name' => 'Otro cliente']);

        // NAN-WEB: bolsas, tareas, hitos y horas.
        $s->web = Project::factory()->hourBank()->create(['client_id' => $s->client->id, 'code' => 'NAN-WEB', 'name' => 'Web corporativa', 'owner_user_id' => $s->gema->id]);
        $s->web->addMember($s->ana);
        $s->web->addMember($s->luis);

        $s->b0 = HourBank::factory()->create(['project_id' => $s->web->id, 'name' => 'Bolsa 2025', 'total_minutes' => 300, 'start_date' => '2026-01-01', 'end_date' => '2026-08-31']);
        $s->t0 = Task::factory()->inBank($s->b0)->create(['title' => 'Diseño 2025']);
        $s->e0 = TimeEntry::factory()->forTask($s->t0)->on('2026-08-10')->minutes(300)->status(TimeEntryStatus::Approved)->create([
            'user_id' => $s->ana->id, 'hourly_rate_snapshot' => '50.00', 'hourly_cost_snapshot' => '20.00', 'description' => 'Horas de agosto',
        ]);
        $s->b0->refresh()->update(['status' => HourBankStatus::Renewed]);

        $s->b1 = HourBank::factory()->create(['project_id' => $s->web->id, 'name' => 'Bolsa Diseño ñ', 'total_minutes' => 600, 'price_amount' => '1000.00',
            'hourly_rate' => '70.00', 'start_date' => '2026-09-01', 'renewed_from_id' => $s->b0->id]);
        $s->t1 = Task::factory()->inBank($s->b1)->assignedTo($s->ana)->create(['title' => 'Diseño de la home', 'task_type_id' => $s->designType->id, 'estimated_minutes' => 500, 'due_date' => '2026-09-24']);
        $s->s1 = Task::factory()->subtaskOf($s->t1)->assignedTo($s->ana)->create(['title' => 'Versión móvil', 'task_type_id' => $s->designType->id, 'estimated_minutes' => 200]);
        $s->s2 = Task::factory()->subtaskOf($s->t1)->create(['title' => 'Versión escritorio', 'task_type_id' => $s->layoutType->id, 'estimated_minutes' => null, 'due_date' => '2026-09-30']);
        $s->t2 = Task::factory()->inBank($s->b1)->assignedTo($s->luis)->create(['title' => 'Maquetación', 'task_type_id' => $s->layoutType->id, 'estimated_minutes' => 240]);

        Task::factory()->milestone()->create(['project_id' => $s->web->id, 'title' => 'Entrega de diseño', 'due_date' => '2026-09-20']);
        Task::factory()->milestone()->create(['project_id' => $s->web->id, 'title' => 'Lanzamiento', 'due_date' => '2026-10-15']);
        $test->travelTo(CarbonImmutable::parse('2026-09-01 10:00', 'Europe/Madrid'));
        Task::factory()->milestone()->completed()->create(['project_id' => $s->web->id, 'title' => 'Arranque', 'due_date' => '2026-09-01']);

        $test->travelTo(CarbonImmutable::parse('2026-09-24 18:00', 'Europe/Madrid'));
        $s->t2->update(['status_id' => TaskStatus::query()->where('category', 'done')->value('id')]);
        $test->travelTo(CarbonImmutable::parse('2026-09-25 12:00', 'Europe/Madrid'));

        $s->e1 = TimeEntry::factory()->forTask($s->s1)->on('2026-09-22')->minutes(300)->status(TimeEntryStatus::Approved)->create([
            'user_id' => $s->ana->id, 'hourly_rate_snapshot' => '55.00', 'hourly_cost_snapshot' => '20.00', 'description' => '¿Qué tal? ¡Sí! 12 €',
        ]);
        $s->e2 = TimeEntry::factory()->forTask($s->t2)->on('2026-09-23')->minutes(400)->status(TimeEntryStatus::Locked)->create([
            'user_id' => $s->luis->id, 'hourly_rate_snapshot' => '70.00', 'hourly_cost_snapshot' => '30.00', 'description' => 'Maquetación de cabecera',
        ]);
        $s->e3 = TimeEntry::factory()->forTask($s->t1)->on('2026-09-24')->minutes(90)->create([
            'user_id' => $s->ana->id, 'description' => 'Borrador que no sale en el PDF',
        ]);

        // NAN-CAMP: por horas, a la tarifa del cliente.
        $s->campaign = Project::factory()->create(['client_id' => $s->client->id, 'code' => 'NAN-CAMP', 'name' => 'Campaña otoño', 'owner_user_id' => $s->olga->id]);
        $s->campaign->addMember($s->marta);
        $s->campaign->addMember($s->ana);
        $s->t4 = Task::factory()->create(['project_id' => $s->campaign->id, 'title' => 'Plan de medios']);
        $s->e4 = TimeEntry::factory()->forTask($s->t4)->on('2026-09-22')->minutes(120)->status(TimeEntryStatus::Approved)->create([
            'user_id' => $s->marta->id, 'hourly_rate_snapshot' => '58.00', 'hourly_cost_snapshot' => '25.00', 'description' => 'Plan',
        ]);
        $s->e5 = TimeEntry::factory()->forTask($s->t4)->on('2026-09-25')->minutes(30)->create([
            'user_id' => $s->ana->id, 'is_billable' => false, 'description' => 'Reunión interna',
        ]);

        // Otro cliente (no debe aparecer nunca en los informes de Bodega Ñandú).
        $s->other = Project::factory()->create(['client_id' => $s->otherClient->id, 'code' => 'OTR-WEB', 'name' => 'Otro proyecto']);
        $otherTask = Task::factory()->create(['project_id' => $s->other->id]);
        TimeEntry::factory()->forTask($otherTask)->on('2026-09-23')->minutes(45)->create(['user_id' => $s->ana->id]);

        $s->b0->refresh();
        $s->b1->refresh();

        return $s;
    }

    /**
     * Query de la semana del escenario (21-27/09).
     *
     * @param  array<string, mixed>  $extra
     */
    public static function week(array $extra = []): string
    {
        return http_build_query(['periodo' => 'semana', 'fecha' => '2026-09-21', ...$extra]);
    }
}
