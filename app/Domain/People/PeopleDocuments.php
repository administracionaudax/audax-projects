<?php

namespace App\Domain\People;

use App\Models\PeopleDocument;
use App\Models\PeopleDocumentRead;
use App\Models\User;
use App\Notifications\People\PeopleDocumentPublished;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Documentos de RR. HH. con lectura registrada (PLAN-FASE-11 §6.1.7; D-354; W-088 y W-108), con el
 * patrón del texto de privacidad (D-075 y D-125):
 *
 * - **Documento de implantación del registro de jornada** (art. 34.9 ET y CT 101/2019: el sistema se
 *   organiza y se documenta; L-04) y **política de desconexión digital** (art. 88.3 LOPDGDD y art.
 *   18 de la Ley 10/2021; L-10). Solo esos dos; el gestor documental general no entra en R2.
 * - Mientras nadie publique un texto propio, cuenta el **borrador** de lang/es/people_documents.php,
 *   marcado «pendiente de asesor» (la primera vez se guarda como versión 1).
 * - Publicar un texto nuevo crea una **versión** nueva (las anteriores se conservan) y toda la
 *   plantilla tiene que volver a leerlo; cada «He leído» queda con la persona, la versión y cuándo.
 * - Los publica RR. HH. (`manage-people`); queda en la auditoría.
 */
final class PeopleDocuments
{
    public const array KEYS = ['register_protocol', 'disconnection_policy'];

    public const string LOG = 'people_documents';

    public const int MAX_LENGTH = 30000;

    /** Versión vigente (si aún no hay ninguna, el borrador como versión 1). */
    public function current(string $key): PeopleDocument
    {
        self::assertKey($key);

        $current = PeopleDocument::query()->where('key', $key)->orderByDesc('version')->first();

        if ($current !== null) {
            return $current;
        }

        return DB::transaction(function () use ($key): PeopleDocument {
            $existing = PeopleDocument::query()->where('key', $key)->lockForUpdate()->orderByDesc('version')->first();

            if ($existing !== null) {
                return $existing;
            }

            $document = new PeopleDocument;
            $document->forceFill([
                'key' => $key,
                'version' => 1,
                'title' => (string) __("people_documents.{$key}.title"),
                'body' => (string) __("people_documents.{$key}.body"),
                'is_draft' => true,
                'published_by' => null,
                'created_at' => CarbonImmutable::now(),
            ])->save();

            return $document;
        });
    }

    /**
     * Las dos versiones vigentes, en orden.
     *
     * @return list<PeopleDocument>
     */
    public function all(): array
    {
        return array_map(fn (string $key): PeopleDocument => $this->current($key), self::KEYS);
    }

    /**
     * Publica un texto nuevo (si cambia): versión nueva, aviso a la plantilla y auditoría.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function publish(User $actor, string $key, string $title, string $body): PeopleDocument
    {
        if (! PeopleAccess::managesAll($actor)) {
            throw new AuthorizationException;
        }

        $title = trim($title);
        $body = trim($body);

        if ($title === '' || $body === '') {
            throw ValidationException::withMessages(['body' => __('people.errors.document_required')]);
        }

        $current = $this->current($key);

        if ($current->title === $title && trim($current->body) === $body) {
            return $current;
        }

        $document = new PeopleDocument;
        $document->forceFill([
            'key' => $key,
            'version' => $current->version + 1,
            'title' => $title,
            'body' => $body,
            'is_draft' => false,
            'published_by' => $actor->id,
            'created_at' => CarbonImmutable::now(),
        ])->save();

        activity(self::LOG)
            ->causedBy($actor)
            ->performedOn($document)
            ->event('published')
            ->withProperties(['key' => $key, 'old' => ['version' => $current->version], 'attributes' => ['version' => $document->version]])
            ->log('people_document.published');

        PeopleNotifier::send(PeopleAccess::teamQuery($actor)->whereKeyNot($actor->id)->get(), new PeopleDocumentPublished($document));

        return $document;
    }

    /**
     * «He leído» de la versión vigente (una sola vez por persona y versión).
     *
     * @throws ValidationException
     */
    public function markRead(User $user, PeopleDocument $document): PeopleDocumentRead
    {
        if ($this->current($document->key)->id !== $document->id) {
            throw ValidationException::withMessages(['document' => __('people.errors.document_outdated')]);
        }

        $read = PeopleDocumentRead::query()->where('people_document_id', $document->id)->where('user_id', $user->id)->first()
            ?? new PeopleDocumentRead;

        if (! $read->exists) {
            $read->forceFill(['people_document_id' => $document->id, 'user_id' => $user->id, 'read_at' => CarbonImmutable::now()])->save();

            activity(self::LOG)
                ->causedBy($user)
                ->performedOn($document)
                ->event('read')
                ->withProperties(['key' => $document->key, 'version' => $document->version])
                ->log('people_document.read');
        }

        return $read;
    }

    /**
     * Documentos vigentes que $user aún no ha leído.
     *
     * @return list<string>
     */
    public function unreadFor(User $user): array
    {
        $unread = [];

        foreach ($this->all() as $document) {
            if (! PeopleDocumentRead::query()->where('people_document_id', $document->id)->where('user_id', $user->id)->exists()) {
                $unread[] = $document->key;
            }
        }

        return $unread;
    }

    private static function assertKey(string $key): void
    {
        if (! in_array($key, self::KEYS, true)) {
            throw new \InvalidArgumentException("Documento de RR. HH. desconocido: {$key}");
        }
    }
}
