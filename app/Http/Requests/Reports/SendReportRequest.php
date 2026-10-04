<?php

namespace App\Http\Requests\Reports;

/**
 * Enviar un informe por correo ahora (POST /informes/enviar, D-141): lo común de
 * ReportDeliveryRequest, con el periodo tal cual se ve en la pantalla.
 */
class SendReportRequest extends ReportDeliveryRequest {}
