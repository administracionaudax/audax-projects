<?php

namespace App\Notifications\People;

use App\Models\PeopleDocument;
use App\Notifications\AppNotification;

/**
 * RR. HH. publica una versión nueva del documento de implantación del registro o de la política
 * de desconexión digital (D-354 y D-356): a la plantilla, para que lo lea.
 */
class PeopleDocumentPublished extends AppNotification
{
    public function __construct(public readonly PeopleDocument $document) {}

    public function kind(): string
    {
        return 'people.document_published';
    }

    public function title(object $notifiable): string
    {
        return (string) __('people.notifications.document_title', ['title' => $this->document->title]);
    }

    public function url(object $notifiable): ?string
    {
        return '/personas/documentos';
    }

    public function icon(): ?string
    {
        return 'file-text';
    }
}
