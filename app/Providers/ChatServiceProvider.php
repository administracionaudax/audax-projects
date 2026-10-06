<?php

namespace App\Providers;

use App\Domain\Chat\ChannelMembership;
use App\Domain\Chat\ConversationDirectory;
use App\Domain\Chat\Links\DnsHostResolver;
use App\Domain\Chat\Links\HostResolver;
use App\Domain\Chat\Links\LinkPreviews;
use App\Domain\Chat\Notices\ProjectChatNotices;
use App\Domain\Chat\Transcription\FakeTranscriber;
use App\Domain\Chat\Transcription\TranscriptionService;
use App\Domain\Chat\Transcription\WhisperServerTranscriber;
use App\Domain\HourBanks\Events\HourBankThresholdReached;
use App\Events\Chat\MessagePosted;
use App\Models\Conversation;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Notifications\Channels\AppDatabaseChannel;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Chat (Fase 6):
 * - el motor de transcripción según services.transcription.driver (whisper en el servidor; fake
 *   en local y en los tests),
 * - el chat de un proyecto sigue a sus miembros: entrar o salir del proyecto es entrar o salir de
 *   su conversación (si ya existe; si no, se crea con todos al primer uso),
 * - previsualización de enlaces y mensajes de sistema del chat del proyecto (área C1).
 */
class ChatServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Previsualización de enlaces (C1): DNS del sistema (en los tests, resoluciones simuladas).
        $this->app->bind(HostResolver::class, DnsHostResolver::class);

        // La campana guarda el mensaje del que habla cada aviso del chat (D-115).
        $this->app->bind(DatabaseChannel::class, AppDatabaseChannel::class);

        $this->app->singleton(TranscriptionService::class, function (): TranscriptionService {
            return match (config('services.transcription.driver')) {
                'fake' => new FakeTranscriber,
                default => new WhisperServerTranscriber(
                    (string) config('services.transcription.whisper.url'),
                    (string) config('services.transcription.whisper.model'),
                    (int) config('services.transcription.whisper.timeout'),
                ),
            };
        });
    }

    public function boot(): void
    {
        $sync = function (ProjectMember $member, bool $joined): void {
            DB::afterCommit(function () use ($member, $joined): void {
                // Quien entra en un proyecto activo participa en el canal de su cliente (D-271),
                // si nunca ha estado en él (quien salió no vuelve a entrar solo).
                if ($joined) {
                    $project = Project::query()->find($member->project_id, ['id', 'client_id', 'status']);
                    $channel = $project?->client_id === null || ! $project->acceptsTime()
                        ? null
                        : Conversation::query()->where('client_id', $project->client_id)->first();
                    if ($channel !== null) {
                        $this->app->make(ChannelMembership::class)->addNew($channel, [(int) $member->user_id]);
                    }
                }

                $conversation = Conversation::query()->where('project_id', $member->project_id)->first();
                if ($conversation === null) {
                    return;
                }

                $directory = $this->app->make(ConversationDirectory::class);
                $joined ? $directory->join($conversation, $member->user_id) : $directory->leave($conversation, $member->user_id);
            });
        };

        ProjectMember::saved(fn (ProjectMember $member) => $sync($member, true));
        ProjectMember::deleted(fn (ProjectMember $member) => $sync($member, false));

        // C1: previsualización del primer enlace de cada mensaje nuevo (D-069) y mensajes de
        // sistema en el chat del proyecto (bolsa al 90 % y al 100 %, hito completado).
        Event::listen(MessagePosted::class, [LinkPreviews::class, 'messagePosted']);
        Event::listen(HourBankThresholdReached::class, [ProjectChatNotices::class, 'hourBankThreshold']);
        Task::updated(fn (Task $task) => $this->app->make(ProjectChatNotices::class)->taskUpdated($task));
    }
}
