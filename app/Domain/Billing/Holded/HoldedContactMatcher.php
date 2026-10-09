<?php

namespace App\Domain\Billing\Holded;

use App\Models\Client;
use App\Models\ClientBillingProfile;
use App\Models\HoldedContact;
use Illuminate\Support\Str;

/**
 * Casa los contactos de Holded con los clientes de Audax (Fase 12, F1; D-387):
 * 1. por NIF (sin espacios, guiones ni el prefijo «ES» del NIF-IVA),
 * 2. si no, por el nombre o el nombre comercial normalizados (sin tildes, signos ni la forma
 *    jurídica: «Hoteles Mirador, S.A.» = «Hoteles Mirador»), solo si casa con UN cliente.
 * 3. si no, por un nombre parecido (D-248), también solo si casa con UN cliente, y queda «por
 *    revisar» (MATCH_APPROX):
 *    - el mismo nombre sin espacios («Naranjas y Frutas» = «Naranjasyfrutas», «AGENCIA SEO» =
 *      «AgenciaSEO»),
 *    - todas las palabras con peso del cliente están en el nombre o el nombre comercial del
 *      contacto («Montó» en «PINTURAS MONTÓ, S.A.U», «Atica» en «NOVA ATICA SA»),
 *    - o todas las del nombre comercial del contacto están en el del cliente («SITRA» en «Sitra -
 *      SIQUIMICA»).
 *    No cuentan las palabras de menos de 3 letras ni las genéricas (grupo, soluciones, agencia…), y
 *    nunca los clientes internos de Audax.
 * Nunca crea clientes ni cambia un enlace hecho a mano o un contacto descartado. Al casar, rellena
 * los datos fiscales que el cliente aún no tiene (NIF, razón social, dirección y país); lo que ya
 * está escrito en Audax no se toca.
 */
final class HoldedContactMatcher
{
    /** Formas jurídicas que se quitan del nombre al compararlo. */
    private const array LEGAL_FORMS = ['s l u', 's l l', 's l', 's a u', 's a', 'slu', 'sll', 'sl', 'sau', 'sa', 's coop', 'scoop', 'sociedad limitada', 'sociedad anonima', 'cb', 's c', 'sc'];

    /** @var array<string, list<int>>|null NIF normalizado → clientes */
    private ?array $byTaxId = null;

    /** @var array<string, list<int>>|null nombre normalizado → clientes */
    private ?array $byName = null;

    /** @var array<string, list<int>>|null nombre sin espacios → clientes */
    private ?array $byCompact = null;

    /** @var array<int, list<string>>|null cliente → sus palabras con peso */
    private ?array $tokens = null;

    /** Palabras que no bastan para casar por sí solas. */
    private const array GENERIC = ['grupo', 'group', 'solutions', 'soluciones', 'solucion', 'international', 'internacional', 'spain', 'espana', 'iberica', 'sociedad', 'limitada', 'anonima', 'agencia', 'digital', 'partners', 'partner', 'servicios', 'gestiones', 'marketing', 'comunicacion', 'consultores', 'consulting', 'the', 'and', 'del', 'las', 'los', 'con', 'para', 'por', 'hnos', 'hermanos', 'tech', 'technologies', 'global', 'studio', 'estudio', 'region', 'unipessoal', 'lda', 'gmbh', 'ltd', 'inc', 'srl', 'web', 'leads', 'general'];

    public function match(HoldedContact $contact): void
    {
        if ($contact->match_method === HoldedContact::MATCH_MANUAL || $contact->ignored_at !== null) {
            return;
        }

        $this->load();
        [$clientId, $method] = $this->find($contact);

        $contact->client_id = $clientId;
        $contact->match_method = $method;
    }

    /** Rellena lo que le falta al cliente con los datos de Holded (solo campos vacíos). */
    public function fillClient(HoldedContact $contact): void
    {
        if ($contact->client_id === null) {
            return;
        }

        $client = Client::query()->withTrashed()->find($contact->client_id);
        if ($client === null) {
            return;
        }

        if (trim((string) $client->tax_id) === '' && $contact->tax_id !== null) {
            $client->tax_id = $contact->tax_id_normalized ?? $contact->tax_id;
            $client->save();
        }

        $profile = ClientBillingProfile::query()->firstOrNew(['client_id' => $client->id]);
        $address = $contact->address ?? [];
        $values = [
            'legal_name' => $contact->name,
            'address' => $address['address'] ?? null,
            'postal_code' => $address['postal_code'] ?? null,
            'city' => $address['city'] ?? null,
            'province' => $address['province'] ?? null,
        ];

        foreach ($values as $field => $value) {
            if (trim((string) $profile->getAttribute($field)) === '' && is_string($value) && trim($value) !== '') {
                $profile->setAttribute($field, Str::limit(trim($value), 190, ''));
            }
        }

        if (! $profile->exists && $contact->country_code !== null) {
            $profile->country_code = strtoupper($contact->country_code);
        }

        if ($profile->isDirty()) {
            $profile->save();
        }
    }

    /** Olvida los clientes leídos (si cambian durante la sincronización). */
    public function reset(): void
    {
        $this->byTaxId = null;
        $this->byName = null;
        $this->byCompact = null;
        $this->tokens = null;
    }

    /**
     * Palabras con peso de un nombre normalizado: de 3 letras o más y no genéricas.
     *
     * @return list<string>
     */
    public static function weightyTokens(string $normalized): array
    {
        return array_values(array_unique(array_filter(
            explode(' ', $normalized),
            fn (string $word): bool => strlen($word) >= 3 && ! in_array($word, self::GENERIC, true),
        )));
    }

    public static function normalizeName(?string $name): string
    {
        $value = Str::lower(Str::ascii((string) $name));
        $value = (string) preg_replace('/[^a-z0-9]+/', ' ', $value);
        $value = ' '.trim($value).' ';

        foreach (self::LEGAL_FORMS as $form) {
            if (str_ends_with($value, ' '.$form.' ')) {
                $value = substr($value, 0, -strlen($form) - 1);
                break;
            }
        }

        return trim((string) preg_replace('/\s+/', ' ', $value));
    }

    /**
     * @return array{0: int|null, 1: string|null}
     */
    private function find(HoldedContact $contact): array
    {
        $taxId = $contact->tax_id_normalized;
        if ($taxId !== null && count($this->byTaxId[$taxId] ?? []) === 1) {
            return [$this->byTaxId[$taxId][0], HoldedContact::MATCH_TAX_ID];
        }

        foreach ([$contact->name, $contact->trade_name] as $name) {
            $key = self::normalizeName($name);
            if ($key !== '' && count($this->byName[$key] ?? []) === 1) {
                return [$this->byName[$key][0], HoldedContact::MATCH_NAME];
            }
        }

        $approx = $this->approximate($contact);

        return $approx !== null ? [$approx, HoldedContact::MATCH_APPROX] : [null, null];
    }

    /** El único cliente con un nombre parecido (D-248), o null si no hay ninguno o hay varios. */
    private function approximate(HoldedContact $contact): ?int
    {
        $found = $this->approximateCandidates($contact);

        if (count($found) <= 1) {
            return $found[0] ?? null;
        }

        return $this->mostSpecific($found);
    }

    /**
     * Los clientes con un nombre parecido (D-248): el mismo nombre sin espacios o todas las
     * palabras con peso de uno en el otro.
     *
     * @return list<int>
     */
    private function approximateCandidates(HoldedContact $contact): array
    {
        $names = array_values(array_filter([self::normalizeName($contact->name), self::normalizeName($contact->trade_name)]));

        foreach ($names as $name) {
            $compact = str_replace(' ', '', $name);
            if (strlen($compact) >= 4 && count($this->byCompact[$compact] ?? []) === 1) {
                return $this->byCompact[$compact];
            }
        }

        $contactWords = self::weightyTokens(implode(' ', $names));
        $tradeWords = self::weightyTokens(self::normalizeName($contact->trade_name));
        $found = [];

        foreach ($this->tokens ?? [] as $clientId => $clientWords) {
            $clientInContact = $clientWords !== [] && array_diff($clientWords, $contactWords) === [];
            $tradeInClient = $tradeWords !== [] && array_diff($tradeWords, $clientWords) === [];

            if ($clientInContact || $tradeInClient) {
                $found[] = $clientId;
            }
        }

        return $found;
    }

    /**
     * Varios: gana el más concreto si sus palabras incluyen las de todos los demás («Montó Export»
     * frente a «Montó»); si no, es dudoso y no casa con ninguno.
     *
     * @param  list<int>  $found
     */
    private function mostSpecific(array $found): ?int
    {
        foreach ($found as $candidate) {
            $words = $this->tokens[$candidate] ?? [];
            $coversAll = array_filter($found, fn (int $other): bool => $other !== $candidate
                && (array_diff($this->tokens[$other] ?? [], $words) !== [] || count($this->tokens[$other] ?? []) >= count($words)));

            if ($coversAll === []) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * La propuesta para casar a mano un contacto (I5, D-413, amplía D-248): casar solo sigue
     * exigiendo un único candidato (match()), pero en «Por revisar» se propone el mejor, con su
     * motivo y su confianza, para confirmarlo con un clic:
     * - **alta**: sus facturas ya están enlazadas con proyectos de un solo cliente (por el código F
     *   o el proyecto de Holded), o un único cliente con su NIF o su nombre (casaría en la próxima
     *   lectura: el cliente se creó o se corrigió después),
     * - **media**: un nombre parecido único (D-248), o varios clientes con el mismo NIF o nombre (el
     *   que más palabras comparte),
     * - **baja**: varios nombres parecidos o solo alguna palabra en común (el que más comparte).
     * Un contacto ya casado por un nombre parecido propone ese cliente (media).
     *
     * @param  array<int, string>  $linkedClients  cliente de los proyectos enlazados con sus facturas => método («f_code» o «proyecto»)
     * @return array{client_id: int, reason: string, confidence: string}|null
     */
    public function propose(HoldedContact $contact, array $linkedClients = []): ?array
    {
        if ($contact->client_id !== null) {
            return ['client_id' => $contact->client_id, 'reason' => 'parecido', 'confidence' => 'media'];
        }

        $this->load();

        if (count($linkedClients) === 1) {
            return ['client_id' => (int) array_key_first($linkedClients), 'reason' => (string) reset($linkedClients), 'confidence' => 'alta'];
        }

        $names = array_values(array_filter([self::normalizeName($contact->name), self::normalizeName($contact->trade_name)]));
        $words = self::weightyTokens(implode(' ', $names));

        $taxId = $contact->tax_id_normalized;
        if ($taxId !== null && ($this->byTaxId[$taxId] ?? []) !== []) {
            $ids = $this->byTaxId[$taxId];

            return ['client_id' => $this->closest($ids, $words), 'reason' => 'nif', 'confidence' => count($ids) === 1 ? 'alta' : 'media'];
        }

        foreach ($names as $name) {
            $ids = $this->byName[$name] ?? [];
            if ($ids !== []) {
                return ['client_id' => $this->closest($ids, $words), 'reason' => 'nombre', 'confidence' => count($ids) === 1 ? 'alta' : 'media'];
            }
        }

        $similar = $this->approximateCandidates($contact);
        if ($similar !== []) {
            $best = count($similar) === 1 ? $similar[0] : $this->mostSpecific($similar);

            return $best !== null
                ? ['client_id' => $best, 'reason' => 'parecido', 'confidence' => 'media']
                : ['client_id' => $this->closest($similar, $words), 'reason' => 'parecido', 'confidence' => 'baja'];
        }

        // Ninguna regla: el cliente que más palabras con peso comparte, si comparte alguna.
        $shared = array_keys(array_filter($this->tokens ?? [], fn (array $clientWords): bool => array_intersect($clientWords, $words) !== []));

        return $shared === [] ? null : ['client_id' => $this->closest($shared, $words), 'reason' => 'palabras', 'confidence' => 'baja'];
    }

    /**
     * De unos clientes, el que más palabras con peso comparte con $words (Jaccard); a igualdad, el
     * primero (el de menor id).
     *
     * @param  list<int>  $ids
     * @param  list<string>  $words
     */
    private function closest(array $ids, array $words): int
    {
        $best = $ids[0];
        $bestScore = -1.0;
        foreach ($ids as $id) {
            $clientWords = $this->tokens[$id] ?? [];
            $union = count(array_unique([...$clientWords, ...$words]));
            $score = $union === 0 ? 0.0 : count(array_intersect($clientWords, $words)) / $union;
            if ($score > $bestScore) {
                $best = $id;
                $bestScore = $score;
            }
        }

        return $best;
    }

    private function load(): void
    {
        if ($this->byTaxId !== null && $this->byName !== null && $this->byCompact !== null && $this->tokens !== null) {
            return;
        }

        $this->byTaxId = [];
        $this->byName = [];
        $this->byCompact = [];
        $this->tokens = [];

        foreach (Client::query()->orderBy('id')->get(['id', 'name', 'tax_id']) as $client) {
            $taxId = HoldedPayload::normalizeTaxId($client->tax_id);
            if ($taxId !== null) {
                $this->byTaxId[$taxId][] = $client->id;
            }

            $name = self::normalizeName($client->name);
            if ($name !== '') {
                $this->byName[$name][] = $client->id;
            }

            // Los clientes internos de Audax (Audax Web, Audax Leads…) nunca casan por parecido.
            if ($name === '' || str_starts_with($name, 'audax')) {
                continue;
            }
            $this->byCompact[str_replace(' ', '', $name)][] = $client->id;
            $this->tokens[$client->id] = self::weightyTokens($name);
        }
    }
}
