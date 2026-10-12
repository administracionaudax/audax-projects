<?php

namespace App\Domain\Billing\Holded;

use App\Enums\HoldedDocumentKind;

/**
 * La API v2 de Holded que usa Audax (Fase 12, F1; D-384): SOLO lectura. Cada listado recorre todas
 * las páginas (cursor) y devuelve los objetos tal cual llegan; HoldedPayload los interpreta.
 *
 * Implementaciones: HttpHoldedClient (la real, con la clave de config('services.holded.key')) y
 * FakeHolded (tests y local, HOLDED_DRIVER=fake).
 */
interface HoldedApi
{
    /**
     * @return iterable<int, array<string, mixed>>
     */
    public function contacts(): iterable;

    /**
     * @return iterable<int, array<string, mixed>>
     */
    public function projects(): iterable;

    /**
     * Facturas de venta (H-142).
     *
     * @return iterable<int, array<string, mixed>>
     */
    public function invoices(): iterable;

    /**
     * Facturas rectificativas (H-043 a H-046).
     *
     * @return iterable<int, array<string, mixed>>
     */
    public function creditNotes(): iterable;

    /**
     * Ficha completa de una rectificativa: el listado no dice qué factura rectifica; la ficha sí
     * (`from`: {id, doc_type}).
     *
     * @return array<string, mixed>
     */
    public function creditNote(string $holdedId): array;

    /**
     * Cobros (H-076).
     *
     * @return iterable<int, array<string, mixed>>
     */
    public function payments(): iterable;

    /**
     * Facturas recurrentes (H-057), en solo lectura: E3 las importa en pausa en Audax
     * (app:import-holded-recurring). Nunca se cambian en Holded.
     *
     * @return iterable<int, array<string, mixed>>
     */
    public function recurringInvoices(): iterable;

    /**
     * El PDF original de un documento (empieza por «%PDF»).
     *
     * @throws HoldedRequestFailed
     */
    public function pdf(string $holdedId, HoldedDocumentKind $kind): string;

    /** Peticiones hechas hasta ahora (para el registro de la sincronización). */
    public function requestCount(): int;
}
