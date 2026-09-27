<?php

use App\Domain\Templates\TemplateTransfer;
use App\Http\Requests\Templates\TemplateStructure;
use App\Models\ProjectTemplate;
use App\Models\TaskType;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;

/*
| /admin/plantillas (D-058): listado con cifras y filtros, editor (crear, editar, duplicar) con la
| estructura validada en el servidor y los errores junto a cada campo, activar y desactivar,
| papelera y recuperar, y exportar e importar en JSON.
*/

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->design = TaskType::factory()->create(['name' => 'Diseño UI']);
    $this->structure = [
        'tasks' => [
            ['ref' => 'dis', 'title' => 'Diseño', 'task_type_id' => $this->design->id, 'priority' => 'high', 'estimated_minutes' => 900, 'start_offset_days' => 0, 'duration_days' => 5],
            ['ref' => 'dis-home', 'parent_ref' => 'dis', 'title' => 'Home', 'estimated_minutes' => 300, 'start_offset_days' => 0, 'duration_days' => 2],
            ['ref' => 'dev', 'title' => 'Desarrollo', 'start_offset_days' => 7, 'duration_days' => 10],
            ['ref' => 'go', 'title' => 'Publicación', 'is_milestone' => true, 'estimated_minutes' => 60, 'start_offset_days' => 17, 'duration_days' => 4],
        ],
        'dependencies' => [['from_ref' => 'dis', 'to_ref' => 'dev'], ['from_ref' => 'dev', 'to_ref' => 'go']],
    ];
    $this->payload = fn (array $overrides = []): array => [
        'name' => 'Web corporativa',
        'description' => 'Diseño y desarrollo de una web',
        'is_active' => true,
        'structure' => $this->structure,
        ...$overrides,
    ];
    $this->actingAs($this->admin);
});

it('lista las plantillas con sus cifras y filtra por estado, texto y papelera', function () {
    ProjectTemplate::query()->create(['name' => 'Web corporativa', 'structure' => $this->structure]);
    ProjectTemplate::query()->create(['name' => 'Campaña SEO', 'description' => 'Posicionamiento', 'structure' => $this->structure, 'is_active' => false]);
    ProjectTemplate::query()->create(['name' => 'Borrada', 'structure' => $this->structure])->delete();

    $this->get('/admin/plantillas')->assertInertia(fn (Assert $page) => $page
        ->component('admin/templates/index')
        ->has('templates.data', 2)
        ->where('templates.data.0.name', 'Campaña SEO')
        ->where('templates.data.0.is_active', false)
        ->where('templates.data.1.stats', ['tasks' => 4, 'subtasks' => 1, 'milestones' => 1, 'dependencies' => 2, 'duration_days' => 21])
        ->where('filters', ['q' => '', 'estado' => 'todas', 'papelera' => false])
        ->where('trashedCount', 1));

    $this->get('/admin/plantillas?estado=activas')->assertInertia(fn (Assert $page) => $page
        ->has('templates.data', 1)
        ->where('templates.data.0.name', 'Web corporativa'));

    $this->get('/admin/plantillas?estado=inactivas')->assertInertia(fn (Assert $page) => $page
        ->has('templates.data', 1)
        ->where('templates.data.0.name', 'Campaña SEO'));

    $this->get('/admin/plantillas?q=posicion')->assertInertia(fn (Assert $page) => $page
        ->has('templates.data', 1)
        ->where('templates.data.0.name', 'Campaña SEO'));

    $this->get('/admin/plantillas?papelera=1')->assertInertia(fn (Assert $page) => $page
        ->has('templates.data', 1)
        ->where('templates.data.0.name', 'Borrada')
        ->whereNot('templates.data.0.deleted_at', null));

    $this->get('/admin/plantillas?estado=otra')->assertSessionHasErrors('estado');
});

it('crea una plantilla con la estructura normalizada y abre su editor', function () {
    $this->post('/admin/plantillas', ($this->payload)())
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $template = ProjectTemplate::query()->sole();
    $milestone = collect($template->structure['tasks'])->firstWhere('ref', 'go');

    expect($template->name)->toBe('Web corporativa')
        ->and($template->created_by)->toBe($this->admin->id)
        ->and($template->structure['tasks'])->toHaveCount(4)
        ->and($template->structure['dependencies'])->toHaveCount(2)
        // Un hito no lleva horas y dura un día.
        ->and($milestone['estimated_minutes'])->toBeNull()
        ->and($milestone['duration_days'])->toBe(1)
        ->and(collect($template->structure['tasks'])->firstWhere('ref', 'dis')['priority'])->toBe('high');

    $this->get("/admin/plantillas/{$template->id}/editar")->assertInertia(fn (Assert $page) => $page
        ->component('admin/templates/edit')
        ->where('template.id', $template->id)
        ->where('template.structure.tasks.1.parent_ref', 'dis')
        ->where('limits', ['max_tasks' => 500, 'max_days' => 3650])
        ->where('priorities', ['low', 'normal', 'high', 'urgent'])
        ->has('types'));
});

it('duplicar abre el editor con la copia sin guardarla', function () {
    $template = ProjectTemplate::query()->create(['name' => 'Web', 'description' => 'Base', 'structure' => $this->structure]);

    $this->get("/admin/plantillas/nueva?desde={$template->id}")->assertInertia(fn (Assert $page) => $page
        ->component('admin/templates/edit')
        ->where('template.id', null)
        ->where('template.name', 'Copia de Web')
        ->where('template.description', 'Base')
        ->has('template.structure.tasks', 4));

    $this->get('/admin/plantillas/nueva')->assertInertia(fn (Assert $page) => $page->where('template', null));

    expect(ProjectTemplate::query()->count())->toBe(1);
});

it('da los errores de la estructura junto a cada campo', function (array $tasks, array $dependencies, string $field) {
    $this->from('/admin/plantillas/nueva')
        ->post('/admin/plantillas', ($this->payload)(['structure' => ['tasks' => $tasks, 'dependencies' => $dependencies]]))
        ->assertRedirect('/admin/plantillas/nueva')
        ->assertSessionHasErrors($field);

    expect(ProjectTemplate::query()->count())->toBe(0);
})->with([
    'sin tareas' => [[], [], 'structure.tasks'],
    'sin título' => [[['ref' => 'a', 'title' => 'A'], ['ref' => 'b', 'title' => '']], [], 'structure.tasks.1.title'],
    'referencia repetida' => [[['ref' => 'a', 'title' => 'A'], ['ref' => 'a', 'title' => 'B']], [], 'structure.tasks.1.ref'],
    'subtarea de una subtarea' => [[['ref' => 'a', 'title' => 'A'], ['ref' => 'b', 'title' => 'B', 'parent_ref' => 'a'], ['ref' => 'c', 'title' => 'C', 'parent_ref' => 'b']], [], 'structure.tasks.2.parent_ref'],
    'subtarea de sí misma' => [[['ref' => 'a', 'title' => 'A', 'parent_ref' => 'a']], [], 'structure.tasks.0.parent_ref'],
    'padre que no existe' => [[['ref' => 'a', 'title' => 'A', 'parent_ref' => 'x']], [], 'structure.tasks.0.parent_ref'],
    'dependencia de sí misma' => [[['ref' => 'a', 'title' => 'A']], [['from_ref' => 'a', 'to_ref' => 'a']], 'structure.tasks.0.depends_on'],
    'dependencia que no existe' => [[['ref' => 'a', 'title' => 'A']], [['from_ref' => 'x', 'to_ref' => 'a']], 'structure.tasks.0.depends_on'],
    'ciclo indirecto' => [
        [['ref' => 'a', 'title' => 'A'], ['ref' => 'b', 'title' => 'B'], ['ref' => 'c', 'title' => 'C']],
        [['from_ref' => 'a', 'to_ref' => 'b'], ['from_ref' => 'b', 'to_ref' => 'c'], ['from_ref' => 'c', 'to_ref' => 'a']],
        'structure.tasks.0.depends_on',
    ],
    'duración de 0 días' => [[['ref' => 'a', 'title' => 'A', 'duration_days' => 0]], [], 'structure.tasks.0.duration_days'],
    'inicio negativo' => [[['ref' => 'a', 'title' => 'A', 'start_offset_days' => -1]], [], 'structure.tasks.0.start_offset_days'],
    'estimación de más de 999 h' => [[['ref' => 'a', 'title' => 'A', 'estimated_minutes' => 999 * 60 + 1]], [], 'structure.tasks.0.estimated_minutes'],
    'prioridad desconocida' => [[['ref' => 'a', 'title' => 'A', 'priority' => 'máxima']], [], 'structure.tasks.0.priority'],
]);

it('el ciclo se marca en todas las tareas que lo forman y un tipo desactivado no vale', function () {
    $inactive = TaskType::factory()->create(['is_active' => false]);

    $this->post('/admin/plantillas', ($this->payload)(['structure' => [
        'tasks' => [['ref' => 'a', 'title' => 'A', 'task_type_id' => $inactive->id], ['ref' => 'b', 'title' => 'B'], ['ref' => 'c', 'title' => 'C']],
        'dependencies' => [['from_ref' => 'a', 'to_ref' => 'b'], ['from_ref' => 'b', 'to_ref' => 'a']],
    ]]))->assertSessionHasErrors(['structure.tasks.0.task_type_id']);

    $this->post('/admin/plantillas', ($this->payload)(['structure' => [
        'tasks' => [['ref' => 'a', 'title' => 'A'], ['ref' => 'b', 'title' => 'B'], ['ref' => 'c', 'title' => 'C']],
        'dependencies' => [['from_ref' => 'a', 'to_ref' => 'b'], ['from_ref' => 'b', 'to_ref' => 'a']],
    ]]))->assertSessionHasErrors(['structure.tasks.0.depends_on', 'structure.tasks.1.depends_on'])
        ->assertSessionDoesntHaveErrors('structure.tasks.2.depends_on');
});

it('como mucho 500 tareas', function () {
    $tasks = array_map(fn (int $i): array => ['ref' => "t{$i}", 'title' => "Tarea {$i}"], range(1, 501));

    $this->post('/admin/plantillas', ($this->payload)(['structure' => ['tasks' => $tasks, 'dependencies' => []]]))
        ->assertSessionHasErrors('structure.tasks');
});

it('edita, desactiva, envía a la papelera y recupera', function () {
    $template = ProjectTemplate::query()->create(['name' => 'Web', 'structure' => $this->structure]);

    $this->put("/admin/plantillas/{$template->id}", ($this->payload)(['name' => 'Web v2', 'structure' => [
        'tasks' => [['ref' => 'a', 'title' => 'Solo una', 'start_offset_days' => 2, 'duration_days' => 3]],
        'dependencies' => [],
    ]]))->assertSessionHasNoErrors()->assertRedirect("/admin/plantillas/{$template->id}/editar");

    expect($template->fresh()->name)->toBe('Web v2')
        ->and($template->fresh()->structure['tasks'])->toHaveCount(1);

    $this->from('/admin/plantillas')->put("/admin/plantillas/{$template->id}/estado", ['is_active' => false])->assertRedirect('/admin/plantillas');
    expect($template->fresh()->is_active)->toBeFalse();

    $this->put("/admin/plantillas/{$template->id}/estado", ['is_active' => true]);
    expect($template->fresh()->is_active)->toBeTrue();

    $this->delete("/admin/plantillas/{$template->id}")->assertRedirect();
    expect($template->fresh()->trashed())->toBeTrue();

    // En la papelera no se edita ni se exporta; se recupera.
    $this->get("/admin/plantillas/{$template->id}/editar")->assertNotFound();
    $this->put("/admin/plantillas/{$template->id}/estado", ['is_active' => false])->assertNotFound();

    $this->post("/admin/plantillas/{$template->id}/restaurar")->assertRedirect();
    expect($template->fresh()->trashed())->toBeFalse();

    // Recuperar una que no está en la papelera no encuentra nada.
    $this->post("/admin/plantillas/{$template->id}/restaurar")->assertNotFound();
});

it('exporta en JSON con el nombre de cada tipo y se puede volver a importar igual', function () {
    $template = ProjectTemplate::query()->create(['name' => 'Web corporativa', 'description' => 'Base', 'structure' => $this->structure]);

    $response = $this->get("/admin/plantillas/{$template->id}/exportar")->assertOk();

    expect($response->headers->get('Content-Disposition'))->toContain('attachment; filename="plantilla-web-corporativa.json"')
        ->and($response->json('format'))->toBe(TemplateTransfer::FORMAT)
        ->and($response->json('version'))->toBe(1)
        ->and($response->json('name'))->toBe('Web corporativa')
        ->and($response->json('structure.tasks.0.task_type'))->toBe('Diseño UI')
        ->and($response->json('structure.dependencies'))->toHaveCount(2);

    // En otra instalación el tipo tiene otro id: se encuentra por su nombre.
    $exported = $response->json();
    $exported['structure']['tasks'][0]['task_type_id'] = 999;
    $file = UploadedFile::fake()->createWithContent('plantilla-web-corporativa.json', (string) json_encode($exported));

    $this->post('/admin/plantillas/importar', ['file' => $file])->assertSessionHasNoErrors()->assertRedirect();

    $imported = ProjectTemplate::query()->latest('id')->first();
    expect($imported->name)->toBe('Web corporativa (2)')
        ->and($imported->description)->toBe('Base')
        ->and($imported->is_active)->toBeTrue()
        // Igual que la original (un hito siempre sin horas y de un día).
        ->and($imported->structure)->toBe(TemplateStructure::clean($template->structure))
        ->and($imported->structure['tasks'][0]['task_type_id'])->toBe($this->design->id);
});

it('importa una estructura suelta con el nombre del fichero y sin tipos desconocidos', function () {
    $file = UploadedFile::fake()->createWithContent('lanzamiento.json', (string) json_encode([
        'tasks' => [['ref' => 'a', 'title' => 'Preparar', 'task_type' => 'No existe', 'task_type_id' => 12345]],
    ]));

    $this->post('/admin/plantillas/importar', ['file' => $file])->assertSessionHasNoErrors();

    $imported = ProjectTemplate::query()->sole();
    expect($imported->name)->toBe('lanzamiento')
        ->and($imported->structure['tasks'][0]['task_type_id'])->toBeNull()
        ->and($imported->structure['dependencies'])->toBe([]);
});

it('rechaza ficheros que no son una plantilla válida con un error comprensible', function (string $contents, string $message) {
    $file = UploadedFile::fake()->createWithContent('mala.json', $contents);

    $this->from('/admin/plantillas')
        ->post('/admin/plantillas/importar', ['file' => $file])
        ->assertRedirect('/admin/plantillas')
        ->assertSessionHasErrors(['file' => $message]);

    expect(ProjectTemplate::query()->count())->toBe(0);
})->with([
    'no es JSON' => ['{esto no es json', 'El fichero no es un JSON válido.'],
    'sin tareas' => ['{"name": "Vacía", "structure": {"tasks": []}}', 'El fichero no tiene una plantilla válida: La plantilla necesita entre 1 y 500 tareas.'],
    'una tarea sin título' => [
        '{"tasks": [{"ref": "a", "title": "A"}, {"ref": "b", "title": ""}]}',
        'El fichero no tiene una plantilla válida: tarea 2: Escribe el título de la tarea.',
    ],
    'con un ciclo' => [
        '{"tasks": [{"ref": "a", "title": "A"}, {"ref": "b", "title": "B"}], "dependencies": [{"from_ref": "a", "to_ref": "b"}, {"from_ref": "b", "to_ref": "a"}]}',
        'El fichero no tiene una plantilla válida: tarea 1: Estas dependencias forman un ciclo: una tarea acabaría dependiendo de sí misma.',
    ],
    'referencias que solo se distinguen por espacios' => [
        '{"tasks": [{"ref": "a ", "title": "X"}, {"ref": "a", "title": "Y"}]}',
        'El fichero no tiene una plantilla válida: tarea 1: Cada tarea necesita una referencia única.',
    ],
    'un hito que no es sí o no' => [
        '{"tasks": [{"ref": "a", "title": "A", "is_milestone": "yes"}]}',
        'El fichero no tiene una plantilla válida: tarea 1: El campo hito debe ser verdadero o falso.',
    ],
    'un inicio que no es un número' => [
        '{"tasks": [{"ref": "a", "title": "A", "start_offset_days": "lunes"}]}',
        'El fichero no tiene una plantilla válida: tarea 1: El campo día de inicio debe ser un número entero.',
    ],
    'una referencia demasiado larga' => [
        '{"tasks": [{"ref": "'.str_repeat('a', 41).'", "title": "A"}]}',
        'El fichero no tiene una plantilla válida: tarea 1: El campo referencia no puede tener más de 40 caracteres.',
    ],
    'una dependencia sin sucesora' => [
        '{"tasks": [{"ref": "a", "title": "A"}], "dependencies": [{"from_ref": "a"}]}',
        'El fichero no tiene una plantilla válida: dependencia 1: El campo tarea que depende es obligatorio.',
    ],
]);

it('al importar, las referencias con espacios alrededor son las mismas que sin ellos', function () {
    $file = UploadedFile::fake()->createWithContent('espacios.json', (string) json_encode([
        'tasks' => [['ref' => ' a', 'title' => 'A'], ['ref' => 'b', 'parent_ref' => 'a ', 'title' => 'B'], ['ref' => 'c ', 'title' => 'C']],
        'dependencies' => [['from_ref' => 'a ', 'to_ref' => ' c']],
    ]));

    $this->post('/admin/plantillas/importar', ['file' => $file])->assertSessionHasNoErrors();

    $structure = ProjectTemplate::query()->sole()->structure;
    expect(array_column($structure['tasks'], 'ref'))->toBe(['a', 'b', 'c'])
        ->and($structure['tasks'][1]['parent_ref'])->toBe('a')
        ->and($structure['dependencies'])->toBe([['from_ref' => 'a', 'to_ref' => 'c']]);
});

it('solo admite ficheros JSON', function () {
    $this->post('/admin/plantillas/importar', ['file' => UploadedFile::fake()->create('plantilla.pdf', 10, 'application/pdf')])
        ->assertSessionHasErrors('file');
    $this->post('/admin/plantillas/importar', [])->assertSessionHasErrors('file');
});
