<?php

namespace App\Domain\Weeklies\Audio;

/**
 * Duración de un MP3 (MPEG-1/2/2.5, capa III) contando sus tramas, sin dependencias: la usa el
 * reproductor por secciones para colocar las marcas de cada cliente (F-085), como hacía WeeklySync
 * con getAudioDurationFromBase64 en el navegador. Salta la etiqueta ID3v2 del principio. Devuelve
 * null si no encuentra ninguna trama (p. ej. el audio del doble de los tests).
 */
final class Mp3Duration
{
    /** kbps por versión (1 = MPEG-1, 2 = MPEG-2 y 2.5) e índice, capa III. */
    private const array BITRATES = [
        1 => [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, 0],
        2 => [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160, 0],
    ];

    /** Hz por versión (bits del encabezado: 3 = MPEG-1, 2 = MPEG-2 y 0 = MPEG-2.5) e índice. */
    private const array SAMPLE_RATES = [
        3 => [44100, 48000, 32000],
        2 => [22050, 24000, 16000],
        0 => [11025, 12000, 8000],
    ];

    public static function milliseconds(string $bytes): ?int
    {
        $length = strlen($bytes);
        $offset = self::id3Size($bytes);
        $seconds = 0.0;
        $frames = 0;

        while ($offset + 4 <= $length) {
            $b1 = ord($bytes[$offset + 1]);

            if (ord($bytes[$offset]) !== 0xFF || ($b1 & 0xE0) !== 0xE0) {
                $offset++;

                continue;
            }

            $version = ($b1 >> 3) & 0x03;
            $layer = ($b1 >> 1) & 0x03;
            $b2 = ord($bytes[$offset + 2]);
            $bitrateIndex = ($b2 >> 4) & 0x0F;
            $rateIndex = ($b2 >> 2) & 0x03;
            $padding = ($b2 >> 1) & 0x01;

            if ($version === 1 || $layer !== 1 || $rateIndex === 3 || $bitrateIndex === 0 || $bitrateIndex === 15) {
                $offset++;

                continue;
            }

            $mpeg1 = $version === 3;
            $bitrate = self::BITRATES[$mpeg1 ? 1 : 2][$bitrateIndex] * 1000;
            $sampleRate = self::SAMPLE_RATES[$version][$rateIndex];
            $samples = $mpeg1 ? 1152 : 576;
            $frameLength = intdiv(($mpeg1 ? 144 : 72) * $bitrate, $sampleRate) + $padding;

            if ($frameLength < 4) {
                $offset++;

                continue;
            }

            $seconds += $samples / $sampleRate;
            $frames++;
            $offset += $frameLength;
        }

        return $frames === 0 ? null : (int) round($seconds * 1000);
    }

    private static function id3Size(string $bytes): int
    {
        if (strlen($bytes) < 10 || substr($bytes, 0, 3) !== 'ID3') {
            return 0;
        }

        $size = ((ord($bytes[6]) & 0x7F) << 21) | ((ord($bytes[7]) & 0x7F) << 14) | ((ord($bytes[8]) & 0x7F) << 7) | (ord($bytes[9]) & 0x7F);

        return 10 + $size + ((ord($bytes[5]) & 0x10) !== 0 ? 10 : 0);
    }
}
