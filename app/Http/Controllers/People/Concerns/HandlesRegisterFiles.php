<?php

namespace App\Http\Controllers\People\Concerns;

use App\Domain\People\Reports\RegisterFile;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lo común de las pantallas del registro de R2 (D-346 y D-351): leer el mes (?mes=AAAA-MM) o el
 * periodo (?desde=&hasta=) de la URL sin pasar de hoy, y descargar un fichero del registro con su
 * huella en la cabecera `X-Content-SHA256` (la misma que queda en `people_exports`).
 */
trait HandlesRegisterFiles
{
    /** Días como mucho de un periodo (un año): los periodos más largos se piden por partes. */
    protected int $maxPeriodDays = 366;

    /** ?mes=AAAA-MM válido y no futuro; si no, $default (por defecto, este mes). */
    protected function monthFrom(Request $request, ?CarbonImmutable $default = null, string $key = 'mes'): CarbonImmutable
    {
        $value = $request->query($key);
        $current = LocalTime::today()->startOfMonth();
        $default ??= $current;

        if (! is_string($value) || preg_match('/^\d{4}-\d{2}$/', $value) !== 1) {
            return $default;
        }

        $month = CarbonImmutable::createFromFormat('!Y-m', $value);

        if ($month === null || $month->format('Y-m') !== $value || $month->greaterThan($current)) {
            return $default;
        }

        return $month;
    }

    /**
     * ?desde=&hasta= (AAAA-MM-DD), recortado a hoy y a $maxPeriodDays; por defecto, este mes.
     *
     * @return array{0: string, 1: string}
     */
    protected function periodFrom(Request $request): array
    {
        $today = LocalTime::todayString();
        $from = self::dateOrNull($request->query('desde')) ?? LocalTime::today()->startOfMonth()->toDateString();
        $to = self::dateOrNull($request->query('hasta')) ?? $today;
        $to = min($to, $today);

        if ($from > $to) {
            $from = $to;
        }

        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) >= $this->maxPeriodDays) {
            $from = CarbonImmutable::parse($to)->subDays($this->maxPeriodDays - 1)->toDateString();
        }

        return [$from, $to];
    }

    protected static function dateOrNull(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== null && $date->toDateString() === $value ? $value : null;
    }

    /** Descarga el fichero (que se borra al enviarlo), con su huella en la cabecera. */
    protected function sendRegisterFile(RegisterFile $file): Response
    {
        $content = (string) file_get_contents($file->path);
        @unlink($file->path);

        return response($content, 200, [
            'Content-Type' => $file->mime,
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $file->filename, Str::ascii($file->filename)),
            'Content-Length' => (string) strlen($content),
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'X-Content-SHA256' => $file->sha256,
        ]);
    }

    protected function toast(string $message, string $type = 'success'): void
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);
    }
}
