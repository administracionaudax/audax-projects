<?php

namespace App\Domain\Chat\Links;

use App\Domain\Chat\MessageWriter;
use App\Enums\MessageType;
use App\Events\Chat\MessagePosted;
use App\Models\Message;

/**
 * Cuándo se pide la previsualización (D-069): al publicar un mensaje con un enlace (escucha
 * MessagePosted, venga de donde venga el mensaje) y al editarlo si cambia su primer enlace; si ya
 * no tiene enlace, se quita. Apagado con link_previews.enabled (los tests no salen a la red).
 */
final class LinkPreviews
{
    public function __construct(private readonly MessageWriter $writer) {}

    public function messagePosted(MessagePosted $event): void
    {
        $this->refresh($event->message);
    }

    public function refresh(Message $message): void
    {
        if (! (bool) config('link_previews.enabled', true) || $message->type === MessageType::System) {
            return;
        }

        $url = FirstLink::in($message->body);
        $current = $message->link_preview['url'] ?? null;

        if ($url === null) {
            if ($message->link_preview !== null) {
                $this->writer->setLinkPreview($message, null);
            }

            return;
        }

        if ($url === $current) {
            return;
        }

        // La previsualización anterior (de otro enlace) deja de valer mientras llega la nueva.
        if ($message->link_preview !== null) {
            $this->writer->setLinkPreview($message, null);
        }

        FetchLinkPreview::dispatch($message->id, $url)->afterCommit();
    }
}
