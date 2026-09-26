<?php

namespace App\Http\Controllers\Tasks;

use Illuminate\Http\RedirectResponse;

/**
 * Vuelve a la página anterior (con sus filtros) abriendo o cerrando el panel de una tarea
 * (?tarea={id}). Así las acciones del panel no pierden la vista, los filtros ni el scroll.
 */
trait RedirectsToTaskPanel
{
    protected function backWithPanel(?int $taskId, ?string $fallback = null): RedirectResponse
    {
        $previous = url()->previous($fallback ?? '/');

        return redirect()->to($this->withTaskParam($previous, $taskId));
    }

    protected function withTaskParam(string $url, ?int $taskId): string
    {
        $parts = parse_url($url);

        if ($parts === false) {
            return $url;
        }

        parse_str($parts['query'] ?? '', $query);

        if ($taskId === null) {
            unset($query['tarea']);
        } else {
            $query['tarea'] = (string) $taskId;
        }

        $path = $parts['path'] ?? '/';
        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return $path.($queryString === '' ? '' : '?'.$queryString);
    }
}
