<?php

use App\Domain\Reports\Delivery\DeliveryAudit;
use App\Domain\Reports\Delivery\DeliveryStatus;
use App\Domain\Reports\Delivery\ExportFormat;
use App\Domain\Reports\Delivery\ReportDeliverer;
use App\Domain\Reports\Delivery\ReportFileGenerator;
use App\Domain\Reports\Delivery\Testing\FakeReportFileGenerator;
use App\Domain\Reports\Delivery\UnavailableReportFileGenerator;
use App\Jobs\SendReportDelivery;
use App\Mail\ReportDeliveryMail;
use App\Models\ReportDelivery;
use App\Models\ReportDownload;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

/*
| «Enviar por correo» (Fase 9, D-141): POST /informes/enviar. Quien puede ver el informe lo envía a
| personas activas de la app y a correos externos; el fichero se genera en la cola `mail` con sus
| permisos y sale con ReportDeliveryMail (adjunto o, si pasa de 10 MB, enlace firmado).
*/

beforeEach(function () {
    $this->fake = FakeReportFileGenerator::install();
    $this->admin = User::factory()->admin()->create(['name' => 'Ana Admin']);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function sendPayload(array $overrides = []): array
{
    return array_replace([
        'request' => ['kind' => 'detail', 'route_params' => [], 'query' => ['periodo' => 'mes', 'fecha' => '2026-09-01']],
        'title' => 'Informe detallado',
        'formats' => ['pdf'],
        'recipient_user_ids' => [],
        'recipient_emails' => ['cliente@example.com'],
        'subject' => null,
        'message' => null,
    ], $overrides);
}

it('valida formato, destinatarios, correos y el límite de 20', function (array $overrides, string $field) {
    Queue::fake();

    $this->actingAs($this->admin)
        ->post('/informes/enviar', sendPayload($overrides))
        ->assertSessionHasErrors($field);

    Queue::assertNothingPushed();
    expect(ReportDelivery::query()->count())->toBe(0);
})->with([
    'sin formato' => [['formats' => []], 'formats'],
    'CSV no se envía' => [['formats' => ['csv']], 'formats.0'],
    'sin destinatarios' => [['recipient_emails' => []], 'recipients'],
    'correo mal escrito' => [['recipient_emails' => ['no-es-un-correo']], 'recipient_emails.0'],
    'más de 20' => [['recipient_emails' => array_map(fn (int $i): string => "c{$i}@example.com", range(1, 21))], 'recipient_emails'],
    'informe desconocido' => [['request' => ['kind' => 'nada', 'route_params' => [], 'query' => []]], 'request.kind'],
    'falta el parámetro de ruta' => [['request' => ['kind' => 'client', 'route_params' => [], 'query' => []]], 'request'],
    'asunto demasiado largo' => [['subject' => str_repeat('a', 151)], 'subject'],
]);

it('suma personas y correos para el límite de 20', function () {
    Queue::fake();
    $people = User::factory()->employee()->count(5)->create()->modelKeys();

    $this->actingAs($this->admin)
        ->post('/informes/enviar', sendPayload([
            'recipient_user_ids' => $people,
            'recipient_emails' => array_map(fn (int $i): string => "c{$i}@example.com", range(1, 16)),
        ]))
        ->assertSessionHasErrors('recipients');
});

it('solo admite como destinatarias personas activas de la plantilla', function (Closure $make) {
    Queue::fake();
    $person = $make();

    $this->actingAs($this->admin)
        ->post('/informes/enviar', sendPayload(['recipient_user_ids' => [$person->id], 'recipient_emails' => []]))
        ->assertSessionHasErrors('recipient_user_ids');
})->with([
    'desactivada' => [fn () => User::factory()->employee()->inactive()->create()],
    'cliente' => [fn () => User::factory()->client()->create()],
    'colaborador externo' => [fn () => User::factory()->collaborator()->create()],
]);

it('responde 403 si quien lo envía no puede ver el informe', function (Closure $request) {
    Queue::fake();
    $employee = User::factory()->employee()->create();

    $this->actingAs($employee)
        ->post('/informes/enviar', sendPayload(['request' => $request()]))
        ->assertForbidden();

    Queue::assertNothingPushed();
})->with([
    'dirección' => [fn () => ['kind' => 'direction', 'route_params' => [], 'query' => []]],
    'informe de otra persona' => [fn () => ['kind' => 'person', 'route_params' => ['user' => User::factory()->employee()->create()->id], 'query' => []]],
    'cliente que no existe' => [fn () => ['kind' => 'client', 'route_params' => ['client' => 999999], 'query' => []]],
]);

it('un colaborador externo no puede enviar informes (403)', function () {
    Queue::fake();

    $this->actingAs(User::factory()->collaborator()->create())
        ->post('/informes/enviar', sendPayload())
        ->assertForbidden();

    $this->actingAs(User::factory()->collaborator()->create())
        ->getJson('/informes/envios/personas')
        ->assertForbidden();

    Queue::assertNothingPushed();
});

it('encola el envío en la cola mail con los datos del formulario', function () {
    Queue::fake();
    $colleague = User::factory()->employee()->create();

    $this->actingAs($this->admin)
        ->post('/informes/enviar', sendPayload([
            'formats' => ['xlsx', 'pdf'],
            'recipient_user_ids' => [$colleague->id],
            'recipient_emails' => ['  Cliente@Example.com '],
            'subject' => 'Cierre de septiembre',
            'message' => 'Te lo adjunto.',
        ]))
        ->assertRedirect()
        ->assertInertiaFlash('toast.type', 'success');

    $delivery = ReportDelivery::query()->sole();

    expect($delivery->schedule_id)->toBeNull()
        ->and($delivery->sender_user_id)->toBe($this->admin->id)
        ->and($delivery->formats)->toBe(['pdf', 'xlsx'])
        ->and($delivery->recipient_user_ids)->toBe([$colleague->id])
        ->and($delivery->recipient_emails)->toBe(['cliente@example.com'])
        ->and($delivery->subject)->toBe('Cierre de septiembre')
        ->and($delivery->status)->toBe(DeliveryStatus::Queued);

    Queue::assertPushedOn('mail', SendReportDelivery::class, fn (SendReportDelivery $job): bool => $job->deliveryId === $delivery->id);
});

it('el job genera con los permisos de quien envía y manda un correo por destinatario con el adjunto', function () {
    Mail::fake();
    $colleague = User::factory()->employee()->create(['name' => 'Berta', 'email' => 'berta@audaxstudio.com']);

    $this->actingAs($this->admin)->post('/informes/enviar', sendPayload([
        'formats' => ['pdf', 'xlsx'],
        'recipient_user_ids' => [$colleague->id],
        'recipient_emails' => ['cliente@example.com'],
        'message' => "Hola,\nte lo adjunto. [pincha](http://malo.example)",
    ]))->assertRedirect();

    expect($this->fake->generated)->toHaveCount(2)
        ->and(array_column($this->fake->generated, 'user_id'))->toBe([$this->admin->id, $this->admin->id])
        ->and($this->fake->generated[0]['format'])->toBe(ExportFormat::Pdf);

    Mail::assertSentCount(2);
    Mail::assertSent(ReportDeliveryMail::class, function (ReportDeliveryMail $mail) use ($colleague): bool {
        return $mail->hasTo($colleague->email)
            && $mail->recipientName === 'Berta'
            && $mail->senderName === 'Ana Admin'
            && $mail->hasReplyTo($this->admin->email)
            && $mail->hasSubject('Informe: Informe de prueba · detail')
            && count($mail->attachments()) === 2
            && $mail->links === [];
    });
    Mail::assertSent(ReportDeliveryMail::class, fn (ReportDeliveryMail $mail): bool => $mail->hasTo('cliente@example.com') && $mail->recipientName === null);

    $mail = new ReportDeliveryMail('Asunto', null, 'Ana Admin', null, 'Informe *detallado*', 'septiembre de 2026', "Hola,\n[pincha](http://malo.example)", [], []);
    $html = $mail->render();
    expect($html)->toContain('Ana Admin te envía este informe')
        ->toContain('Periodo: septiembre de 2026')
        ->not->toContain('href="http://malo.example"')
        ->not->toContain('<em>detallado</em>');

    $delivery = ReportDelivery::query()->sole();
    expect($delivery->status)->toBe(DeliveryStatus::Sent)
        ->and($delivery->sent_at)->not->toBeNull()
        ->and($delivery->title)->toBe('Informe de prueba · detail');

    // Los temporales del generador se borran al terminar.
    foreach ($this->fake->paths as $path) {
        expect(file_exists($path))->toBeFalse();
    }
});

it('si un fichero pasa de 10 MB, el correo lleva un enlace firmado de 7 días en lugar del adjunto', function () {
    Mail::fake();
    Storage::fake('local');
    $this->fake->sizes = ['pdf' => 200, 'xlsx' => ReportDeliverer::MAX_ATTACHMENT_BYTES + 1];

    $this->actingAs($this->admin)->post('/informes/enviar', sendPayload(['formats' => ['pdf', 'xlsx']]))->assertRedirect();

    $download = ReportDownload::query()->sole();
    Storage::disk('local')->assertExists($download->path);
    expect($download->filename)->toBe('informe.xlsx')
        ->and($download->size_bytes)->toBe(ReportDeliverer::MAX_ATTACHMENT_BYTES + 1)
        ->and($download->expires_at->diffInDays(now()->addDays(7)))->toBeLessThan(1);

    $url = null;
    Mail::assertSent(ReportDeliveryMail::class, function (ReportDeliveryMail $mail) use (&$url): bool {
        $url = $mail->links[0]['url'] ?? null;

        return count($mail->attachments()) === 1 && count($mail->links) === 1;
    });

    // El enlace funciona sin sesión (lo abre un externo) y solo con la firma.
    auth()->logout();
    $this->get((string) $url)->assertOk()->assertDownload('informe.xlsx');
    $this->get(route('reports.downloads.show', ['download' => $download->id]))->assertForbidden();

    expect(Activity::query()->where('log_name', DeliveryAudit::LOG)->where('event', 'report_downloaded')->count())->toBe(1);

    // Caducado: ya no se descarga.
    $this->travel(8)->days();
    $this->get((string) $url)->assertForbidden();
});

it('deja cada envío en la auditoría, con los correos externos', function () {
    Mail::fake();
    $colleague = User::factory()->employee()->create();

    $this->actingAs($this->admin)->post('/informes/enviar', sendPayload([
        'recipient_user_ids' => [$colleague->id],
        'recipient_emails' => ['cliente@example.com'],
    ]));

    $activity = Activity::query()->where('log_name', DeliveryAudit::LOG)->sole();

    expect($activity->event)->toBe('report_sent')
        ->and($activity->causer_id)->toBe($this->admin->id)
        ->and($activity->subject_type)->toBe((new ReportDelivery)->getMorphClass())
        ->and($activity->properties['kind'])->toBe('detail')
        ->and($activity->properties['recipient_user_ids'])->toBe([$colleague->id])
        ->and($activity->properties['recipient_emails'])->toBe(['cliente@example.com']);

    $this->actingAs($this->admin)
        ->get('/admin/auditoria?entidad=report_delivery')
        ->assertOk();
});

it('si el generador falla, el envío queda fallido con el error y en la auditoría', function () {
    Mail::fake();
    app()->instance(ReportFileGenerator::class, new UnavailableReportFileGenerator);

    $this->actingAs($this->admin)->post('/informes/enviar', sendPayload())->assertRedirect();

    $delivery = ReportDelivery::query()->sole();
    expect($delivery->status)->toBe(DeliveryStatus::Failed)
        ->and($delivery->error)->toBe(UnavailableReportFileGenerator::message());
    Mail::assertNothingSent();
    expect(Activity::query()->where('event', 'report_send_failed')->count())->toBe(1);
});

it('si al generarlo ya no puede verlo, el envío se omite sin enviar nada', function () {
    Mail::fake();
    $this->fake->deny();

    $this->actingAs($this->admin)->post('/informes/enviar', sendPayload())->assertRedirect();

    expect(ReportDelivery::query()->sole()->status)->toBe(DeliveryStatus::Skipped);
    Mail::assertNothingSent();
});

it('sin el generador real hay un respaldo que falla con un error claro', function () {
    app()->forgetInstance(ReportFileGenerator::class);
    app()->offsetUnset(ReportFileGenerator::class);
    app()->bindIf(ReportFileGenerator::class, UnavailableReportFileGenerator::class);

    expect(app(ReportFileGenerator::class))->toBeInstanceOf(UnavailableReportFileGenerator::class);
});

it('lista las personas que pueden recibir informes: activas y de la plantilla', function () {
    $active = User::factory()->employee()->create(['name' => 'Carla']);
    User::factory()->employee()->inactive()->create(['name' => 'Inactiva']);
    User::factory()->client()->create(['name' => 'Cliente']);
    User::factory()->collaborator()->create(['name' => 'Colaborador']);

    $people = $this->actingAs($this->admin)->getJson('/informes/envios/personas')->assertOk()->json('people');

    expect(array_column($people, 'name'))->toContain('Carla', 'Ana Admin')
        ->not->toContain('Inactiva', 'Cliente', 'Colaborador')
        ->and(array_keys($people[0]))->toBe(['id', 'name']);
});

it('el correo con enlace muestra el botón de descarga y su caducidad', function () {
    $mail = new ReportDeliveryMail('Asunto', 'Berta', 'Ana Admin', null, 'Informe', null, null, [], [
        ['filename' => 'informe.xlsx', 'url' => 'https://projects.audaxstudio.com/informes/descargas/x?signature=y', 'expires_at' => now()->addDays(7)->toImmutable()],
    ]);

    expect($mail->render())
        ->toContain('Hola, Berta:')
        ->toContain('Descargar informe.xlsx')
        ->toContain('href="https://projects.audaxstudio.com/informes/descargas/x?signature=y"')
        ->toContain('El enlace caduca el '.now()->addDays(7)->setTimezone('Europe/Madrid')->format('d/m/Y'));
});
