<?php

namespace App\Search;

use App\Models\User;

/**
 * Fuente de la búsqueda global. Cada fuente es responsable de devolver SOLO lo que $user puede ver.
 * En la Fase 1 se añaden proyectos, tareas y clientes.
 */
interface SearchSource
{
    /**
     * @return list<SearchResult>
     */
    public function search(User $user, string $query, int $limit): array;
}
