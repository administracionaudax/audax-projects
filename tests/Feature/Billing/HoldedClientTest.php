<?php

use App\Domain\Billing\Holded\HoldedRequestFailed;
use App\Domain\Billing\Holded\HttpHoldedClient;
use App\Domain\Billing\Holded\SimplePdf;
use App\Enums\HoldedDocumentKind;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;

/*
| Cliente de la API v2 de Holded (Fase 12, F1; D-384) con respuestas simuladas (Http::fake): Bearer
| con la clave, paginación por cursor, 429 con Retry-After, errores claros sin la clave, el límite
| por minuto y el PDF (binario o en base64). Nunca se llama a Holded de verdad.
*/

const HOLDED_KEY = 'clave-de-prueba-que-no-debe-salir';

beforeEach(function () {
    Sleep::fake(syncWithCarbon: true);
    RateLimiter::clear(HttpHoldedClient::LIMITER);
});

function holdedClient(int $perMinute = 600, int $maxRetries = 5): HttpHoldedClient
{
    return new HttpHoldedClient(app(HttpFactory::class), HOLDED_KEY, 'https://api.holded.test/api/v2', perMinute: $perMinute, pageSize: 2, maxRetries: $maxRetries, maxRetryAfter: 60);
}

it('recorre todas las páginas con el cursor y manda la clave como Bearer', function () {
    Http::fake([
        'api.holded.test/api/v2/invoices*' => Http::sequence()
            ->push(['data' => [['id' => 'a'], ['id' => 'b']], 'meta' => ['next_cursor' => 'c2']])
            ->push(['data' => [['id' => 'c'], ['id' => 'd']], 'links' => ['next' => 'https://api.holded.test/api/v2/invoices?cursor=c3&limit=2']])
            ->push(['data' => [['id' => 'e']], 'meta' => ['next_cursor' => null]]),
    ]);

    $ids = array_map(fn (array $item): string => $item['id'], iterator_to_array(holdedClient()->invoices(), false));

    expect($ids)->toBe(['a', 'b', 'c', 'd', 'e']);
    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer '.HOLDED_KEY) && (string) ($request->data()['limit'] ?? '') === '2');
    Http::assertSent(fn (Request $request): bool => ($request->data()['cursor'] ?? null) === 'c3');
});

it('recorre las páginas con el formato real de la v2 (items, has_more y cursor)', function () {
    Http::fake([
        'api.holded.test/api/v2/contacts*' => Http::sequence()
            ->push(['items' => [['id' => 'a'], ['id' => 'b']], 'has_more' => true, 'cursor' => 'k2'])
            ->push(['items' => [['id' => 'c']], 'has_more' => false, 'cursor' => null]),
    ]);

    $ids = array_map(fn (array $item): string => $item['id'], iterator_to_array(holdedClient()->contacts(), false));

    expect($ids)->toBe(['a', 'b', 'c']);
    Http::assertSent(fn (Request $request): bool => ($request->data()['cursor'] ?? null) === 'k2');
    Http::assertSentCount(2);
});

it('no entra en bucle si Holded repite un cursor', function () {
    Http::fake(['*' => Http::response(['data' => [['id' => 'x']], 'meta' => ['next_cursor' => 'mismo']])]);

    expect(iterator_to_array(holdedClient()->contacts(), false))->toHaveCount(2);
    Http::assertSentCount(2);
});

it('lee una lista plana (sin data) en una sola página', function () {
    Http::fake(['*' => Http::response([['id' => 'p1', 'name' => 'Uno'], ['id' => 'p2', 'name' => 'Dos']])]);

    expect(iterator_to_array(holdedClient()->projects(), false))->toHaveCount(2);
    Http::assertSentCount(1);
});

it('ante un 429 espera lo que dice Retry-After y reintenta', function () {
    Http::fake(['*' => Http::sequence()
        ->push(['message' => 'Too many'], 429, ['Retry-After' => '7'])
        ->push(['data' => [['id' => 'ok']]])]);

    expect(iterator_to_array(holdedClient()->payments(), false))->toHaveCount(1);
    Sleep::assertSlept(fn ($duration): bool => (int) $duration->totalSeconds === 7, 1);
});

it('Retry-After nunca hace esperar más del máximo', function () {
    Http::fake(['*' => Http::sequence()
        ->push([], 429, ['Retry-After' => '3600'])
        ->push(['data' => []])]);

    iterator_to_array(holdedClient()->payments(), false);
    Sleep::assertSlept(fn ($duration): bool => (int) $duration->totalSeconds === 60, 1);
});

it('si el 429 no cede, para con un error claro', function () {
    Http::fake(['*' => Http::response([], 429, ['Retry-After' => '1'])]);

    expect(fn () => iterator_to_array(holdedClient(maxRetries: 2)->invoices(), false))
        ->toThrow(HoldedRequestFailed::class, 'limitado las peticiones');
    Http::assertSentCount(3);
});

it('con 401 da un error claro que no lleva la clave', function () {
    Http::fake(['*' => Http::response(['message' => 'Unauthorized'], 401)]);

    try {
        iterator_to_array(holdedClient()->contacts(), false);
        $this->fail('Tenía que fallar');
    } catch (HoldedRequestFailed $e) {
        expect($e->getMessage())->toContain('rechazado la clave')->not->toContain(HOLDED_KEY)
            ->and($e->status)->toBe(401);
    }
});

it('con 403 dice qué no puede leer la clave', function () {
    Http::fake(['*' => Http::response([], 403)]);

    expect(fn () => iterator_to_array(holdedClient()->creditNotes(), false))
        ->toThrow(HoldedRequestFailed::class, '/credit-notes');
});

it('reintenta un 5xx con espera', function () {
    Http::fake(['*' => Http::sequence()->push([], 502)->push(['data' => [['id' => 'r']]])]);
    expect(iterator_to_array(holdedClient()->invoices(), false))->toHaveCount(1);
    Sleep::assertSleptTimes(1);
});

it('si el 5xx sigue, falla con un error claro', function () {
    Http::fake(['*' => Http::response([], 503)]);
    expect(fn () => iterator_to_array(holdedClient()->invoices(), false))->toThrow(HoldedRequestFailed::class, 'error 503');
});

it('un fallo de conexión se reintenta y acaba en un error claro', function () {
    Http::fake(['*' => fn () => throw new ConnectionException('timeout')]);

    expect(fn () => iterator_to_array(holdedClient(maxRetries: 1)->contacts(), false))
        ->toThrow(HoldedRequestFailed::class, 'No se ha podido conectar con Holded');
});

it('nunca pasa del límite por minuto: espera a la ventana siguiente', function () {
    Http::fake(['*' => Http::sequence()
        ->push(['data' => [['id' => 1]], 'meta' => ['next_cursor' => 'n1']])
        ->push(['data' => [['id' => 2]], 'meta' => ['next_cursor' => 'n2']])
        ->push(['data' => [['id' => 3]]])]);

    $client = holdedClient(perMinute: 2);
    expect(iterator_to_array($client->contacts(), false))->toHaveCount(3)
        ->and($client->requestCount())->toBe(3);
    Sleep::assertSleptTimes(1);
});

it('descarga el PDF en binario o en base64', function () {
    $pdf = SimplePdf::make('Factura F260001');

    Http::fake([
        '*/invoices/abc/pdf' => Http::response($pdf, 200, ['Content-Type' => 'application/pdf']),
        '*/credit-notes/xyz/pdf' => Http::response(['status' => 1, 'data' => base64_encode($pdf)]),
        '*' => Http::response('<html>no</html>'),
    ]);
    expect(holdedClient()->pdf('abc', HoldedDocumentKind::Invoice))->toBe($pdf);
    expect(holdedClient()->pdf('xyz', HoldedDocumentKind::CreditNote))->toBe($pdf);

    expect(fn () => holdedClient()->pdf('mal', HoldedDocumentKind::Invoice))->toThrow(HoldedRequestFailed::class, 'PDF válido');
});

it('sin clave no se puede crear el cliente', function () {
    config(['services.holded.key' => '']);

    expect(fn () => HttpHoldedClient::fromConfig(app(HttpFactory::class)))->toThrow(HoldedRequestFailed::class, 'HOLDED_API_KEY');
});

it('el PDF de ejemplo es un PDF válido', function () {
    $pdf = SimplePdf::make('Factura F260001', ['Cliente: Montaña, S.L.', 'Total: 1.210,00 €']);

    expect($pdf)->toStartWith('%PDF-1.4')->toContain('%%EOF')->toContain('startxref');
});
