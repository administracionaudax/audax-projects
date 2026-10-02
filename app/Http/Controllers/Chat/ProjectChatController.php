<?php

namespace App\Http\Controllers\Chat;

use App\Domain\Chat\ConversationDirectory;
use App\Http\Controllers\Controller;
use App\Http\Resources\Chat\ConversationView;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\Tasks\Plain;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pestaña Chat del proyecto (SPEC §6 y §12): la conversación del proyecto (se crea con sus
 * miembros la primera vez). La ven sus miembros y el admin, que la modera sin escribir (D-071);
 * el resto de internos ve la ficha del proyecto (D-021) pero no su chat: se le explica por qué.
 * En un proyecto archivado el chat es de solo lectura.
 */
class ProjectChatController extends Controller
{
    use RespondsWithMessages;

    public function __invoke(Request $request, Project $project, ConversationDirectory $directory, ConversationView $view): Response
    {
        Gate::authorize('view', $project);

        /** @var User $user */
        $user = $request->user();
        $project->loadMissing(['client', 'owner']);
        $conversation = $directory->forProject($project);
        $canView = Gate::allows('view', $conversation);

        return Inertia::render('chat/project', [
            'project' => Plain::of(ProjectResource::make($project)),
            'canManage' => $user->canManageProject($project),
            ...($canView
                ? $view->props($conversation, $user, $this->positiveInt($request->query('mensaje')))
                : ['conversation' => null, 'messages' => null, 'pinned' => [], 'focus' => null]),
        ]);
    }
}
