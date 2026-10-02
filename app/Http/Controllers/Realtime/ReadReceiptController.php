<?php

namespace App\Http\Controllers\Realtime;

use App\Broadcasting\ReadReceipts;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * GET /tiempo-real/conversaciones/{id}/leidos: hasta dónde ha leído cada participante («leído
 * por», SPEC §12). Mismo permiso que ver la conversación (D-071): un admin no ve las directas.
 */
class ReadReceiptController extends Controller
{
    public function __invoke(Conversation $conversation, ReadReceipts $receipts): JsonResponse
    {
        Gate::authorize('view', $conversation);

        return response()->json(['participants' => $receipts->for($conversation)]);
    }
}
