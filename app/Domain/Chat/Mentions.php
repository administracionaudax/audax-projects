<?php

namespace App\Domain\Chat;

/**
 * Menciones en el cuerpo de un mensaje (D-069): el editor inserta «<@ID>» por cada persona y
 * «@todos» menciona a todos los participantes. Al pintar, <@ID> se muestra como @Nombre.
 */
final class Mentions
{
    /**
     * @return array{users: list<int>, everyone: bool}
     */
    public static function parse(string $body): array
    {
        preg_match_all('/<@(\d{1,10})>/', $body, $matches);
        $users = array_values(array_unique(array_map('intval', $matches[1])));

        return [
            'users' => $users,
            'everyone' => preg_match('/(^|[\s(])@todos\b/iu', $body) === 1,
        ];
    }
}
