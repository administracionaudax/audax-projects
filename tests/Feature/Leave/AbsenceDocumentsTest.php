<?php

use App\Domain\Absences\AbsenceDocuments;
use App\Models\Absence;
use App\Models\AbsenceDocument;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;

/*
| Justificantes (Fase 11, R3; W-071; L-24; D-368): en el disco privado; los ven la persona, RR. HH. y,
| si el tipo no es de salud, su responsable; nadie más. Cada descarga queda en la auditoría.
*/

beforeEach(function () {
    enablePeople();
    Notification::fake();
    Storage::fake('local');
    $this->travelTo(madridAt('2026-10-07 10:00'));
    $this->employee = userWithRole('employee', ['name' => 'Elena']);
    $this->colleague = userWithRole('employee');
    ['manager' => $this->manager] = peopleTeam($this->employee, $this->colleague);
    ['manager' => $this->otherManager] = peopleTeam();
    $this->hr = hrUser();
    $this->admin = userWithRole('admin');

    $this->moving = Absence::factory()->for($this->employee)->approved()->between('2026-10-05', '2026-10-05')->create(['leave_type_id' => leaveType('moving')->id, 'type' => 'leave']);
    $this->family = Absence::factory()->for($this->employee)->approved()->between('2026-10-06', '2026-10-08')->create(['leave_type_id' => leaveType('family_illness')->id, 'type' => 'leave']);
});

function uploadDocument(User $user, Absence $absence, string $name = 'empadronamiento.pdf'): TestResponse
{
    return test()->actingAs($user)->post("/ausencias/{$absence->id}/justificantes", [
        'file' => UploadedFile::fake()->create($name, 120, 'application/pdf'),
    ]);
}

it('la persona sube un justificante: disco privado, huella y auditoría sin el nombre del fichero', function () {
    uploadDocument($this->employee, $this->moving)->assertSessionHasNoErrors();

    $document = AbsenceDocument::query()->sole();
    expect($document->path)->toStartWith("people/justificantes/{$this->employee->id}/")
        ->and($document->original_name)->toBe('empadronamiento.pdf')
        ->and($document->sha256)->toHaveLength(64)
        ->and($document->uploaded_by)->toBe($this->employee->id);
    Storage::disk('local')->assertExists($document->path);

    $activity = Activity::query()->where('log_name', 'absence-documents')->where('event', 'document_uploaded')->sole();
    expect($activity->properties->toArray())->not->toHaveKey('name')
        ->and(json_encode($activity->properties))->not->toContain('empadronamiento');
});

it('quién ve un justificante de un permiso que no es de salud', function (string $who, int $status) {
    uploadDocument($this->employee, $this->moving);
    $document = AbsenceDocument::query()->sole();
    $viewer = match ($who) {
        'persona' => $this->employee,
        'responsable' => $this->manager,
        'rrhh' => $this->hr,
        'admin' => $this->admin,
        'compañera' => $this->colleague,
        'otro responsable' => $this->otherManager,
    };

    $this->actingAs($viewer)->get("/ausencias/justificantes/{$document->id}")->assertStatus($status);
})->with([
    'persona' => ['persona', 200],
    'su responsable' => ['responsable', 200],
    'RR. HH.' => ['rrhh', 200],
    'un admin (tiene manage-people)' => ['admin', 200],
    'una compañera' => ['compañera', 403],
    'otro responsable' => ['otro responsable', 403],
]);

it('de un tipo de salud, el responsable solo sabe que se ha entregado; lo ven la persona y RR. HH.', function () {
    uploadDocument($this->employee, $this->family, 'informe-hospital.pdf');
    $document = AbsenceDocument::query()->sole();

    $this->actingAs($this->manager)->get("/ausencias/justificantes/{$document->id}")->assertForbidden();
    $this->actingAs($this->employee)->get("/ausencias/justificantes/{$document->id}")->assertOk();
    $this->actingAs($this->hr)->get("/ausencias/justificantes/{$document->id}")->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Cache-Control', 'no-store, private');

    $this->actingAs($this->manager)->get('/ausencias/equipo')->assertInertia(fn ($page) => $page
        ->where('upcoming', fn ($rows) => collect($rows)->contains(fn ($row) => $row['id'] === $this->family->id
            && $row['leave']['documents'] === null
            && $row['leave']['documents_count'] === 1)));

    expect(Activity::query()->where('log_name', 'absence-documents')->where('event', 'document_downloaded')->count())->toBe(2);
});

it('solo la persona o RR. HH. suben justificantes; el responsable y los compañeros, no', function () {
    uploadDocument($this->manager, $this->moving)->assertForbidden();
    uploadDocument($this->colleague, $this->moving)->assertForbidden();
    uploadDocument($this->hr, $this->moving)->assertSessionHasNoErrors();
});

it('admite PDF e imágenes de 10 MB como mucho y 5 por ausencia', function () {
    $this->actingAs($this->employee)->post("/ausencias/{$this->moving->id}/justificantes", ['file' => UploadedFile::fake()->create('virus.exe', 10)])->assertSessionHasErrors('file');
    $this->actingAs($this->employee)->post("/ausencias/{$this->moving->id}/justificantes", ['file' => UploadedFile::fake()->create('grande.pdf', AbsenceDocuments::MAX_KILOBYTES + 1, 'application/pdf')])->assertSessionHasErrors('file');

    foreach (range(1, AbsenceDocuments::MAX_PER_ABSENCE) as $index) {
        uploadDocument($this->employee, $this->moving, "parte-{$index}.pdf")->assertSessionHasNoErrors();
    }
    uploadDocument($this->employee, $this->moving, 'otro.pdf')->assertSessionHasErrors(['file' => 'Una ausencia admite 5 justificantes como mucho. Borra alguno antes.']);
});

it('borra quien lo subió o RR. HH., y se va el fichero', function () {
    uploadDocument($this->employee, $this->moving);
    $document = AbsenceDocument::query()->sole();

    $this->actingAs($this->manager)->delete("/ausencias/justificantes/{$document->id}")->assertForbidden();
    $this->actingAs($this->employee)->delete("/ausencias/justificantes/{$document->id}")->assertSessionHasNoErrors();

    expect(AbsenceDocument::query()->count())->toBe(0);
    Storage::disk('local')->assertMissing($document->path);
});

it('con el módulo apagado no hay justificantes (404)', function () {
    uploadDocument($this->employee, $this->moving);
    $document = AbsenceDocument::query()->sole();
    Setting::set('modules', [...(array) Setting::get('modules', []), 'people' => false]);

    $this->actingAs($this->employee)->get("/ausencias/justificantes/{$document->id}")->assertNotFound();
    uploadDocument($this->employee, $this->moving)->assertNotFound();
});

it('el informe de justificantes pendientes lista las ausencias que lo piden y no lo tienen', function () {
    uploadDocument($this->employee, $this->moving);

    expect(AbsenceDocuments::missing()->pluck('id')->all())->toBe([$this->family->id]);
});
