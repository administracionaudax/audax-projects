<?php

namespace App\Providers;

use App\Domain\Chat\ConversationDirectory;
use App\Domain\Chat\Transcription\FakeTranscriber;
use App\Domain\Chat\Transcription\TranscriptionService;
use App\Domain\Chat\Transcription\WhisperServerTranscriber;
use App\Models\Conversation;
use App\Models\ProjectMember;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

/**
 * Chat (Fase 6):
 * - el motor de transcripción según services.transcription.driver (whisper en el servidor; fake
 *   en local y en los tests),
 * - el chat de un proyecto sigue a sus miembros: entrar o salir del proyecto es entrar o salir de
 *   su conversación (si ya existe; si no, se crea con todos al primer uso).
 */
class ChatServiceProvider extends ServiceProvider
{
    public function register(): void
    {
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
    }
}
