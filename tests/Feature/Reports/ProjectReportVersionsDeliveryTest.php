<?php

use App\Domain\Reports\Delivery\ReportVersion;
use App\Mail\ReportDeliveryMail;
use App\Models\ReportDelivery;
use App\Models\ReportSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Reports\R2Scenario;

/*
| La versión del informe de proyecto (D-240 a D-242) en «Enviar por correo» y en «Programar envío»:
| viaja en la query del ReportRequest (?version=interno|cliente), se valida, se guarda en el envío y
| en el envío programado, y el fichero que sale es el de esa versión, generado con los permisos de
| quien lo envía. Sin versión (los envíos programados de antes), el interno.
*/

beforeEach(function () {
    $this->s = R2Scenario::build($this);
    Mail::fake();

    $this->payload = fn (array $query = [], array $overrides = []): array => array_replace([
        'request' => ['kind' => 'project', 'route_params' => ['project' => $this->s->web->id], 'query' => ['periodo' => 'semana', 'fecha' => '2026-09-21', ...$query]],
        'title' => 'Informe de NAN-WEB',
        'formats' => ['pdf', 'xlsx'],
        'recipient_user_ids' => [],
        'recipient_emails' => ['cliente@example.com'],
        'subject' => null,
        'message' => null,
    ], $overrides);

    $this->schedule = fn (array $query = []): array => ($this->payload)($query, [
        'relative_period' => 'previous',
        'frequency' => 'weekly',
        'run_date' => null,
        'weekday' => 1,
        'month_day' => null,
        'time' => '08:00',
    ]);

    // Lo que sale en el correo: el título del informe y los nombres de los ficheros.
    $this->sent = function (): array {
        $mails = Mail::sent(ReportDeliveryMail::class);
        expect($mails)->toHaveCount(1);
        $mail = $mails->first();

        $files = [];
        foreach ($mail->files as $file) {
            $files[$file->format->value] = $file->filename;
        }

        return [$mail->reportTitle, $files];
    };
});

test('enviar por correo la versión para el cliente: se guarda en el envío y sale ese fichero', function () {
    $this->actingAs($this->s->admin)->post('/informes/enviar', ($this->payload)(['version' => 'cliente']))->assertRedirect()->assertSessionHasNoErrors();

    expect(ReportDelivery::query()->sole()->request['query']['version'])->toBe('cliente');

    [$title, $files] = ($this->sent)();
    expect($title)->toStartWith('Informe de proyecto para el cliente · NAN-WEB')
        ->and($files['pdf'])->toStartWith('informe-proyecto-cliente-nan-web')
        ->and($files['xlsx'])->toStartWith('informe-para-el-cliente-de-nan-web');
});

test('enviar por correo sin versión es el interno (como siempre)', function () {
    $this->actingAs($this->s->admin)->post('/informes/enviar', ($this->payload)())->assertSessionHasNoErrors();

    [$title, $files] = ($this->sent)();
    expect($title)->toStartWith('Informe de proyecto · NAN-WEB')
        ->and($files['pdf'])->toStartWith('informe-proyecto-nan-web')
        ->and($files['xlsx'])->toStartWith('informe-completo-de-nan-web');
});

test('la versión se valida y solo existe en el informe de proyecto', function (array $request) {
    $this->actingAs($this->s->admin)
        ->post('/informes/enviar', ($this->payload)([], ['request' => $request]))
        ->assertSessionHasErrors('request');

    expect(ReportDelivery::query()->count())->toBe(0);
})->with([
    'versión desconocida' => fn (): array => ['kind' => 'project', 'route_params' => ['project' => $this->s->web->id], 'query' => ['version' => 'secreta']],
    'en otro informe' => fn (): array => ['kind' => 'client', 'route_params' => ['client' => $this->s->client->id], 'query' => ['version' => 'cliente']],
]);

test('quien solo ve las horas de su equipo no envía ni programa la versión para el cliente', function () {
    $raul = $this->s->raul;

    $this->actingAs($raul)->post('/informes/enviar', ($this->payload)(['version' => 'cliente']))->assertForbidden();
    $this->actingAs($raul)->post('/informes/envios', ($this->schedule)(['version' => 'cliente']))->assertForbidden();
    // El interno sí (con las horas de su equipo).
    $this->actingAs($raul)->post('/informes/enviar', ($this->payload)(['version' => 'interno']))->assertSessionHasNoErrors();

    expect(ReportSchedule::query()->count())->toBe(0);
});

test('programar el envío de la versión para el cliente: se guarda y cada envío sale con ella', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-25 10:00', 'UTC'));
    $this->actingAs($this->s->gema)->post('/informes/envios', ($this->schedule)(['version' => 'cliente']))->assertSessionHasNoErrors();

    $schedule = ReportSchedule::query()->sole();
    expect($schedule->request['query'][ReportVersion::QUERY_KEY])->toBe('cliente');

    // La lista y el detalle enseñan la versión.
    $this->actingAs($this->s->gema)->get("/informes/envios/{$schedule->id}")
        ->assertInertia(fn (Assert $page) => $page->where('schedule.version', 'cliente')->where('schedule.request.query.version', 'cliente'));
    $this->actingAs($this->s->gema)->get('/informes/envios')
        ->assertInertia(fn (Assert $page) => $page->where('schedules.0.version', 'cliente'));

    // El lunes 28 a las 08:00 de Madrid: la semana anterior (21-27/09), en la versión para el cliente.
    $this->travelTo(CarbonImmutable::parse('2026-09-28 06:05', 'UTC'));
    $this->artisan('reports:send-scheduled')->assertSuccessful();

    $delivery = ReportDelivery::query()->sole();
    expect($delivery->request['query']['version'])->toBe('cliente')
        ->and($delivery->request['query']['fecha'])->toBe('2026-09-21');

    [$title, $files] = ($this->sent)();
    expect($title)->toBe('Informe de proyecto para el cliente · NAN-WEB · Semana del 21/09/2026 al 27/09/2026')
        ->and($files['pdf'])->toStartWith('informe-proyecto-cliente-nan-web');

    // Al editarlo se puede cambiar a la interna.
    $this->actingAs($this->s->gema)->put("/informes/envios/{$schedule->id}", ($this->schedule)(['version' => 'interno']))->assertSessionHasNoErrors();
    expect($schedule->refresh()->request['query']['version'])->toBe('interno');
});

test('un envío programado de antes (sin versión) sigue siendo el interno', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-28 06:05', 'UTC'));
    ReportSchedule::factory()->create([
        'owner_user_id' => $this->s->admin->id,
        'title' => 'Informe de NAN-WEB',
        'request' => ['kind' => 'project', 'route_params' => ['project' => $this->s->web->id], 'query' => ['periodo' => 'semana', 'fecha' => '2026-09-14']],
        'formats' => ['pdf'],
    ]);

    $this->artisan('reports:send-scheduled')->assertSuccessful();

    [$title] = ($this->sent)();
    expect($title)->toBe('Informe de proyecto · NAN-WEB · Semana del 21/09/2026 al 27/09/2026');
});
