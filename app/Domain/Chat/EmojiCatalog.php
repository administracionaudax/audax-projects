<?php

namespace App\Domain\Chat;

use Illuminate\Support\Facades\Cache;

/**
 * Emojis que se pueden usar como reacción (SPEC §12): exactamente los del selector (Emojibase 16,
 * autoalojado en public/emojibase/es/data.json), con sus tonos de piel. Así se admiten los que
 * llevan cifras o letras (0️⃣, ℹ️, 🅰️) sin abrir la puerta a texto arbitrario (D-117).
 * La lista se guarda en caché por la fecha del fichero: cambiar los datos la renueva sola.
 */
final class EmojiCatalog
{
    /** @var array<string, true>|null */
    private static ?array $emojis = null;

    public static function contains(string $emoji): bool
    {
        return isset(self::all()[$emoji]);
    }

    /**
     * @return array<string, true>
     */
    private static function all(): array
    {
        if (self::$emojis !== null) {
            return self::$emojis;
        }

        $path = public_path('emojibase/es/data.json');
        $version = is_file($path) ? (string) filemtime($path) : 'missing';

        /** @var array<string, true> $emojis */
        $emojis = Cache::rememberForever('chat.emoji-catalog.'.$version, fn (): array => self::load($path));

        return self::$emojis = $emojis;
    }

    /**
     * @return array<string, true>
     */
    private static function load(string $path): array
    {
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $emojis = [];

        foreach (is_array($data) ? $data : [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if (is_string($entry['emoji'] ?? null)) {
                $emojis[$entry['emoji']] = true;
            }

            foreach (is_array($entry['skins'] ?? null) ? $entry['skins'] : [] as $skin) {
                if (is_array($skin) && is_string($skin['emoji'] ?? null)) {
                    $emojis[$skin['emoji']] = true;
                }
            }
        }

        return $emojis;
    }
}
