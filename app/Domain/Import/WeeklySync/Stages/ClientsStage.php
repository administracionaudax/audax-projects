<?php

namespace App\Domain\Import\WeeklySync\Stages;

use App\Domain\Import\WeeklySync\WeeklySyncContext;
use App\Domain\Import\WeeklySync\WeeklySyncImportReport as Report;
use App\Domain\Import\WeeklySync\WeeklySyncNames;
use App\Models\Client;
use App\Models\HourBank;
use App\Models\Project;
use Illuminate\Support\Str;

/**
 * Clientes (D-149 y D-214). Para cada cliente de WeeklySync, en este orden:
 *   1. el fichero de clientes (`client`, `client_id` o `create: true`),
 *   2. la correspondencia de una importación anterior (import_refs),
 *   3. el nombre normalizado (WeeklySyncNames::client) contra los clientes de Audax, si es único,
 *   4. la foto actual del estado de proyectos de WeeklySync contra lo importado de ClickUp (D-135):
 *      primero los códigos F de factura (`hour_banks.invoice_reference`) y después los códigos de
 *      proyecto («BH1», «FE2»… contra «CLIENTE-BH1»), siempre que el nombre se parezca. Se avisa en
 *      el informe para revisarlo,
 *   5. si no casa, se crea INACTIVO, sin proyectos.
 * El icono se copia si el de Audax está vacío; la satisfacción actual se pone en WeeksStage (tras
 * saber si Audax ya la calcula por su cuenta).
 */
final class ClientsStage
{
    /** @var array<string, list<int>> nombre normalizado => clientes de Audax */
    private array $byName = [];

    /** @var array<int, string> cliente de Audax => nombre normalizado */
    private array $names = [];

    /** @var array<string, int> código de factura => cliente de Audax */
    private array $byInvoice = [];

    /** @var array<int, list<string>> cliente de Audax => códigos de sus proyectos («BH1», «FE2»…) */
    private array $codes = [];

    public function run(WeeklySyncContext $context): void
    {
        $this->index();
        $current = $this->currentProjects($context);

        foreach ($context->rows('clients') as $row) {
            $id = WeeklySyncContext::id($row['id'] ?? null);
            $name = trim((string) preg_replace('/\s+/u', ' ', WeeklySyncContext::str($row['name'] ?? null)));
            $name = $name !== '' ? $name : 'Cliente de WeeklySync';
            $rule = $context->mappings->client($id, $name);
            $client = null;

            if ($rule !== null && ! $rule['create']) {
                $client = $rule['client_id'] !== null
                    ? Client::query()->find($rule['client_id'])
                    : Client::query()->whereRaw('lower(name) = ?', [Str::lower((string) $rule['client'])])->orderBy('id')->first();

                if ($client === null) {
                    $context->report->warn("El fichero de clientes lleva «{$name}» a un cliente que no existe en Audax: se busca por su nombre.");
                }
            }

            if ($client === null && ($rule === null || ! $rule['create'])) {
                $local = $context->refs->find('client', $id);
                $client = $local !== null ? Client::withTrashed()->find($local) : null;
            }

            if ($client === null && ($rule === null || ! $rule['create'])) {
                $client = $this->byName($name) ?? $this->byProjects($context, $name, $current[$id] ?? ['codes' => [], 'invoices' => []]);
            }

            $icon = WeeklySyncContext::nullableStr($row['icon'] ?? null);

            if ($client === null) {
                $client = new Client;
                $client->forceFill([
                    'name' => Str::limit($name, 255, ''),
                    'icon' => $icon !== null ? mb_substr($icon, 0, 4) : null,
                    'is_active' => false,
                    'satisfaction_score' => self::score($row['current_satisfaction'] ?? null) ?? 50,
                ])->save();
                $this->remember($client);
                $context->report->count('clients', Report::CREATED);
                $context->report->warn("Cliente sin pareja en Audax, creado inactivo: {$client->name}.");
            } else {
                if (($client->icon === null || $client->icon === '') && $icon !== null) {
                    $client->icon = mb_substr($icon, 0, 4);
                }

                $context->report->count('clients', $client->isDirty() ? Report::UPDATED : Report::UNCHANGED);
                $client->save();
            }

            $context->refs->put('client', $id, 'client', $client->id);
            $context->clients[$id] = $client->id;
            $context->clientsByName[WeeklySyncNames::client($name)] ??= $client->id;
        }
    }

    public static function score(mixed $value): ?int
    {
        return is_numeric($value) ? max(0, min(100, (int) round((float) $value))) : null;
    }

    private function index(): void
    {
        foreach (Client::query()->get(['id', 'name']) as $client) {
            $this->remember($client);
        }

        $banks = HourBank::withTrashed()
            ->join('projects', 'projects.id', '=', 'hour_banks.project_id')
            ->whereNotNull('hour_banks.invoice_reference')
            ->whereNotNull('projects.client_id')
            ->toBase()
            ->get(['hour_banks.invoice_reference', 'projects.client_id']);

        foreach ($banks as $bank) {
            /** @var object{invoice_reference: string, client_id: int|string} $bank */
            $this->byInvoice[strtoupper(trim($bank->invoice_reference))] = (int) $bank->client_id;
        }

        foreach (Project::withTrashed()->whereNotNull('client_id')->get(['id', 'client_id', 'code']) as $project) {
            if (preg_match('/-([A-Z]{2,3}\d+)$/i', (string) $project->code, $match) === 1) {
                $this->codes[(int) $project->client_id][] = strtoupper($match[1]);
            }
        }
    }

    private function remember(Client $client): void
    {
        $key = WeeklySyncNames::client($client->name);
        $this->byName[$key][] = $client->id;
        $this->names[$client->id] = $key;
    }

    private function byName(string $name): ?Client
    {
        $matches = array_values(array_unique($this->byName[WeeklySyncNames::client($name)] ?? []));

        return count($matches) === 1 ? Client::query()->find($matches[0]) : null;
    }

    /**
     * @param  array{codes: list<string>, invoices: list<string>}  $current
     */
    private function byProjects(WeeklySyncContext $context, string $name, array $current): ?Client
    {
        $normalized = WeeklySyncNames::client($name);
        $candidates = [];

        foreach ($current['invoices'] as $invoice) {
            if (isset($this->byInvoice[$invoice])) {
                $candidates[] = $this->byInvoice[$invoice];
            }
        }
        $candidates = array_values(array_unique($candidates));
        $how = 'su factura';

        if ($candidates === [] && $current['codes'] !== []) {
            $how = 'sus códigos de proyecto';
            foreach ($this->codes as $clientId => $codes) {
                if (array_diff($current['codes'], $codes) === []) {
                    $candidates[] = $clientId;
                }
            }
        }

        $candidates = array_values(array_filter(
            $candidates,
            fn (int $clientId): bool => WeeklySyncNames::similar($normalized, $this->names[$clientId] ?? ''),
        ));

        if (count($candidates) !== 1) {
            return null;
        }

        $client = Client::query()->find($candidates[0]);

        if ($client !== null) {
            $context->report->warn("Cliente casado por {$how}: «{$name}» de WeeklySync → «{$client->name}». Revísalo.");
        }

        return $client;
    }

    /**
     * Códigos de proyecto y de factura de la foto actual de cada cliente de WeeklySync.
     *
     * @return array<string, array{codes: list<string>, invoices: list<string>}>
     */
    private function currentProjects(WeeklySyncContext $context): array
    {
        $current = [];

        foreach ($context->rows('project_status_entries') as $row) {
            if (($row['is_current'] ?? true) !== true) {
                continue;
            }

            $id = WeeklySyncContext::id($row['client_id'] ?? null);
            $current[$id] ??= ['codes' => [], 'invoices' => []];
            $code = strtoupper(WeeklySyncContext::str($row['project_code'] ?? null));
            $invoice = strtoupper(WeeklySyncContext::str($row['invoice_number'] ?? null));

            if ($code !== '') {
                $current[$id]['codes'][] = $code;
            }
            if ($invoice !== '') {
                $current[$id]['invoices'][] = $invoice;
            }
        }

        return $current;
    }
}
