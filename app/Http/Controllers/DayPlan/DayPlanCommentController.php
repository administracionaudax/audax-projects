<?php

namespace App\Http\Controllers\DayPlan;

use App\Models\DayPlanComment;
use App\Models\DayPlanItem;
use App\Models\User;
use App\Notifications\DayPlan\DayPlanCommented;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Comentarios en una línea del plan del día (docs/PLAN-CARGAS.md §4.2, D-255): los deja el
 * responsable de la persona o un admin, y la persona puede contestar; los ven solo ellos (son
 * «cifras», D-251). Al comentar otra persona, se avisa a la dueña de la línea (day_plan.commented).
 * Cada uno borra los suyos. Nadie edita la línea de otro.
 */
class DayPlanCommentController extends DayPlanController
{
    /** POST /dia/lineas/{item}/comentarios {body} */
    public function store(Request $request, DayPlanItem $item): RedirectResponse
    {
        $this->authorize('comment', $item);
        $request->validate(['body' => ['required', 'string', 'max:'.DayPlanComment::BODY_MAX]]);

        /** @var User $user */
        $user = $request->user();
        $comment = DayPlanComment::query()->create([
            'day_plan_item_id' => $item->id,
            'user_id' => $user->id,
            'body' => trim($request->string('body')->toString()),
        ]);

        if ($item->user_id !== $user->id && $item->user->isActive()) {
            $item->user->notify(new DayPlanCommented($user->name, $item->text, $comment->body, $item->date->toDateString()));
        }

        $this->toast(__('day_plan.flash.commented'));

        return back();
    }

    /** DELETE /dia/comentarios/{comment} */
    public function destroy(Request $request, DayPlanComment $comment): RedirectResponse
    {
        abort_unless($comment->user_id === $request->user()?->id, 403);

        $comment->delete();
        $this->toast(__('day_plan.flash.comment_deleted'), 'info');

        return back();
    }
}
