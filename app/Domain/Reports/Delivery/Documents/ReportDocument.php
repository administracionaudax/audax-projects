<?php

namespace App\Domain\Reports\Delivery\Documents;

use App\Domain\Reports\Delivery\ReportRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Un informe exportable (ReportKind, D-139): su título, la tabla de Excel y CSV (la de ?tabla=, o
 * la principal) y el documento del PDF y de la impresión (D-140). Cada método comprueba antes los
 * permisos de $as con las mismas políticas que la página, así que nadie saca por aquí lo que no
 * vería en pantalla.
 */
interface ReportDocument
{
    /**
     * @throws AuthorizationException
     */
    public function title(ReportRequest $request, User $as): string;

    /**
     * @throws AuthorizationException
     */
    public function table(ReportRequest $request, User $as): ExportTable;

    /**
     * @throws AuthorizationException
     */
    public function pdf(ReportRequest $request, User $as): ReportPdf;
}
