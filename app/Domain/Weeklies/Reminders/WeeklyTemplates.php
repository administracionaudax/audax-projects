<?php

namespace App\Domain\Weeklies\Reminders;

use App\Enums\WeeklyReminderTemplate;
use App\Models\Setting;
use App\Models\User;

/**
 * Plantillas editables de los avisos de la weekly (F-104 y F-105): automatic, manual y
 * weekly_closed, con asunto y cuerpo. Las de por defecto están en lang/es/weeklies.php
 * (templates); el ajuste weekly_email_templates guarda SOLO las cambiadas, así que «Restaurar por
 * defecto» es guardar la de por defecto y un texto nuevo por defecto llega a quien no la ha tocado.
 *
 * Variables, como en send-email-reminder de WeeklySync: {nombre}, {semana} (el número, «W41-26»),
 * {week_label} (la etiqueta, «Semana 41 (Lun 05/10 - Vie 09/10)») y {weekly_url} (el enlace a «Mi
 * weekly» en los recordatorios y al informe en «weekly cerrada»). Una variable desconocida se deja
 * tal cual. El gemelo de render() en TypeScript es renderWeeklyTemplate (lib/weekly-templates.ts);
 * los dos pasan tests/fixtures/weeklies/template-render.json.
 */
final class WeeklyTemplates
{
    public const string SETTING = 'weekly_email_templates';

    /** @var list<string> */
    public const array VARIABLES = ['nombre', 'semana', 'week_label', 'weekly_url'];

    public const int SUBJECT_MAX = 200;

    public const int BODY_MAX = 5000;

    /**
     * @return array<string, array{subject: string, body: string}>
     */
    public function defaults(): array
    {
        $defaults = [];

        foreach (WeeklyReminderTemplate::EDITABLE as $key) {
            $defaults[$key] = [
                'subject' => self::text("weeklies.templates.{$key}.subject"),
                'body' => self::text("weeklies.templates.{$key}.body"),
            ];
        }

        return $defaults;
    }

    /**
     * Las tres plantillas en uso, con si son las de por defecto.
     *
     * @return array<string, array{subject: string, body: string, is_default: bool}>
     */
    public function all(): array
    {
        $stored = $this->stored();
        $all = [];

        foreach ($this->defaults() as $key => $default) {
            $template = $stored[$key] ?? $default;
            $all[$key] = [...$template, 'is_default' => $template === $default];
        }

        return $all;
    }

    /**
     * @return array{subject: string, body: string}
     */
    public function get(WeeklyReminderTemplate $template): array
    {
        $all = $this->all();

        if (! isset($all[$template->value])) {
            throw new \InvalidArgumentException("La plantilla {$template->value} no se edita.");
        }

        return ['subject' => $all[$template->value]['subject'], 'body' => $all[$template->value]['body']];
    }

    /**
     * Guarda las plantillas que llegan (las que faltan se conservan) y deja en el ajuste solo las
     * que difieren de las de por defecto. Devuelve las claves que han cambiado.
     *
     * @param  array<string, array{subject: string, body: string}>  $templates
     * @return list<string>
     */
    public function save(array $templates, User $by): array
    {
        $defaults = $this->defaults();
        $stored = $this->stored();
        $before = $this->all();
        $changed = [];

        foreach ($templates as $key => $template) {
            if (! isset($defaults[$key])) {
                continue;
            }

            $value = ['subject' => self::normalize($template['subject']), 'body' => self::normalize($template['body'])];

            if ($value === $defaults[$key]) {
                unset($stored[$key]);
            } else {
                $stored[$key] = $value;
            }

            if ($value['subject'] !== $before[$key]['subject'] || $value['body'] !== $before[$key]['body']) {
                $changed[] = $key;
            }
        }

        if ($changed === []) {
            return [];
        }

        Setting::set(self::SETTING, $stored === [] ? null : $stored);

        activity('weekly-reminders')
            ->causedBy($by)
            ->event('templates_updated')
            ->withProperties(['templates' => $changed])
            ->log('templates_updated');

        return $changed;
    }

    /**
     * Sustituye {variable} por su valor; las desconocidas se quedan como están.
     *
     * @param  array<string, string>  $values
     */
    public static function render(string $text, array $values): string
    {
        return (string) preg_replace_callback(
            '/\{([a-z_]+)\}/',
            fn (array $match): string => array_key_exists($match[1], $values) ? $values[$match[1]] : $match[0],
            $text,
        );
    }

    /** Saltos de línea de Unix y sin espacios al final, como se guarda. */
    public static function normalize(string $text): string
    {
        return rtrim(str_replace(["\r\n", "\r"], "\n", $text));
    }

    /**
     * Lo guardado, saneado: solo las editables con asunto y cuerpo de texto.
     *
     * @return array<string, array{subject: string, body: string}>
     */
    private function stored(): array
    {
        $raw = Setting::get(self::SETTING);
        $stored = [];

        if (! is_array($raw)) {
            return [];
        }

        foreach (WeeklyReminderTemplate::EDITABLE as $key) {
            $template = $raw[$key] ?? null;

            if (is_array($template) && is_string($template['subject'] ?? null) && is_string($template['body'] ?? null)) {
                $stored[$key] = ['subject' => $template['subject'], 'body' => $template['body']];
            }
        }

        return $stored;
    }

    private static function text(string $key): string
    {
        $text = __($key);

        return is_string($text) ? $text : $key;
    }
}
