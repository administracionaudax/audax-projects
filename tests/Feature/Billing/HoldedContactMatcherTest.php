<?php

use App\Domain\Billing\Holded\HoldedContactMatcher;
use App\Models\Client;
use App\Models\HoldedContact;
use Inertia\Testing\AssertableInertia;

/*
| Casar contactos de Holded con clientes por un nombre parecido (D-248), con los casos reales que
| no casaban el 08/10/2026: solo si señala a UN cliente, y queda «por revisar».
*/

beforeEach(function () {
    foreach (['Montó', 'Atica', 'Naranjasyfrutas', 'AgenciaSEO', 'EZPlus', 'Virosa', 'Melodía', 'Fanté', 'Kensight',
        'Sitra - SIQUIMICA', 'Equipson Group', 'Clavei', 'H&N', 'Kora Travel', 'Audax Web', 'Audax Leads', 'Volare', 'Access'] as $name) {
        Client::factory()->create(['name' => $name, 'tax_id' => null]);
    }
});

function matchContact(string $name, ?string $trade = null): array
{
    static $n = 0;
    $contact = HoldedContact::query()->create(['holded_id' => 'c'.(++$n), 'name' => $name, 'trade_name' => $trade]);
    $matcher = app(HoldedContactMatcher::class);
    $matcher->reset();
    $matcher->match($contact);

    return [$contact->client_id !== null ? Client::query()->whereKey($contact->client_id)->value('name') : null, $contact->match_method];
}

it('casa por un nombre parecido cuando solo hay un cliente posible', function (string $name, ?string $trade, ?string $client) {
    [$found, $method] = matchContact($name, $trade);

    expect($found)->toBe($client)
        ->and($method)->toBe($client === null ? null : HoldedContact::MATCH_APPROX);
})->with([
    'nombre del cliente dentro del contacto' => ['PINTURAS MONTÓ, S.A.U', 'Pinturas Montó', 'Montó'],
    'otra empresa del mismo cliente' => ['TINTAS MONTÓ, UNIPESSOAL LDA', 'Tintas Montó', 'Montó'],
    'con la forma jurídica delante' => ['NOVA ATICA SA', null, 'Atica'],
    'sin espacios (nombre comercial)' => ['SERVIFRUIT SERVICIOS Y GESTIONES S.L', 'Naranjas y Frutas', 'Naranjasyfrutas'],
    'sin espacios (agencia)' => ['HRL Marketing y Comunicación, SL', 'AGENCIA SEO', 'AgenciaSEO'],
    'sin espacios con forma jurídica' => ['EZ PLUS SL.', 'ASCEND ART', 'EZPlus'],
    'actividad delante' => ['LIMPIEZAS VIROSA, S.L.', null, 'Virosa'],
    'tildes' => ['MELODIA SOLUCIONES SL.', null, 'Melodía'],
    'actividad detrás' => ['FANTE FOODS SL.', null, 'Fanté'],
    'nombre comercial dentro del cliente' => ['SOLUCIONES INDUSTRIALES Y TRATAMIENTOS AMBIENTALES SL', 'SITRA', 'Sitra - SIQUIMICA'],
    'palabra genérica de más en el cliente' => ['Equipson S.A', 'Equipson', 'Equipson Group'],
    'otro nombre comercial que contiene el del cliente' => ['EMPLEO EXPRESS EMPRESA DE TRABAJO TEMPORAL SL', 'Access Talento', 'Access'],
    'parecido pero distinto: no' => ['CLAVE INFORMATICA SOCIEDAD LIMITADA', null, null],
    'solo letras sueltas: no' => ['H&N International GmbH', 'H&N INTERNACIONAL', null],
    'una palabra en común y otra distinta: no' => ['KORA HOSPITALITY TECH SL.', null, null],
    'un cliente interno de Audax nunca' => ['Leads Factory SL', 'Web Leads', null],
]);

it('entre varios gana el más concreto; si son distintos no casa con ninguno; lo exacto sigue ganando', function () {
    Client::factory()->create(['name' => 'Montó Export', 'tax_id' => null]);

    // «Montó» y «Montó Export» encajan: gana el que tiene más palabras en común.
    expect(matchContact('PINTURAS MONTÓ EXPORT SA')[0])->toBe('Montó Export')
        ->and(matchContact('PINTURAS MONTÓ, S.A.U')[0])->toBe('Montó')
        ->and(matchContact('Volare', null))->toBe(['Volare', HoldedContact::MATCH_NAME]);

    // «Atica» y «Virosa» encajan los dos y ninguno incluye al otro: dudoso, ninguno.
    expect(matchContact('ATICA VIROSA SL')[0])->toBeNull();
});

it('los parecidos salen en «Por revisar» y se confirman con un clic; sus facturas ya son del cliente', function () {
    enableBilling();
    $admin = userWithRole('admin');
    $contact = HoldedContact::query()->create(['holded_id' => 'ph1', 'name' => 'PINTURAS MONTÓ, S.A.U']);
    $matcher = app(HoldedContactMatcher::class);
    $matcher->match($contact);
    $contact->save();
    $monto = Client::query()->where('name', 'Montó')->value('id');

    $this->actingAs($admin)->get('/facturacion/por-revisar?vista=por-revisar')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('view', 'por-revisar')
            ->where('counts.por-revisar', 1)
            ->where('contacts.0.match_method', HoldedContact::MATCH_APPROX)
            ->where('contacts.0.client.id', $monto));

    $this->actingAs($admin)->put("/facturacion/contactos/{$contact->id}", ['action' => 'confirm'])->assertRedirect();

    expect($contact->refresh()->match_method)->toBe(HoldedContact::MATCH_MANUAL)
        ->and($contact->client_id)->toBe($monto)
        ->and($contact->resolved_by)->toBe($admin->id);
});
