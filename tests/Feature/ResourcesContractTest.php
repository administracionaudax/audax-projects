<?php

use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Contrato Resources ↔ resources/js/types/domain.ts: sin envoltorio {data: …}, tampoco en los
| Resources anidados; las colecciones paginadas mantienen {data, links, meta}.
*/

beforeEach(function () {
    Route::middleware('web')->get('/_test/resource', fn () => Inertia::render('home', [
        'project' => ProjectResource::make(Project::query()->with(['client', 'owner'])->firstOrFail()),
        'projects' => ProjectResource::collection(Project::query()->with(['client', 'owner'])->get()),
        'page' => ProjectResource::collection(Project::query()->with(['client', 'owner'])->paginate(1)),
    ]));
});

test('los Resources llegan a Inertia sin envoltorio, también los anidados', function () {
    $project = Project::factory()->create(['name' => 'Contrato']);

    $this->actingAs(User::factory()->employee()->create())
        ->get('/_test/resource')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('project.id', $project->id)
            ->where('project.name', 'Contrato')
            ->where('project.owner.id', $project->owner_user_id)
            ->missing('project.data')
            ->missing('project.owner.data')
            ->where('projects.0.id', $project->id)
            ->where('page.data.0.id', $project->id)
            ->has('page.meta')
            ->has('page.links'));
});
