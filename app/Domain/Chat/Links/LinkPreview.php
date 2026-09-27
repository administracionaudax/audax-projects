<?php

namespace App\Domain\Chat\Links;

/**
 * Previsualización de un enlace (D-069): título, descripción y dominio. Nunca una imagen remota.
 */
final readonly class LinkPreview
{
    public function __construct(
        public string $url,
        public string $title,
        public ?string $description,
        public string $domain,
    ) {}

    /**
     * @return array{url: string, title: string, description: string|null, domain: string}
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'title' => $this->title,
            'description' => $this->description,
            'domain' => $this->domain,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $url = $data['url'] ?? null;
        $title = $data['title'] ?? null;
        $domain = $data['domain'] ?? null;
        $description = $data['description'] ?? null;

        if (! is_string($url) || ! is_string($title) || ! is_string($domain)) {
            return null;
        }

        return new self($url, $title, is_string($description) ? $description : null, $domain);
    }
}
