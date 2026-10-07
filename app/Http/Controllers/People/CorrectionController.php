<?php

namespace App\Http\Controllers\People;

use App\Domain\People\ClockCorrectionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\People\CorrectionRequest;
use App\Models\ClockCorrection;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Correcciones del registro con doble conformidad (D-335): proponer, aceptar (una o varias),
 * rechazar con motivo y retirar. Quién puede qué lo decide PeopleAccess dentro del servicio.
 */
class CorrectionController extends Controller
{
    public function __construct(private readonly ClockCorrectionService $corrections) {}

    public function store(CorrectionRequest $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $subject = User::query()->findOrFail($request->integer('user_id'));

        $this->corrections->propose($actor, $subject, $request->string('date')->toString(), $request->rows(), $request->string('reason')->toString());
        $this->toast(__('people.flash.proposed'));

        return back();
    }

    public function accept(Request $request, ClockCorrection $correction): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;

        $this->corrections->accept($actor, $correction, $note);
        $this->toast(__('people.flash.accepted'));

        return back();
    }

    public function acceptMany(Request $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $ids = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'distinct'],
        ])['ids'];

        $accepted = 0;
        $failed = [];

        foreach (ClockCorrection::query()->whereKey($ids)->orderBy('date')->get() as $correction) {
            try {
                $this->corrections->accept($actor, $correction);
                $accepted++;
            } catch (ValidationException $exception) {
                $failed[] = $correction->date->format('d/m/Y').': '.collect($exception->errors())->flatten()->first();
            }
        }

        if ($accepted > 0) {
            $this->toast(trans_choice('people.flash.accepted_many', $accepted, ['count' => $accepted]));
        }

        if ($failed !== []) {
            throw ValidationException::withMessages(['ids' => implode(' · ', $failed)]);
        }

        return back();
    }

    public function reject(Request $request, ClockCorrection $correction): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $note = $request->validate(['note' => ['required', 'string', 'min:3', 'max:500']], ['note.required' => __('people.errors.note_required')])['note'];

        $this->corrections->reject($actor, $correction, $note);
        $this->toast(__('people.flash.rejected'));

        return back();
    }

    public function withdraw(Request $request, ClockCorrection $correction): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $this->corrections->withdraw($actor, $correction);
        $this->toast(__('people.flash.withdrawn'));

        return back();
    }

    private function toast(string $message): void
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);
    }
}
