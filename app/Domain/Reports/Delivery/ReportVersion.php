<?php

namespace App\Domain\Reports\Delivery;

/**
 * Versión de un informe exportado (D-240): la interna y completa (por defecto, con todo lo que ve
 * quien lo genera) o la que se envía al cliente (D-241: solo las horas que vería en el portal, sin
 * costes, tarifas ni márgenes). Viaja en la query del ReportRequest (?version=interno|cliente), así
 * que la descarga, la impresión, Google Sheets, el envío por correo y los envíos programados la
 * llevan sin más: un envío programado sin ella es el interno (retrocompatible).
 */
enum ReportVersion: string
{
    case Internal = 'interno';
    case Client = 'cliente';

    /** Parámetro de la URL y de la query del ReportRequest. */
    public const string QUERY_KEY = 'version';

    /**
     * La versión de una query; sin ella o con un valor no válido, la interna.
     *
     * @param  array<string, mixed>  $query
     */
    public static function fromQuery(array $query): self
    {
        $value = $query[self::QUERY_KEY] ?? null;

        return is_string($value) ? (self::tryFrom($value) ?? self::Internal) : self::Internal;
    }

    /**
     * Informes con versión para el cliente. El resto ignora el parámetro.
     */
    public static function supports(ReportKind $kind): bool
    {
        return $kind === ReportKind::Project;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
