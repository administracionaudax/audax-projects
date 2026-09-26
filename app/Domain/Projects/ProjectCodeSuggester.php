<?php

namespace App\Domain\Projects;

use App\Models\Project;
use Illuminate\Support\Str;

/**
 * Código corto y único del proyecto (SPEC §4.2: «ACME-WEB»). Se sugiere con la primera palabra
 * significativa del cliente y del nombre, sin acentos y en mayúsculas. Gemelo de
 * resources/js/components/projects-list/project-code.ts (mismos casos en los tests).
 */
final class ProjectCodeSuggester
{
    /** Longitud máxima de la columna projects.code. */
    public const int MAX_LENGTH = 20;

    /** Longitud máxima de cada parte (cliente y nombre). */
    private const int PART_LENGTH = 8;

    /** Formato admitido: letras sin acentos, números y guiones o guiones bajos intermedios. */
    public const string PATTERN = '/^[A-Z0-9](?:[A-Z0-9_-]*[A-Z0-9])?$/';

    /** Palabras que no dan nombre (artículos, preposiciones…). */
    public const array STOPWORDS = [
        'A', 'AL', 'CON', 'DE', 'DEL', 'E', 'EL', 'EN', 'LA', 'LAS', 'LOS', 'O', 'PARA', 'POR', 'U',
        'UN', 'UNA', 'Y', 'THE', 'OF', 'AND',
    ];

    /**
     * Normaliza lo que escribe el usuario: mayúsculas, sin acentos y espacios como guiones.
     */
    public static function normalize(string $code): string
    {
        $ascii = Str::upper(Str::ascii(trim($code)));

        return (string) preg_replace('/\s+/', '-', $ascii);
    }

    public function suggest(?string $clientName, string $projectName): string
    {
        $parts = array_values(array_filter([
            $this->keyword($clientName),
            $this->keyword($projectName),
        ]));

        if (count($parts) === 2 && $parts[0] === $parts[1]) {
            $parts = [$parts[0]];
        }

        $code = implode('-', $parts);

        return $code === '' ? 'PROYECTO' : $code;
    }

    /**
     * La sugerencia, con un sufijo (-2, -3…) si ya existe (también entre los proyectos borrados:
     * el índice único los incluye).
     */
    public function unique(?string $clientName, string $projectName): string
    {
        return $this->nextFree($this->suggest($clientName, $projectName));
    }

    /**
     * El propio código si está libre; si no, el primero libre con sufijo.
     */
    public function nextFree(string $code, ?int $ignoreProjectId = null): string
    {
        // Sitio para un sufijo de hasta 3 cifras (-999) sin pasar de 20 caracteres.
        $base = rtrim(Str::limit($code, self::MAX_LENGTH - 4, ''), '-_');
        $candidate = rtrim(Str::limit($code, self::MAX_LENGTH, ''), '-_');
        $suffix = 2;

        while ($this->taken($candidate, $ignoreProjectId)) {
            $candidate = $base.'-'.$suffix++;
        }

        return $candidate;
    }

    public function taken(string $code, ?int $ignoreProjectId = null): bool
    {
        return Project::query()
            ->withTrashed()
            ->where('code', $code)
            ->when($ignoreProjectId !== null, fn ($query) => $query->whereKeyNot($ignoreProjectId))
            ->exists();
    }

    private function keyword(?string $text): string
    {
        $words = preg_split('/[^A-Z0-9]+/', Str::upper(Str::ascii((string) $text)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($words as $word) {
            if (! in_array($word, self::STOPWORDS, true)) {
                return Str::limit($word, self::PART_LENGTH, '');
            }
        }

        return '';
    }
}
