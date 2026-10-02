<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Privacy\PrivacyNotice;
use App\Domain\Privacy\PrivacySettings;
use App\Domain\Privacy\RetentionPolicy;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PrivacySettingsRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /admin/privacidad (D-075 y D-076), solo admin: el texto informativo con su versión (cada cambio
 * sube la versión, pide una nueva lectura a toda la plantilla y queda en la auditoría), los plazos
 * de retención, los días para descargar los datos personales y los umbrales de aviso.
 */
class PrivacySettingsController extends Controller
{
    public function edit(Request $request, PrivacyNotice $notice, PrivacySettings $settings): Response
    {
        abort_unless($request->user()?->isAdmin() === true, 403);

        $internal = User::query()->internal()->active();

        return Inertia::render('admin/privacy', [
            'notice' => [
                'markdown' => $notice->text(),
                'version' => $notice->version(),
                'is_draft' => $notice->isDraft(),
                'max_length' => PrivacyNotice::MAX_LENGTH,
            ],
            'readers' => [
                'read' => (clone $internal)->where('privacy_acknowledged_version', '>=', $notice->version())->count(),
                'total' => (clone $internal)->count(),
            ],
            'settings' => $settings->current(),
            'retention' => array_map(fn (string $type): array => [
                'type' => $type,
                'key' => RetentionPolicy::SETTINGS[$type],
                'min' => RetentionPolicy::MINIMUM_MONTHS[$type],
                'max' => RetentionPolicy::MAXIMUM_MONTHS,
                'unlimited_allowed' => in_array($type, RetentionPolicy::UNLIMITED_ALLOWED, true),
            ], array_keys(RetentionPolicy::SETTINGS)),
            'limits' => [
                'export_days' => ['min' => PrivacySettings::EXPORT_DAYS_MIN, 'max' => PrivacySettings::EXPORT_DAYS_MAX],
                'disk_percent' => ['min' => PrivacySettings::DISK_PERCENT_MIN, 'max' => PrivacySettings::DISK_PERCENT_MAX],
                'attachments_gb' => ['min' => PrivacySettings::ATTACHMENTS_GB_MIN, 'max' => PrivacySettings::ATTACHMENTS_GB_MAX],
            ],
        ]);
    }

    public function update(PrivacySettingsRequest $request, PrivacyNotice $notice, PrivacySettings $settings): RedirectResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $version = $notice->version();

        DB::transaction(function () use ($request, $notice, $settings, $admin): void {
            $notice->update($request->notice(), $admin);
            $settings->update($request->settings(), $admin);
        });

        $message = $notice->version() > $version
            ? __('privacy.admin.saved_new_version', ['version' => $notice->version()])
            : __('privacy.admin.saved');

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
