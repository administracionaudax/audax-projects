<?php

use App\Domain\Reports\Delivery\ReportAccess;
use App\Domain\Reports\Delivery\ReportKind;
use App\Domain\Reports\Delivery\ReportRequest;
use App\Models\AiUsage;
use App\Models\Client;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Models\WeeklyCycle;
use Inertia\Testing\AssertableInertia as Assert;

/*
| PDF, impresión, Excel y HTML del informe de la weekly (10.3, F-083, D-192) con el motor `html`
| (REPORTS_PDF_DRIVER=html en los tests), y la página «Uso de IA» (F-173 y F-180, D-193).
*/

beforeEach(function () {
    $this->acme = Client::factory()->create(['name' => 'Acme']);
    $this->beta = Client::factory()->create(['name' => 'Beta']);
    $this->cycle = WeeklyCycle::factory()->create();
    $this->cycle->forceFill(['report' => [
        'global_summary' => 'Semana intensa con dos entregas.',
        'team_risks' => ['Vacaciones de dos personas'],
        'client_updates' => [
            ['client_id' => $this->acme->id, 'client_name' => 'Acme', 'status' => 'blocked', 'executive_summary' => 'Esperando el contenido del cliente.', 'next_steps' => ['Reclamar textos'], 'milestones' => [['date' => '20/10', 'label' => 'Lanzamiento']], 'tags' => ['Web'], 'satisfaction_score' => 62, 'has_reports' => true,
                'projects' => [['project_id' => 1, 'code' => 'ACME-BH1', 'name' => 'Bolsa web', 'billing_type' => 'hour_bank', 'budget_minutes' => 600, 'consumed_minutes' => 660, 'expected_minutes' => null, 'week_minutes' => 90]]],
            ['client_id' => $this->beta->id, 'client_name' => 'Beta', 'status' => 'on_track', 'executive_summary' => 'Sin novedades reportadas esta semana.', 'next_steps' => [], 'milestones' => [], 'tags' => [], 'has_reports' => false, 'projects' => []],
        ],
    ]])->save();
    $this->employee = userWithRole('employee');
});

it('el PDF del informe lleva la portada, las cifras, el resumen, los riesgos y cada cliente', function () {
    $response = $this->actingAs($this->employee)->get("/weeklies/{$this->cycle->id}/informe/pdf");

    $response->assertOk();
    expect($response->headers->get('Content-Disposition'))->toContain('weekly-'.strtolower($this->cycle->number).'.html');
    $text = reportHtmlText($response->getContent());

    expect($text)->toContain('Weekly')
        ->toContain($this->cycle->label)
        ->toContain('Clientes 2')
        ->toContain('Bloqueados 1')
        ->toContain('Resumen global Semana intensa con dos entregas.')
        ->toContain('Riesgos detectados Vacaciones de dos personas')
        ->toContain('Acme Bloqueado')
        ->toContain('Satisfacción al cerrar: 62 / 100')
        ->toContain('Reclamar textos')
        ->toContain('20/10: Lanzamiento')
        ->toContain('ACME-BH1 Bolsa web Bolsa de horas 11:00 10:00 — 1:30')
        ->toContain('Beta En curso')
        ->toContain('No hay pasos definidos.');
    expect($response->getContent())->toContain('<td class="num overage">11:00</td>');
});

it('imprimir abre el mismo documento con el diálogo de impresión; HTML lo descarga sin él', function () {
    $print = $this->actingAs($this->employee)->get("/weeklies/{$this->cycle->id}/informe/pdf?formato=imprimir");
    $print->assertOk();
    expect($print->getContent())->toContain('window.print()');

    $html = $this->actingAs($this->employee)->get("/weeklies/{$this->cycle->id}/informe/pdf?formato=html");
    $html->assertOk()->assertHeader('Content-Type', 'text/html; charset=UTF-8');
    expect($html->headers->get('Content-Disposition'))->toContain('attachment;')->toContain('weekly-'.strtolower($this->cycle->number).'.html')
        ->and($html->getContent())->not->toContain('window.print()')
        ->and(reportHtmlText($html->getContent()))->toContain('Esperando el contenido del cliente.');
});

it('el Excel y el CSV llevan una fila por cliente', function () {
    $csv = $this->actingAs($this->employee)->get("/weeklies/{$this->cycle->id}/informe/pdf?formato=csv");
    $csv->assertOk();
    $content = $csv->streamedContent();

    expect($content)->toContain('Cliente')->toContain('Resumen ejecutivo')
        ->toContain('Acme')->toContain('Bloqueado')->toContain('Reclamar textos')->toContain('62')
        ->toContain('Beta');
});

it('«Solo mis proyectos» deja los clientes de mis proyectos (F-080)', function () {
    Project::factory()->withMembers([$this->employee])->create(['client_id' => $this->acme->id]);

    $text = reportHtmlText($this->actingAs($this->employee)->get("/weeklies/{$this->cycle->id}/informe/pdf?mios=1")->getContent());
    expect($text)->toContain('Acme Bloqueado')->not->toContain('Beta En curso')->toContain('Solo mis proyectos');

    $other = userWithRole('employee');
    expect(reportHtmlText($this->actingAs($other)->get("/weeklies/{$this->cycle->id}/informe/pdf?mios=1")->getContent()))
        ->toContain('No formas parte de ningún proyecto incluido en esta weekly.');
});

it('sin informe, el PDF lo dice', function () {
    $empty = WeeklyCycle::factory()->create();

    expect(reportHtmlText($this->actingAs($this->employee)->get("/weeklies/{$empty->id}/informe/pdf")->getContent()))
        ->toContain('Aún no se ha generado el informe de esta semana.');
});

it('nadie de fuera de la plantilla lo descarga; con el módulo apagado, tampoco se envía', function () {
    $this->actingAs(User::factory()->collaborator()->create())->get("/weeklies/{$this->cycle->id}/informe/pdf")->assertForbidden();

    $request = new ReportRequest(ReportKind::Weekly, ['cycle' => $this->cycle->id], []);
    expect(app(ReportAccess::class)->allows($request, $this->employee))->toBeTrue()
        ->and(ReportAccess::routeParams(ReportKind::Weekly))->toBe(['cycle'])
        ->and(app(ReportAccess::class)->allows(new ReportRequest(ReportKind::Weekly, ['cycle' => 999999], []), $this->employee))->toBeFalse();

    Setting::set('modules', ['weeklies' => false]);
    expect(app(ReportAccess::class)->allows($request, $this->employee))->toBeFalse();
    $this->actingAs($this->employee)->get("/weeklies/{$this->cycle->id}/informe/pdf")->assertNotFound();
});

it('la descarga queda en la auditoría de informes exportados', function () {
    $this->actingAs($this->employee)->get("/weeklies/{$this->cycle->id}/informe/pdf");

    $this->assertDatabaseHas('activity_log', ['log_name' => 'report-delivery', 'description' => 'report.generated']);
});

// --- «Uso de IA» ---------------------------------------------------------------------------------

it('«Uso de IA» suma llamadas, errores, tokens y coste por función y por modelo (solo admins)', function () {
    $this->travelTo(now());
    AiUsage::factory()->create(['feature' => 'weekly_report', 'model' => 'gemini-2.5-flash', 'prompt_tokens' => 1000, 'response_tokens' => 200, 'total_tokens' => 1200, 'estimated_cost_usd' => '0.000800']);
    AiUsage::factory()->create(['feature' => 'weekly_report', 'model' => 'gemini-2.5-flash', 'prompt_tokens' => 500, 'response_tokens' => 100, 'total_tokens' => 600, 'estimated_cost_usd' => '0.000400']);
    AiUsage::factory()->failed()->create(['feature' => 'satisfaction', 'model' => 'gemini-2.5-flash', 'total_tokens' => null, 'estimated_cost_usd' => null]);
    AiUsage::factory()->create(['provider' => 'google_tts', 'feature' => 'speech', 'model' => 'es-ES-Journey-F', 'character_count' => 3000, 'prompt_tokens' => null, 'response_tokens' => null, 'total_tokens' => null, 'estimated_cost_usd' => '0.048000']);
    $old = AiUsage::factory()->create(['feature' => 'assistant', 'estimated_cost_usd' => '9.000000']);
    $old->forceFill(['created_at' => now()->subDays(40)])->save();

    $this->actingAs(userWithRole('admin'))->get('/admin/uso-ia')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/ai-usage')
            ->where('range.days', 30)
            ->where('totals.calls', 4)
            ->where('totals.errors', 1)
            ->where('totals.total_tokens', 1800)
            ->where('totals.characters', 3000)
            ->where('totals.cost_usd', '0.049200')
            ->where('by_feature.0.feature', 'speech')
            ->where('by_feature.0.cost_usd', '0.048000')
            ->where('by_feature.1.feature', 'weekly_report')
            ->where('by_feature.1.calls', 2)
            ->where('by_feature.1.prompt_tokens', 1500)
            ->has('by_model', 2)
            ->where('by_model.0.model', 'es-ES-Journey-F')
            ->has('by_day', 1)
            ->where('by_day.0.cost_usd', '0.049200')
            ->has('recent', 4));

    $this->actingAs(userWithRole('admin'))->get('/admin/uso-ia?dias=90')->assertInertia(fn (Assert $page) => $page->where('range.days', 90)->where('totals.calls', 5));
    $this->actingAs(userWithRole('department_manager'))->get('/admin/uso-ia')->assertForbidden();
    $this->actingAs($this->employee)->get('/admin/uso-ia')->assertForbidden();
});
