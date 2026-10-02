<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Privacy\PersonalDataExporter;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * El admin pide la exportación de los datos personales de una persona desde su ficha
 * (/admin/usuarios/{user}, D-075) y la descarga desde allí (la lista llega en las props de la ficha).
 */
class UserPersonalDataExportController extends Controller
{
    public function store(Request $request, User $user, PersonalDataExporter $exporter): RedirectResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        abort_unless($admin->isAdmin(), 403);
        abort_if($user->isClient(), 404);

        $exporter->request($user, $admin);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('privacy.exports.requested_for', ['name' => $user->name])]);

        return back();
    }
}
