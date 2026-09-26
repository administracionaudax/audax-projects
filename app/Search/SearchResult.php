<?php

namespace App\Search;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Un resultado de la búsqueda global. Contrato JSON: {type, id, title, subtitle, url}.
 *
 * @implements Arrayable<string, int|string|null>
 */
final readonly class SearchResult implements Arrayable, JsonSerializable
{
    public function __construct(
        public string $type,
        public int|string $id,
        public string $title,
        public ?string $subtitle,
        public ?string $url,
    ) {}

    /**
     * @return array{type: string, id: int|string, title: string, subtitle: string|null, url: string|null}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'id' => $this->id,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'url' => $this->url,
        ];
    }

    /**
     * @return array{type: string, id: int|string, title: string, subtitle: string|null, url: string|null}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
