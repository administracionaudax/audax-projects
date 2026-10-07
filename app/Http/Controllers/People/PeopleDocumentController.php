<?php

namespace App\Http\Controllers\People;

use App\Domain\People\PeopleAccess;
use App\Domain\People\PeopleDocuments;
use App\Http\Controllers\Controller;
use App\Http\Controllers\People\Concerns\HandlesRegisterFiles;
use App\Models\PeopleDocument;
use App\Models\PeopleDocumentRead;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Documentos» (`/personas/documentos`, PLAN-FASE-11 §6.1.7; D-354; W-088 y W-108): el documento de
 * implantación del registro de jornada y la política de desconexión digital, con «He leído». RR. HH.
 * publica versiones nuevas y ve quién ha leído la vigente.
 */
class PeopleDocumentController extends Controller
{
    use HandlesRegisterFiles;

    public function __construct(private readonly PeopleDocuments $documents) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $managesAll = PeopleAccess::managesRegister($user);
        $staff = $managesAll ? PeopleAccess::teamQuery($user)->orderBy('name')->get(['users.id', 'users.name']) : collect();

        $documents = array_map(function (PeopleDocument $document) use ($user, $managesAll, $staff): array {
            $reads = PeopleDocumentRead::query()->where('people_document_id', $document->id)->pluck('read_at', 'user_id');
            $mine = $reads->get($user->id);

            return [
                'id' => $document->id,
                'key' => $document->key,
                'version' => $document->version,
                'title' => $document->title,
                'body' => $document->body,
                'is_draft' => $document->is_draft,
                'published_at' => $document->created_at?->toIso8601ZuluString(),
                'published_by' => $document->publisher?->name,
                'read_at' => $mine === null ? null : CarbonImmutable::parse((string) $mine)->toIso8601ZuluString(),
                'readers' => $managesAll ? $staff->map(fn (User $person): array => [
                    'id' => $person->id,
                    'name' => $person->name,
                    'read_at' => $reads->has($person->id) ? CarbonImmutable::parse((string) $reads->get($person->id))->toIso8601ZuluString() : null,
                ])->values()->all() : null,
            ];
        }, $this->documents->all());

        return Inertia::render('people/documents', [
            'documents' => $documents,
            'can_publish' => $managesAll,
            'max_length' => PeopleDocuments::MAX_LENGTH,
        ]);
    }

    public function read(Request $request, PeopleDocument $document): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->documents->markRead($user, $document);
        $this->toast(__('people.flash.document_read', ['version' => $document->version]));

        return back();
    }

    public function update(Request $request, string $key): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        abort_unless(in_array($key, PeopleDocuments::KEYS, true), 404);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:'.PeopleDocuments::MAX_LENGTH],
        ]);

        $document = $this->documents->publish($actor, $key, $data['title'], $data['body']);
        $this->toast(__('people.flash.document_published', ['version' => $document->version]));

        return back();
    }
}
