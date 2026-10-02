<?php

namespace App\Broadcasting;

/**
 * Contenido de un aviso Web Push. Viaja cifrado de extremo a extremo (aes128gcm) hasta el
 * navegador, que lo pinta con el service worker (public/sw.js): título, texto, a dónde lleva al
 * pulsarlo (ruta relativa de la app) y la etiqueta que agrupa los avisos de una conversación.
 */
final readonly class WebPushMessage
{
    public function __construct(
        public string $title,
        public ?string $body,
        public string $url,
        public ?string $tag = null,
    ) {}

    public function payload(): string
    {
        return (string) json_encode([
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'tag' => $this->tag,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Cabecera Topic (RFC 8030): un aviso nuevo de la misma conversación sustituye al que el
     * servicio de push aún no había entregado. Máximo 32 caracteres del alfabeto base64url.
     */
    public function topic(): ?string
    {
        if ($this->tag === null) {
            return null;
        }

        $topic = substr((string) preg_replace('/[^A-Za-z0-9_-]/', '', $this->tag), 0, 32);

        return $topic === '' ? null : $topic;
    }
}
