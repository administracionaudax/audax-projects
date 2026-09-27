<?php

use App\Enums\AbsenceType;
use App\Models\Absence;
use App\Models\Department;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\Absences\AbsenceApprovedNotification;
use App\Notifications\Absences\AbsenceCancelledNotification;
use App\Notifications\Absences\AbsenceRejectedNotification;
use App\Notifications\Absences\AbsenceRequestedNotification;
use App\Notifications\Absences\AbsenceUpdatedNotification;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/*
| Avisos de ausencias (SPEC §13, D-049): en la app (campana, contrato de AppNotification) y por
| email por la cola `mail`, con una foto de los datos (no cambian si la ausencia cambia después).
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 10:00:00', 'Europe/Madrid'));
    Setting::set('company_name', 'Audax Studio');

    $design = Department::factory()->create();
    $this->manager = User::factory()->departmentManager()->inDepartment($design)->create(['name' => 'Raúl']);
    $this->employee = User::factory()->employee()->inDepartment($design)->create(['name' => 'Elena']);
    $this->absence = Absence::factory()->for($this->employee)->between('2026-10-05', '2026-10-09')->create([
        'type' => AbsenceType::Vacation,
        'notes' => 'Viaje',
    ]);
});

test('cada aviso va a la campana y al email, por cola (el email, por la cola mail)', function (Closure $make, string $kind, string $icon, string $url) {
    /** @var AbsenceRequestedNotification $notification */
    $notification = $make->call($this);

    expect($notification)->toBeInstanceOf(ShouldQueue::class)
        ->and($notification->via($this->employee))->toBe(['database', 'mail'])
        ->and($notification->viaQueues())->toBe(['database' => 'default', 'mail' => 'mail'])
        ->and($notification->toArray($this->employee))->toMatchArray(['kind' => $kind, 'icon' => $icon, 'url' => $url]);
})->with([
    'solicitada' => [fn () => new AbsenceRequestedNotification($this->absence, $this->employee), 'absence.requested', 'calendar-plus', '/ausencias/equipo'],
    'aprobada' => [fn () => new AbsenceApprovedNotification($this->absence, $this->manager), 'absence.approved', 'calendar-check', '/ausencias'],
    'rechazada' => [fn () => new AbsenceRejectedNotification($this->absence, $this->manager, 'No'), 'absence.rejected', 'calendar-x', '/ausencias'],
    'anulada' => [fn () => new AbsenceCancelledNotification($this->absence, $this->manager, byOwner: false), 'absence.cancelled', 'calendar-off', '/ausencias'],
    'cancelada por la persona' => [fn () => new AbsenceCancelledNotification($this->absence, $this->employee, byOwner: true), 'absence.cancelled', 'calendar-off', '/ausencias/equipo'],
    'modificada' => [fn () => new AbsenceUpdatedNotification($this->absence, $this->manager, 'Vacaciones del 05/10/2026 al 16/10/2026'), 'absence.updated', 'calendar-clock', '/ausencias'],
]);

test('el email de una solicitud saluda, explica y enlaza a «Ausencias del equipo»', function () {
    $mail = (new AbsenceRequestedNotification($this->absence, $this->employee))->toMail($this->manager);

    expect($mail->subject)->toBe('Elena ha solicitado vacaciones del 05/10/2026 al 09/10/2026')
        ->and($mail->greeting)->toBe('Hola, Raúl:')
        ->and($mail->introLines)->toBe(['Elena ha solicitado vacaciones del 05/10/2026 al 09/10/2026', '«Viaje»'])
        ->and($mail->actionText)->toBe('Ver las ausencias del equipo')
        ->and($mail->actionUrl)->toBe(url('/ausencias/equipo'))
        ->and($mail->salutation)->toBe("Un saludo,\nAudax Studio");

    // Se puede renderizar (plantilla de Laravel con la identidad de la empresa).
    expect((string) $mail->render())->toContain('Elena ha solicitado vacaciones');
});

test('el email de un rechazo lleva el comentario y enlaza a «Mis ausencias»', function () {
    $mail = (new AbsenceRejectedNotification($this->absence, $this->manager, 'Es la entrega de ACME'))->toMail($this->employee);

    expect($mail->subject)->toBe('Ausencia no aprobada: Vacaciones del 05/10/2026 al 09/10/2026')
        ->and($mail->introLines)->toContain('Raúl: «Es la entrega de ACME»')
        ->and($mail->actionText)->toBe('Ver mis ausencias')
        ->and($mail->actionUrl)->toBe(url('/ausencias'));
});

test('el email de una ausencia modificada dice cómo era antes', function () {
    $mail = (new AbsenceUpdatedNotification($this->absence, $this->manager, 'Vacaciones del 05/10/2026 al 16/10/2026'))->toMail($this->employee);

    expect($mail->subject)->toBe('Raúl ha modificado tu ausencia: Vacaciones del 05/10/2026 al 09/10/2026')
        ->and($mail->introLines)->toBe([
            'Raúl ha modificado tu ausencia: Vacaciones del 05/10/2026 al 09/10/2026',
            'Antes: Vacaciones del 05/10/2026 al 16/10/2026.',
        ])
        ->and($mail->actionUrl)->toBe(url('/ausencias'));
});

test('lo que escribe la gente no cuela enlaces ni formato en el email; en la campana va tal cual', function () {
    $notes = "Firma aquí: [Portal](https://phishing.example)\n\n# *Urgente* ![x](https://phishing.example/p.png)";
    $this->absence->update(['notes' => $notes]);
    $requested = new AbsenceRequestedNotification($this->absence->fresh(), $this->employee);

    $mail = $requested->toMail($this->manager);
    $html = (string) $mail->render();

    expect($mail->introLines[1])->toBe('«Firma aquí: \\[Portal\\](https://phishing.example) # \\*Urgente\\* !\\[x\\](https://phishing.example/p.png)»')
        ->and($html)->not->toContain('href="https://phishing.example"')
        ->and($html)->not->toContain('<img src="https://phishing.example')
        ->and($html)->not->toContain('<em>Urgente</em>')
        ->and($html)->toContain('[Portal](https://phishing.example)')
        ->and($requested->toArray($this->manager)['body'])->toBe("«{$notes}»");

    $rejected = new AbsenceRejectedNotification($this->absence, $this->manager, '[Pulsa aquí](https://phishing.example) y _ya_');
    $html = (string) $rejected->toMail($this->employee)->render();

    expect($html)->not->toContain('href="https://phishing.example"')
        ->and($html)->not->toContain('<em>ya</em>')
        ->and($html)->toContain('[Pulsa aquí](https://phishing.example) y _ya_');

    // También los nombres (del saludo y de las frases).
    $this->employee->update(['name' => '[Elena](https://phishing.example)']);
    $html = (string) (new AbsenceRequestedNotification($this->absence->fresh(), $this->employee->fresh()))->toMail($this->manager)->render();
    expect($html)->not->toContain('href="https://phishing.example"');
});

test('el aviso guarda una foto: no cambia si la ausencia cambia o se borra', function () {
    $notification = new AbsenceApprovedNotification($this->absence, $this->manager);
    $this->absence->update(['start_date' => '2026-12-01', 'end_date' => '2026-12-02']);
    $this->absence->delete();

    $restored = unserialize(serialize($notification));

    expect($restored->title($this->employee))->toBe('Ausencia aprobada: Vacaciones del 05/10/2026 al 09/10/2026')
        ->and($restored->body($this->employee))->toBe('La ha aprobado Raúl.');
});

test('una ausencia parcial se describe con sus horas', function () {
    $partial = Absence::factory()->for($this->employee)->between('2026-10-20', '2026-10-20')->partial(150)->create(['type' => AbsenceType::Leave]);

    expect((new AbsenceRequestedNotification($partial, $this->employee))->title($this->manager))
        ->toBe('Elena ha solicitado un permiso el 20/10/2026 (2:30)');
});

test('las notificaciones se guardan en la base de datos con el contrato de la campana', function () {
    Notification::fake();
    $this->manager->notify(new AbsenceRequestedNotification($this->absence, $this->employee));

    Notification::assertSentTo($this->manager, AbsenceRequestedNotification::class, fn (AbsenceRequestedNotification $notification) => $notification->toArray($this->manager) === [
        'kind' => 'absence.requested',
        'title' => 'Elena ha solicitado vacaciones del 05/10/2026 al 09/10/2026',
        'body' => '«Viaje»',
        'url' => '/ausencias/equipo',
        'icon' => 'calendar-plus',
    ]);
});
