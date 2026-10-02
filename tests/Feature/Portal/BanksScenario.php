<?php

namespace Tests\Feature\Portal;

use App\Domain\HourBanks\Events\HourBankOverageRecorded;
use App\Domain\HourBanks\Events\HourBankThresholdReached;
use App\Enums\TimeEntryStatus;
use App\Models\Client;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TaskType;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Escenario CALCULADO A MANO de las bolsas del portal (P1, Fase 5). Hoy: jueves 15/10/2026 en Madrid.
 *
 * Cliente «Bodega Ñandú» (ajustes por defecto: personas con su nombre y solo horas aprobadas o
 * bloqueadas) con la usuaria del portal María José López:
 *
 * NAN-WEB «Web corporativa» (bolsas):
 *  - B0 «Bolsa primer semestre» 600 min, del 01/01 al 30/06/2026, RENOVADA por B1.
 *    E0: Ana 540 aprobados el 10/03/2026 → dentro 540 (90 %).
 *  - B1 «Bolsa Diseño ñ» 1200 min (20:00) desde el 01/07/2026, renueva a B0. Entradas en orden:
 *    · E1: Ana 600 aprobados el 10/09 en T1 «Diseño de la home» (Diseño UI), «Maquetación de cabecera»,
 *    · E2: Luis 480 BLOQUEADOS el 22/09 en T2 «Maquetación» (Maquetación), «Versión móvil»,
 *    · E5: Luis 240 aprobados el 01/10 en T2, «Ajustes finales»,
 *    · E3: Ana 180 ENVIADOS el 06/10 en T1, «Enviada sin aprobar»,
 *    · E4: Ana 120 en BORRADOR el 07/10 en T1, «Borrador que no se ve».
 *    Exceso (D-019, D-035): la bloqueada E2 reserva sus 480, así que caben 720 más: E1 600 dentro;
 *    E5 120 dentro y 120 de exceso; E3 (180) y E4 (120), todo exceso. Por dentro: 1620 consumidos,
 *    1200 dentro y 420 de exceso (agotada).
 *    Lo que ve el cliente (aprobadas y bloqueadas: E1, E2 y E5): dentro 1200, exceso 120, restante
 *    0, 110 %; por mes, septiembre 1080 + 0 y octubre 120 + 120. Con las enviadas (E3 también):
 *    dentro 1200, exceso 300, 125 %; octubre 120 + 300.
 *  - B3 «Soporte 2025» 600 min, del 01/01 al 31/12/2025, CERRADA. E7: Ana 450 aprobados el 20/11/2025.
 * NAN-MKT «Campañas» (bolsas):
 *  - B2 «Bolsa Marketing» 3000 min (50:00) desde el 01/09/2026. E6: Marta 300 aprobados el 12/10
 *    en T4 «Plan de medios» (sin tipo) → dentro 300 (10 %).
 * NAN-CAMP «Soporte por horas» (sin bolsa): E8, Marta 90 aprobados el 13/10: no es de ninguna bolsa.
 *
 * Inicio: activas B2 y B1 (por código de proyecto: NAN-MKT antes que NAN-WEB); anteriores B0
 * (acaba el 30/06/2026) y B3 (31/12/2025); cerca del límite (desde el 75 %): solo B1; horas de
 * octubre en sus bolsas: E5 240 (120 de exceso) + E6 300 = 540 (con las enviadas, + E3 180 = 720).
 *
 * Otro cliente «Otro cliente» (con su usuario del portal): OTR-WEB con la bolsa BF «Bolsa ajena»
 * (600) y EF, Ana 60 aprobados el 10/10.
 */
final class BanksScenario
{
    public Client $client;

    public Client $other;

    public User $portal;

    public User $otherPortal;

    public User $ana;

    public User $luis;

    public User $marta;

    public TaskType $design;

    public TaskType $layout;

    public Project $web;

    public Project $mkt;

    public Project $camp;

    public Project $foreignProject;

    public HourBank $b0;

    public HourBank $b1;

    public HourBank $b2;

    public HourBank $b3;

    public HourBank $foreign;

    public Task $t1;

    public Task $t2;

    public Task $t4;

    public TimeEntry $e1;

    public TimeEntry $e2;

    public TimeEntry $e3;

    public TimeEntry $e4;

    public TimeEntry $e5;

    public static function build(TestCase $test): self
    {
        Event::fake([HourBankThresholdReached::class, HourBankOverageRecorded::class]);
        $test->travelTo(CarbonImmutable::parse('2026-10-15 12:00', 'Europe/Madrid'));
        TaskStatus::ensureDefaults();

        $s = new self;
        $s->ana = User::factory()->employee()->create(['name' => 'Ana García Ruiz']);
        $s->luis = User::factory()->employee()->create(['name' => 'Luis Pérez']);
        $s->marta = User::factory()->employee()->create(['name' => 'Marta Sanz']);
        $s->design = TaskType::factory()->create(['name' => 'Diseño UI', 'color' => '#0171FF']);
        $s->layout = TaskType::factory()->create(['name' => 'Maquetación', 'color' => '#179FA5']);

        $s->client = Client::factory()->create(['name' => 'Bodega Ñandú', 'default_hourly_rate' => '60.00']);
        $s->other = Client::factory()->create(['name' => 'Otro cliente']);
        $s->portal = User::factory()->portalOf($s->client)->create(['name' => 'María José López']);
        $s->otherPortal = User::factory()->portalOf($s->other)->create(['name' => 'Otra Persona']);

        $s->web = Project::factory()->hourBank()->create(['client_id' => $s->client->id, 'code' => 'NAN-WEB', 'name' => 'Web corporativa', 'hourly_rate' => '70.00']);
        $s->mkt = Project::factory()->hourBank()->create(['client_id' => $s->client->id, 'code' => 'NAN-MKT', 'name' => 'Campañas']);
        $s->camp = Project::factory()->create(['client_id' => $s->client->id, 'code' => 'NAN-CAMP', 'name' => 'Soporte por horas']);

        // B0, renovada por B1.
        $s->b0 = HourBank::factory()->renewed()->create(['project_id' => $s->web->id, 'name' => 'Bolsa primer semestre', 'total_minutes' => 600, 'start_date' => '2026-01-01', 'end_date' => '2026-06-30']);
        $t0 = Task::factory()->inBank($s->b0)->create(['title' => 'Diseño primer semestre']);
        self::entry($t0, $s->ana, '2026-03-10', 540, TimeEntryStatus::Approved, 'Primer semestre');

        // B1: la bolsa en curso, con precio y tarifa (nunca llegan al portal).
        $s->b1 = HourBank::factory()->create([
            'project_id' => $s->web->id, 'name' => 'Bolsa Diseño ñ', 'total_minutes' => 1200, 'start_date' => '2026-07-01',
            'renewed_from_id' => $s->b0->id, 'price_amount' => '1000.00', 'hourly_rate' => '75.00',
            'invoice_reference' => 'FAC-2026-017', 'notes' => 'Nota interna de la bolsa',
        ]);
        $s->t1 = Task::factory()->inBank($s->b1)->create(['title' => 'Diseño de la home', 'task_type_id' => $s->design->id]);
        $s->t2 = Task::factory()->inBank($s->b1)->create(['title' => 'Maquetación', 'task_type_id' => $s->layout->id]);
        $s->e1 = self::entry($s->t1, $s->ana, '2026-09-10', 600, TimeEntryStatus::Approved, 'Maquetación de cabecera');
        $s->e2 = self::entry($s->t2, $s->luis, '2026-09-22', 480, TimeEntryStatus::Locked, 'Versión móvil');
        $s->e5 = self::entry($s->t2, $s->luis, '2026-10-01', 240, TimeEntryStatus::Approved, 'Ajustes finales');
        $s->e3 = self::entry($s->t1, $s->ana, '2026-10-06', 180, TimeEntryStatus::Submitted, 'Enviada sin aprobar');
        $s->e4 = self::entry($s->t1, $s->ana, '2026-10-07', 120, TimeEntryStatus::Draft, 'Borrador que no se ve');

        // B3: cerrada.
        $s->b3 = HourBank::factory()->closed()->create(['project_id' => $s->web->id, 'name' => 'Soporte 2025', 'total_minutes' => 600, 'start_date' => '2025-01-01', 'end_date' => '2025-12-31']);
        $t3 = Task::factory()->inBank($s->b3)->create(['title' => 'Soporte 2025']);
        self::entry($t3, $s->ana, '2025-11-20', 450, TimeEntryStatus::Approved, 'Soporte de noviembre');

        // B2: otra bolsa activa, en otro proyecto del cliente.
        $s->b2 = HourBank::factory()->create(['project_id' => $s->mkt->id, 'name' => 'Bolsa Marketing', 'total_minutes' => 3000, 'start_date' => '2026-09-01']);
        $s->t4 = Task::factory()->inBank($s->b2)->create(['title' => 'Plan de medios']);
        self::entry($s->t4, $s->marta, '2026-10-12', 300, TimeEntryStatus::Approved, 'Plan de octubre');

        // Horas de octubre sin bolsa: no cuentan en el resumen de las bolsas.
        $t5 = Task::factory()->create(['project_id' => $s->camp->id, 'title' => 'Soporte suelto']);
        self::entry($t5, $s->marta, '2026-10-13', 90, TimeEntryStatus::Approved, 'Sin bolsa');

        // Otro cliente.
        $s->foreignProject = Project::factory()->hourBank()->create(['client_id' => $s->other->id, 'code' => 'OTR-WEB', 'name' => 'Web ajena']);
        $s->foreign = HourBank::factory()->create(['project_id' => $s->foreignProject->id, 'name' => 'Bolsa ajena', 'total_minutes' => 600, 'start_date' => '2026-09-01']);
        $tf = Task::factory()->inBank($s->foreign)->create(['title' => 'Tarea ajena']);
        self::entry($tf, $s->ana, '2026-10-10', 60, TimeEntryStatus::Approved, 'Horas de otro cliente');

        foreach ([$s->b0, $s->b1, $s->b2, $s->b3, $s->foreign] as $bank) {
            $bank->refresh();
        }

        return $s;
    }

    public static function entry(Task $task, User $user, string $date, int $minutes, TimeEntryStatus $status, string $description): TimeEntry
    {
        return TimeEntry::factory()->forTask($task)->on($date)->minutes($minutes)->status($status)->create([
            'user_id' => $user->id,
            'description' => $description,
            'hourly_rate_snapshot' => '75.00',
            'hourly_cost_snapshot' => '31.50',
        ]);
    }
}
