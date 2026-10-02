<?php

namespace App\Http\Resources\Chat;

use App\Models\User;

/**
 * Personas del chat (autores, participantes, mencionadas, quien reacciona): se cargan todas de una
 * vez por ids (una consulta por página, sin N+1). Contrato: resources/js/types/chat.ts (ChatUser).
 * Las desactivadas siguen en el histórico, marcadas como inactivas (SPEC §12).
 */
final class ChatUsers
{
    /**
     * @param  iterable<int|null>  $ids
     * @return array<int, User>
     */
    public static function load(iterable $ids): array
    {
        $unique = [];

        foreach ($ids as $id) {
            if ($id !== null && $id > 0) {
                $unique[$id] = true;
            }
        }

        if ($unique === []) {
            return [];
        }

        return User::query()
            ->whereKey(array_keys($unique))
            ->get(['id', 'name', 'avatar_path', 'is_active'])
            ->keyBy('id')
            ->all();
    }

    /**
     * @return array{id: int, name: string, avatar: string|null, is_active: bool}
     */
    public static function present(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'avatar' => $user->avatar_url,
            'is_active' => $user->is_active,
        ];
    }

    /**
     * @param  array<int, User>  $users
     * @return list<array{id: int, name: string, avatar: string|null, is_active: bool}>
     */
    public static function list(array $users): array
    {
        return array_values(array_map(self::present(...), $users));
    }
}
