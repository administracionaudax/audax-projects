<?php

namespace App\Enums;

/**
 * «Pedir cancelación» de una ausencia ya aprobada (Fase 11, R3; W-069; D-365): la persona la pide
 * con un motivo y quien aprueba sus ausencias la acepta (la ausencia queda cancelada y devuelve el
 * saldo) o la rechaza con un comentario (sigue aprobada).
 */
enum CancellationStatus: string
{
    case Requested = 'requested';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
}
