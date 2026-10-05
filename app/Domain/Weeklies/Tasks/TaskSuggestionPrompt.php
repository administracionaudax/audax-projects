<?php

namespace App\Domain\Weeklies\Tasks;

/**
 * El prompt de «Generar tareas con IA» (F-062), portado palabra por palabra de la Edge Function
 * `extract-tasks` de WeeklySync, con los ids de Audax. Funciones puras.
 */
final class TaskSuggestionPrompt
{
    /**
     * Un reporte de la weekly: el texto general («General / Interno») y uno por cliente, como lo
     * montaba el original.
     *
     * @param  list<array{client: string, text: string}>  $clientEntries
     */
    public static function report(int $authorId, string $authorName, ?string $general, array $clientEntries): string
    {
        $text = 'TEXTO GENERAL: '.(($general ?? '') !== '' ? $general : 'Sin texto general')."\n\n";

        if ($clientEntries !== []) {
            $text .= "REPORTES POR CLIENTE:\n";

            foreach ($clientEntries as $entry) {
                $text .= "\n[CLIENTE: {$entry['client']}]\n{$entry['text']}\n";
            }
        }

        return "AUTHOR_ID: {$authorId} ({$authorName})\nCONTENT:\n{$text}";
    }

    /**
     * @param  list<array{id: int, name: string}>  $users
     * @param  list<array{id: int, name: string}>  $clients
     * @param  list<string>  $reports  self::report()
     */
    public static function prompt(int $userId, string $userName, array $users, array $clients, array $reports): string
    {
        $userMap = implode("\n", array_map(fn (array $user): string => (string) json_encode($user, JSON_UNESCAPED_UNICODE), $users));
        $clientMap = implode("\n", array_map(fn (array $client): string => (string) json_encode($client, JSON_UNESCAPED_UNICODE), $clients));
        $inputs = implode("\n---\n", $reports);

        return <<<PROMPT

Analiza la weekly completa (todos los reportes del equipo). Tu objetivo es extraer tareas accionables que estén asignadas al usuario objetivo.

USUARIO OBJETIVO:
- id: {$userId}
- nombre: {$userName}

REGLAS CRÍTICAS:
1. RESPONSABLE: Identifica QUIÉN debe hacer la tarea
   - Si dice "Yo haré..." → responsable es el autor
   - Si dice "Juan tiene que hacer..." → responsable es Juan

2. CLIENTE: Identifica a QUÉ CLIENTE pertenece la tarea
   - Si la tarea está en una sección "[CLIENTE: NombreCliente]" → usa ese cliente
   - Si menciona un cliente por nombre → busca el ID en la lista
   - Si no hay cliente claro → deja clientId como null

3. FILTRO OBLIGATORIO:
   - Devuelve SOLO tareas cuyo responsable sea el usuario objetivo ({$userName} / {$userId})
   - Ignora tareas asignadas a cualquier otro usuario
   - Si no hay tareas para el usuario objetivo, devuelve []

USUARIOS CONOCIDOS (ID y Nombre):
{$userMap}

CLIENTES CONOCIDOS (ID y Nombre):
{$clientMap}

REPORTES A ANALIZAR:
{$inputs}

FORMATO DE SALIDA - Devuelve un JSON Array:
[{
    "description": "Texto corto de la tarea en imperativo (ej: Revisar diseño, Implementar API)",
    "assigneeId": "ID del usuario responsable (obligatorio, debe ser uno de arriba)",
    "assignerId": "ID del autor del reporte",
    "clientId": "ID del cliente si se detecta, o null si es tarea general",
    "status": "TODO"
}]

IMPORTANTE:
- Solo extrae tareas FUTURAS (próximos pasos, pendientes)
- NO extraes tareas ya completadas
- Si una tarea menciona un cliente específico, SIEMPRE asigna el clientId correcto
- El campo "assigneeId" debe ser SIEMPRE "{$userId}" (usuario objetivo)
- Si no hay tareas, devuelve array vacío []
- Devuelve SOLO el JSON, sin texto adicional

Ejemplo:
Si el texto dice "[CLIENTE: Audax] Tengo que revisar el dashboard", la tarea debe tener el clientId de Audax.

PROMPT;
    }

    /**
     * La forma de la respuesta (responseSchema de Gemini): la del «FORMATO DE SALIDA» del prompt.
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'type' => 'ARRAY',
            'items' => [
                'type' => 'OBJECT',
                'properties' => [
                    'description' => ['type' => 'STRING'],
                    'assigneeId' => ['type' => 'STRING'],
                    'assignerId' => ['type' => 'STRING', 'nullable' => true],
                    'clientId' => ['type' => 'STRING', 'nullable' => true],
                    'status' => ['type' => 'STRING'],
                ],
                'required' => ['description', 'assigneeId'],
                'propertyOrdering' => ['description', 'assigneeId', 'assignerId', 'clientId', 'status'],
            ],
        ];
    }

    /**
     * Deduplicación de App.tsx (`handleGenerateTasks`, F-062), sin cambios: una tarea propuesta está
     * repetida si ya tengo otra del mismo cliente con la misma descripción o una que contiene a la
     * otra (sin distinguir mayúsculas ni espacios de los extremos).
     *
     * @param  list<array{title: string, client_id: int|null}>  $existing
     */
    public static function isDuplicate(string $description, ?int $clientId, array $existing): bool
    {
        $description = mb_strtolower(trim($description));

        foreach ($existing as $task) {
            if ($task['client_id'] !== $clientId) {
                continue;
            }

            $current = mb_strtolower(trim($task['title']));

            if ($current === $description || str_contains($current, $description) || str_contains($description, $current)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Las filas de la respuesta: la lista tal cual o, si la IA la envuelve, la de `tasks`.
     *
     * @param  array<array-key, mixed>|null  $json
     * @return list<array<string, mixed>>
     */
    public static function rows(?array $json): array
    {
        if ($json === null) {
            return [];
        }

        $rows = array_is_list($json) ? $json : (is_array($json['tasks'] ?? null) ? $json['tasks'] : []);

        return array_values(array_filter($rows, is_array(...)));
    }

    /** Un id de la respuesta («7», 7 o null). */
    public static function id(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && preg_match('/^\s*(\d+)\s*$/', $value, $match) === 1) {
            return (int) $match[1] > 0 ? (int) $match[1] : null;
        }

        return null;
    }
}
