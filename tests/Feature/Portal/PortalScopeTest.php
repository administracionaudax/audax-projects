<?php

use App\Domain\Portal\PortalBankAlerts;
use App\Domain\Portal\PortalBankFigures;
use App\Domain\Portal\PortalScope;
use App\Enums\PortalEntryVisibility;
use App\Enums\PortalPersonDisplay;
use App\Enums\TimeEntryStatus;
use App\Models\Client;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TimeEntry;
use App\Models\User;
use App\Notifications\Portal\ClientHourBankThreshold;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
| Contrato del portal (D-063 a D-066): qué ve cada cliente, sus cifras de bolsa y sus avisos.
*/

beforeEach(function () {
    TaskStatus::ensureDefaults();
    $this->client = Client::factory()->create();
    $this->user = User::factory()->portalOf($this->client)->create(['name' => 'María José López']);
    $this->project = Project::factory()->hourBank()->create(['client_id' => $this->client->id]);
    $this->bank = HourBank::factory()->create(['project_id' => $this->project->id, 'total_minutes' => 600]);
    $this->task = Task::factory()->create(['project_id' => $this->project->id, 'hour_bank_id' => $this->bank->id]);
    $this->entry = fn (int $minutes, TimeEntryStatus $status, string $date = '2026-09-15', int $overage = 0) => TimeEntry::factory()
        ->forTask($this->task)
        ->create(['minutes' => $minutes, 'status' => $status, 'date' => $date, 'overage_minutes' => $overage]);
});

it('solo los usuarios del portal activos de un cliente activo tienen alcance', function () {
    expect(PortalScope::for($this->user)->client->id)->toBe($this->client->id);

    expect(fn () => PortalScope::for(User::factory()->employee()->create()))->toThrow(HttpException::class)
        ->and(fn () => PortalScope::for(User::factory()->client()->create()))->toThrow(HttpException::class)
        ->and(fn () => PortalScope::for(User::factory()->portalOf($this->client)->create(['is_active' => false])))->toThrow(HttpException::class);

    $this->client->update(['is_active' => false]);
    expect(fn () => PortalScope::for($this->user->fresh()))->toThrow(HttpException::class);
});

it('el cliente ve solo las horas de sus proyectos en los estados que permite', function () {
    ($this->entry)(60, TimeEntryStatus::Draft);
    ($this->entry)(60, TimeEntryStatus::Submitted);
    ($this->entry)(60, TimeEntryStatus::Approved);
    ($this->entry)(60, TimeEntryStatus::Locked);
    TimeEntry::factory()->create(['status' => TimeEntryStatus::Approved]);

    $scope = PortalScope::for($this->user);
    expect($scope->entries()->count())->toBe(2)
        ->and($scope->hourBanks()->pluck('id')->all())->toBe([$this->bank->id]);

    $this->client->update(['portal_entry_visibility' => PortalEntryVisibility::Submitted]);
    expect(PortalScope::for($this->user->fresh())->entries()->count())->toBe(3);
});

it('nombra a las personas con el nombre, las iniciales o «Equipo»', function () {
    $person = User::factory()->employee()->create(['name' => 'María José López']);

    expect(PortalScope::label(PortalPersonDisplay::Name, $person))->toBe('María José López')
        ->and(PortalScope::label(PortalPersonDisplay::Initials, $person))->toBe('M.J.L.')
        ->and(PortalScope::label(PortalPersonDisplay::Team, $person))->toBe('Equipo')
        ->and(PortalScope::label(PortalPersonDisplay::Name, null))->toBe('Equipo');
});

it('las cifras de la bolsa salen solo de lo que ve el cliente, dentro y exceso por separado', function () {
    ($this->entry)(300, TimeEntryStatus::Approved, '2026-09-15');
    ($this->entry)(400, TimeEntryStatus::Locked, '2026-10-02', 100);
    ($this->entry)(120, TimeEntryStatus::Draft, '2026-10-03');

    $scope = PortalScope::for($this->user);

    expect(PortalBankFigures::one($scope, $this->bank))->toBe([
        'total_minutes' => 600,
        'within_minutes' => 600,
        'overage_minutes' => 100,
        'remaining_minutes' => 0,
        'percent' => 1.1667,
    ])->and(PortalBankFigures::byMonth($scope, $this->bank))->toBe([
        ['month' => '2026-09-01', 'within_minutes' => 300, 'overage_minutes' => 0],
        ['month' => '2026-10-01', 'within_minutes' => 300, 'overage_minutes' => 100],
    ]);
});

it('el proyecto, sus horas por tarea y el Gantt solo si el admin los abre al portal', function () {
    $scope = PortalScope::for($this->user);
    $other = Project::factory()->create(['portal_project_visible' => true, 'portal_gantt_visible' => true]);

    expect($scope->canViewProject($this->project))->toBeFalse()
        ->and($scope->canViewGantt($this->project))->toBeFalse()
        ->and($scope->canViewProject($other))->toBeFalse()
        ->and($scope->ownsBank($this->bank))->toBeTrue();

    $this->project->update(['portal_project_visible' => true, 'portal_gantt_visible' => true]);
    expect($scope->canViewProject($this->project))->toBeTrue()
        ->and($scope->canViewGantt($this->project))->toBeTrue()
        ->and($scope->canViewTaskHours($this->project))->toBeFalse();
});

it('avisa al cliente por email al 90 % y al 100 % de lo que ve, una vez por umbral', function () {
    Notification::fake();
    $inactive = User::factory()->portalOf($this->client)->create(['is_active' => false]);

    ($this->entry)(560, TimeEntryStatus::Approved);
    Notification::assertNotSentTo($this->user, ClientHourBankThreshold::class);
    TimeEntry::query()->update(['minutes' => 530]);

    $this->client->update(['portal_notify_thresholds' => true]);
    ($this->entry)(10, TimeEntryStatus::Draft);
    Notification::assertNotSentTo($this->user, ClientHourBankThreshold::class);

    ($this->entry)(10, TimeEntryStatus::Approved);
    Notification::assertSentTo($this->user, ClientHourBankThreshold::class, fn ($n) => $n->threshold === 90);
    Notification::assertNotSentTo($inactive, ClientHourBankThreshold::class);

    ($this->entry)(10, TimeEntryStatus::Approved);
    Notification::assertSentToTimes($this->user, ClientHourBankThreshold::class, 1);
    ($this->entry)(50, TimeEntryStatus::Approved);
    Notification::assertSentTo($this->user, ClientHourBankThreshold::class, fn ($n) => $n->threshold === 100);
    Notification::assertSentToTimes($this->user, ClientHourBankThreshold::class, 2);

    app(PortalBankAlerts::class)->check($this->bank->fresh());
    Notification::assertSentToTimes($this->user, ClientHourBankThreshold::class, 2);
});

it('el email al cliente no lleva importes y enlaza a su bolsa del portal', function () {
    $mail = (new ClientHourBankThreshold($this->bank->load('project'), 100, [
        'total_minutes' => 600, 'within_minutes' => 600, 'overage_minutes' => 30, 'remaining_minutes' => 0, 'percent' => 1.05,
    ]))->toMail($this->user);

    $text = implode("\n", [...$mail->introLines, $mail->subject, $mail->actionUrl]);
    expect($text)->toContain('10:00')
        ->toContain("/portal/bolsas/{$this->bank->id}")
        ->not->toContain('€');
});
