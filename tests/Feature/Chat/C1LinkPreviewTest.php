<?php

use App\Domain\Chat\ConversationDirectory;
use App\Domain\Chat\Links\FetchLinkPreview;
use App\Domain\Chat\Links\FirstLink;
use App\Domain\Chat\Links\HostResolver;
use App\Domain\Chat\Links\IpGuard;
use App\Domain\Chat\Links\LinkPreviewFetcher;
use App\Domain\Chat\MessageWriter;
use App\Events\Chat\MessageUpdated;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
| Previsualización de enlaces (D-069) con protección SSRF estricta: la IP se comprueba ANTES de
| conectar y en cada redirección (privadas, locales, link-local, multicast y reservadas, IPv4 e
| IPv6), solo http(s) en los puertos 80 y 443, 3 redirecciones, 512 KB y solo text/html. Las
| resoluciones DNS se simulan (HostResolver) y la red, con Http::fake: ningún test sale fuera.
*/

beforeEach(function () {
    Storage::fake('local');
    Http::preventStrayRequests();
    config(['link_previews.enabled' => true]);

    $this->dns = new class implements HostResolver
    {
        /** @var array<string, list<string>> */
        public array $map = [];

        /** @var list<string> */
        public array $asked = [];

        public function resolve(string $host): array
        {
            $this->asked[] = $host;

            return $this->map[$host] ?? [];
        }
    };
    $this->dns->map = [
        'audaxstudio.com' => ['185.230.63.107'],
        'www.audaxstudio.com' => ['185.230.63.107'],
        'blog.audaxstudio.com' => ['2a00:1450:4003:80e::2003'],
        'interno.audaxstudio.com' => ['10.0.0.5'],
        'rebind.example.org' => ['185.230.63.107', '127.0.0.1'],
        'metadatos.example.org' => ['169.254.169.254'],
        'v6local.example.org' => ['fd00::1'],
        'mapeada.example.org' => ['::ffff:127.0.0.1'],
    ];
    $this->app->instance(HostResolver::class, $this->dns);

    $this->html = fn (string $title, string $description = 'Agencia de UX/UI en Valencia'): string => '<!doctype html><html><head><meta charset="utf-8">'
        ."<title>{$title} (title)</title><meta property=\"og:title\" content=\"{$title}\"><meta property=\"og:description\" content=\"{$description}\">"
        .'<meta property="og:image" content="https://audaxstudio.com/og.png"></head><body><script>alert(1)</script></body></html>';
    $this->fetcher = fn (): LinkPreviewFetcher => app(LinkPreviewFetcher::class);

    $this->ana = User::factory()->employee()->create();
    $this->luis = User::factory()->employee()->create();
    $this->chat = app(ConversationDirectory::class)->direct($this->ana, $this->luis);
    $this->writer = app(MessageWriter::class);
});

it('encuentra el primer enlace http(s), fuera del código y sin la puntuación final', function (string $body, ?string $url) {
    expect(FirstLink::in($body))->toBe($url);
})->with([
    ['Mira https://audaxstudio.com.', 'https://audaxstudio.com'],
    ['Mira [la web](https://audaxstudio.com/proyectos) y https://otra.es', 'https://audaxstudio.com/proyectos'],
    ['(ver https://es.wikipedia.org/wiki/Valencia_(España))', 'https://es.wikipedia.org/wiki/Valencia_(España)'],
    ['`https://no.es` y luego http://sí.es/a?b=1#c', 'http://sí.es/a?b=1#c'],
    ["```\nhttps://bloque.es\n```", null],
    ['[mal](javascript:alert(1)) y ftp://x.es', null],
    ['sin enlaces', null],
]);

it('solo da por públicas las IP globales (IPv4 e IPv6)', function (string $ip, bool $public) {
    expect(IpGuard::isPublic($ip))->toBe($public);
})->with([
    ['185.230.63.107', true],
    ['8.8.8.8', true],
    ['2a00:1450:4003:80e::2003', true],
    ['127.0.0.1', false],
    ['10.1.2.3', false],
    ['172.16.0.1', false],
    ['192.168.1.1', false],
    ['169.254.169.254', false],
    ['100.64.0.1', false],
    ['0.0.0.0', false],
    ['192.0.2.10', false],
    ['198.18.0.1', false],
    ['224.0.0.1', false],
    ['240.0.0.1', false],
    ['255.255.255.255', false],
    ['::1', false],
    ['::', false],
    ['fd00::1', false],
    ['fe80::1', false],
    ['ff02::1', false],
    ['2001:db8::1', false],
    ['2002:7f00:1::1', false],
    ['64:ff9b::7f00:1', false],
    ['::ffff:127.0.0.1', false],
    ['::ffff:8.8.8.8', true],
    ['no-es-una-ip', false],
]);

it('al publicar un mensaje con enlace guarda título, descripción y dominio (sin imagen)', function () {
    Http::fake(['https://www.audaxstudio.com/*' => Http::response(($this->html)('Audax Studio'), 200, ['Content-Type' => 'text/html; charset=utf-8'])]);
    Event::fake([MessageUpdated::class]);

    $message = $this->writer->post($this->ana, $this->chat, 'Nuestra web: https://www.audaxstudio.com/estudio');

    expect($message->fresh()->link_preview)->toBe([
        'url' => 'https://www.audaxstudio.com/estudio',
        'title' => 'Audax Studio',
        'description' => 'Agencia de UX/UI en Valencia',
        'domain' => 'audaxstudio.com',
    ]);
    Event::assertDispatched(MessageUpdated::class);
    Http::assertSentCount(1);

    $this->actingAs($this->luis)
        ->getJson("/chat/mensajes/{$message->id}")
        ->assertJsonPath('message.link_preview.title', 'Audax Studio')
        ->assertJsonPath('message.link_preview.domain', 'audaxstudio.com');
});

it('el job va a la cola default y solo cuando se confirma el mensaje', function () {
    Queue::fake();

    $message = $this->writer->post($this->ana, $this->chat, 'https://audaxstudio.com');

    Queue::assertPushedOn('default', FetchLinkPreview::class, fn (FetchLinkPreview $job): bool => $job->messageId === $message->id && $job->url === 'https://audaxstudio.com');
});

it('no conecta a nada que resuelva a una IP no pública (ni aunque otra sí lo sea)', function (string $url) {
    Http::fake();

    expect(($this->fetcher)()->fetch($url))->toBeNull();
    Http::assertNothingSent();
})->with([
    'nombre interno' => 'https://interno.audaxstudio.com/',
    'DNS con una IP pública y otra local' => 'https://rebind.example.org/',
    'metadatos de la nube' => 'http://metadatos.example.org/latest/meta-data',
    'IPv6 local' => 'https://v6local.example.org/',
    'IPv4 mapeada en IPv6' => 'https://mapeada.example.org/',
    'nombre que no resuelve' => 'https://no-existe.example.net/',
    'IP local escrita tal cual' => 'http://127.0.0.1/',
    'IPv6 de loopback' => 'http://[::1]/',
    'IP de metadatos escrita tal cual' => 'http://169.254.169.254/latest/meta-data',
    'localhost' => 'http://localhost/',
    'nombre de una sola etiqueta' => 'http://intranet/',
    'IP en decimal' => 'http://2130706433/',
    'IP en hexadecimal' => 'http://0x7f.0.0.1/',
    'dominio .local' => 'http://impresora.local/',
    'puerto no admitido' => 'https://audaxstudio.com:8443/',
    'puerto interno' => 'http://audaxstudio.com:6379/',
    'credenciales en la URL' => 'https://usuario:clave@audaxstudio.com/',
    'otro esquema' => 'file:///etc/passwd',
]);

it('vuelve a comprobar la IP en cada redirección', function () {
    Http::fake([
        'https://audaxstudio.com/a' => Http::response('', 302, ['Location' => 'http://127.0.0.1/admin']),
        'https://audaxstudio.com/b' => Http::response('', 301, ['Location' => 'https://interno.audaxstudio.com/']),
        '*' => Http::response(($this->html)('No debería llegar'), 200, ['Content-Type' => 'text/html']),
    ]);

    expect(($this->fetcher)()->fetch('https://audaxstudio.com/a'))->toBeNull()
        ->and(($this->fetcher)()->fetch('https://audaxstudio.com/b'))->toBeNull();

    Http::assertSentCount(2);
});

it('sigue como mucho 3 redirecciones (relativas o absolutas) a sitios públicos', function () {
    Http::fake([
        'https://audaxstudio.com/1' => Http::response('', 302, ['Location' => '/2']),
        'https://audaxstudio.com/2' => Http::response('', 302, ['Location' => 'https://blog.audaxstudio.com/3']),
        'https://blog.audaxstudio.com/3' => Http::response('', 302, ['Location' => 'final']),
        'https://blog.audaxstudio.com/final' => Http::response(($this->html)('Por fin'), 200, ['Content-Type' => 'text/html']),
        'https://audaxstudio.com/bucle' => Http::response('', 302, ['Location' => '/bucle']),
    ]);

    $preview = ($this->fetcher)()->fetch('https://audaxstudio.com/1');
    expect($preview?->title)->toBe('Por fin')
        ->and($preview?->url)->toBe('https://audaxstudio.com/1')
        ->and($preview?->domain)->toBe('blog.audaxstudio.com');

    expect(($this->fetcher)()->fetch('https://audaxstudio.com/bucle'))->toBeNull();
});

it('solo lee HTML, como mucho 512 KB, y da por perdido lo que falla o tarda', function () {
    $padding = str_repeat('<p>relleno</p>', 60_000); // ~840 KB
    Http::fake([
        'https://audaxstudio.com/pdf' => Http::response('%PDF-1.7', 200, ['Content-Type' => 'application/pdf']),
        'https://audaxstudio.com/imagen' => Http::response('png', 200, ['Content-Type' => 'image/png']),
        'https://audaxstudio.com/grande' => Http::response('<html><body>'.$padding.'<title>Tarde</title></body></html>', 200, ['Content-Type' => 'text/html']),
        'https://audaxstudio.com/cabecera' => Http::response('<html><head><title>Arriba</title></head><body>'.$padding.'</body></html>', 200, ['Content-Type' => 'text/html']),
        'https://audaxstudio.com/error' => Http::response('Error', 500, ['Content-Type' => 'text/html']),
        'https://audaxstudio.com/lento' => fn () => throw new ConnectionException('Timeout (3 s)'),
        'https://audaxstudio.com/sin-titulo' => Http::response('<html><head></head><body>Nada</body></html>', 200, ['Content-Type' => 'text/html']),
    ]);
    $fetch = fn (string $path) => ($this->fetcher)()->fetch("https://audaxstudio.com/{$path}");

    expect($fetch('pdf'))->toBeNull()
        ->and($fetch('imagen'))->toBeNull()
        ->and($fetch('grande'))->toBeNull()
        ->and($fetch('cabecera')?->title)->toBe('Arriba')
        ->and($fetch('error'))->toBeNull()
        ->and($fetch('lento'))->toBeNull()
        ->and($fetch('sin-titulo'))->toBeNull();
});

it('lee la codificación de la página y limpia el texto', function () {
    $latin = mb_convert_encoding('<html><head><title>  Diseño   &amp; código  </title><meta name="description" content="Más  información"></head></html>', 'ISO-8859-1', 'UTF-8');
    Http::fake(['https://audaxstudio.com/latin' => Http::response($latin, 200, ['Content-Type' => 'text/html; charset=ISO-8859-1'])]);

    $preview = ($this->fetcher)()->fetch('https://audaxstudio.com/latin');

    expect($preview?->title)->toBe('Diseño & código')
        ->and($preview?->description)->toBe('Más información')
        ->and($preview?->toArray())->not->toHaveKey('image');
});

it('recuerda la previsualización de cada URL en caché', function () {
    Http::fake(['https://audaxstudio.com/*' => Http::response(($this->html)('En caché'), 200, ['Content-Type' => 'text/html'])]);

    expect(($this->fetcher)()->preview('https://audaxstudio.com/x')?->title)->toBe('En caché')
        ->and(($this->fetcher)()->preview('https://audaxstudio.com/x')?->title)->toBe('En caché');

    Http::assertSentCount(1);
});

it('al editar se rehace con el nuevo enlace y se quita si ya no hay enlace', function () {
    Http::fake([
        'https://audaxstudio.com/uno' => Http::response(($this->html)('Uno'), 200, ['Content-Type' => 'text/html']),
        'https://audaxstudio.com/dos' => Http::response(($this->html)('Dos'), 200, ['Content-Type' => 'text/html']),
    ]);
    $message = $this->writer->post($this->ana, $this->chat, 'https://audaxstudio.com/uno');
    expect($message->fresh()->link_preview['title'] ?? null)->toBe('Uno');

    // La respuesta de la edición ya no trae la anterior; la nueva llega con el job (aquí, síncrono).
    $this->actingAs($this->ana)->patchJson("/chat/mensajes/{$message->id}", ['body' => 'Mejor https://audaxstudio.com/dos'])
        ->assertJsonPath('message.link_preview', null);
    expect($message->fresh()->link_preview['title'] ?? null)->toBe('Dos');

    $this->actingAs($this->ana)->patchJson("/chat/mensajes/{$message->id}", ['body' => 'Sin enlace'])
        ->assertJsonPath('message.link_preview', null);
});

it('el job no toca un mensaje que ya no tiene ese enlace, ni uno borrado u ocultado', function () {
    Http::fake();
    $message = $this->writer->post($this->ana, $this->chat, 'Sin enlace todavía');

    app()->call([new FetchLinkPreview($message->id, 'https://audaxstudio.com'), 'handle']);

    expect($message->fresh()->link_preview)->toBeNull();
    Http::assertNothingSent();
});

it('apagado (como en el resto de tests) no pide nada', function () {
    config(['link_previews.enabled' => false]);
    Queue::fake();

    $this->writer->post($this->ana, $this->chat, 'https://audaxstudio.com');

    Queue::assertNothingPushed();
});
