<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Chat\Transcription\AudioTranscriptions;
use App\Enums\ConversationType;
use App\Enums\TranscriptionStatus;
use App\Http\Controllers\Chat\Media\StoreMediaMessageRequest;
use App\Http\Controllers\Controller;
use App\Models\AudioTranscription;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Transcripciones de los audios del chat (SPEC §12, D-070), solo admin: /admin/transcripciones.
 * - Lista con filtro por estado (?estado=pendientes|en-curso|fallidas|hechas), intentos, último
 *   error, duración y tiempo de proceso; señala los audios que pasan de la duración máxima según
 *   el transcriptor (el navegador declaró otra: StoreMediaMessageRequest).
 * - «Relanzar» una (AudioTranscriptions::retry) o todas las fallidas de una vez.
 * Privacidad (D-071): de las conversaciones directas no se muestran ni las personas ni el enlace
 * (el admin no las ve); nunca se muestra el texto ni el audio.
 */
class TranscriptionController extends Controller
{
    public const int PER_PAGE = 50;

    /**
     * Una transcripción «en curso» desde hace más de esto se da por interrumpida y se puede
     * relanzar (el mismo margen que AudioTranscriptions::requeue).
     */
    public const int STALE_MINUTES = 50;

    /**
     * ?estado= (en español en la URL) → estado.
     */
    public const array STATUSES = [
        'pendientes' => TranscriptionStatus::Pending,
        'en-curso' => TranscriptionStatus::Processing,
        'fallidas' => TranscriptionStatus::Failed,
        'hechas' => TranscriptionStatus::Done,
    ];

    public function __construct(private readonly AudioTranscriptions $transcriptions) {}

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'estado' => ['nullable', 'string', Rule::in(array_keys(self::STATUSES))],
        ]);
        $filter = isset($validated['estado']) ? (string) $validated['estado'] : null;
        $status = $filter === null ? null : self::STATUSES[$filter];

        /** @var array<string, int> $counts */
        $counts = AudioTranscription::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($total): int => (int) $total)
            ->all();

        $page = AudioTranscription::query()
            ->with(['message' => fn ($message) => $message->withTrashed()
                ->select(['id', 'conversation_id', 'user_id', 'hidden_at', 'deleted_at', 'created_at'])
                ->with([
                    'author:id,name',
                    'conversation:id,type,name,project_id,client_id',
                    'conversation.project' => fn ($project) => $project->withTrashed()->select(['id', 'name', 'code']),
                    'conversation.client' => fn ($client) => $client->select(['id', 'name']),
                ])])
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $limitMs = StoreMediaMessageRequest::maxDurationMs();
        $now = CarbonImmutable::now();

        return Inertia::render('admin/transcriptions', [
            'filters' => ['status' => $filter],
            'counts' => [
                'pending' => $counts[TranscriptionStatus::Pending->value] ?? 0,
                'processing' => $counts[TranscriptionStatus::Processing->value] ?? 0,
                'failed' => $counts[TranscriptionStatus::Failed->value] ?? 0,
                'done' => $counts[TranscriptionStatus::Done->value] ?? 0,
            ],
            'maxAudioSeconds' => StoreMediaMessageRequest::maxSeconds(),
            'transcriptions' => array_values(array_map(
                fn (AudioTranscription $transcription): array => $this->row($transcription, $limitMs, $now),
                $page->items(),
            )),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'prev_url' => self::relative($page->previousPageUrl()),
                'next_url' => self::relative($page->nextPageUrl()),
            ],
        ]);
    }

    public function retry(AudioTranscription $transcription): RedirectResponse
    {
        if ($transcription->status === TranscriptionStatus::Done) {
            Inertia::flash('toast', ['type' => 'info', 'message' => __('chat_media.admin.already_done')]);

            return back();
        }

        if (! self::canRetry($transcription, CarbonImmutable::now())) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('chat_media.admin.busy')]);

            return back();
        }

        $this->relaunch($transcription);
        $transcription->refresh();

        Inertia::flash('toast', $transcription->status === TranscriptionStatus::Failed
            ? ['type' => 'warning', 'message' => __('chat_media.admin.failed_again', ['error' => (string) $transcription->last_error])]
            : ['type' => 'success', 'message' => __('chat_media.admin.retried')]);

        return back();
    }

    /**
     * Relanza todas las fallidas (en bloque, de 100 en 100).
     */
    public function retryFailed(): RedirectResponse
    {
        $count = 0;

        AudioTranscription::query()
            ->where('status', TranscriptionStatus::Failed)
            ->lazyById(100)
            ->each(function (AudioTranscription $transcription) use (&$count): void {
                $this->relaunch($transcription);
                $count++;
            });

        Inertia::flash('toast', ['type' => $count > 0 ? 'success' : 'info', 'message' => trans_choice('chat_media.admin.retried_many', $count, ['count' => $count])]);

        return back();
    }

    /**
     * Con la cola síncrona (local) el motor se ejecuta aquí mismo: si falla, la transcripción ya
     * queda en «fallida» con su error y la revisión periódica la volverá a intentar; la página no
     * debe romperse por ello.
     */
    private function relaunch(AudioTranscription $transcription): void
    {
        try {
            $this->transcriptions->retry($transcription);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private static function canRetry(AudioTranscription $transcription, CarbonImmutable $now): bool
    {
        return match ($transcription->status) {
            TranscriptionStatus::Done => false,
            TranscriptionStatus::Processing => $transcription->started_at === null
                || $transcription->started_at->lt($now->subMinutes(self::STALE_MINUTES)),
            default => true,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function row(AudioTranscription $transcription, int $limitMs, CarbonImmutable $now): array
    {
        $message = $transcription->message;
        $conversation = $message->conversation;
        $direct = $conversation->type === ConversationType::Direct;
        $label = match ($conversation->type) {
            ConversationType::Project => $conversation->project === null ? null : $conversation->project->code.' · '.$conversation->project->name,
            ConversationType::Group, ConversationType::Team => $conversation->name,
            ConversationType::Client => $conversation->client?->name,
            ConversationType::Direct => null,
        };
        $visible = ! $direct && ! $message->trashed();

        return [
            'id' => $transcription->id,
            'message_id' => $message->id,
            'status' => $transcription->status->value,
            'attempts' => $transcription->attempts,
            'last_error' => $transcription->last_error,
            'audio_duration_ms' => $transcription->audio_duration_ms,
            'processing_ms' => $transcription->processing_ms,
            'engine' => $transcription->engine,
            'model' => $transcription->model,
            'created_at' => $transcription->created_at?->toIso8601ZuluString(),
            'queued_at' => $transcription->queued_at?->toIso8601ZuluString(),
            'transcribed_at' => $transcription->transcribed_at?->toIso8601ZuluString(),
            'admin_notified' => $transcription->admin_notified_at !== null,
            'conversation' => [
                'type' => $conversation->type->value,
                'label' => $label,
            ],
            'author' => $direct ? null : $message->author?->name,
            'message_deleted' => $message->trashed(),
            'message_hidden' => $message->hidden_at !== null,
            'over_limit' => $transcription->audio_duration_ms !== null && $transcription->audio_duration_ms > $limitMs,
            'can_retry' => self::canRetry($transcription, $now),
            'url' => $visible ? route('chat.show', ['conversation' => $conversation->id, 'mensaje' => $message->id], false) : null,
        ];
    }

    private static function relative(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $parts = parse_url($url);

        return ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
    }
}
