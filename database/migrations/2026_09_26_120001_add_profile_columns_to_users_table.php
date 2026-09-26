<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Organización, economía, estado y preferencias del usuario (SPEC §4.1).
     * client_id llega en la Fase 1 con la tabla clients.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('password')->constrained('departments')->nullOnDelete();
            $table->decimal('hourly_cost', 10, 2)->nullable()->after('department_id');
            $table->decimal('default_hourly_rate', 10, 2)->nullable()->after('hourly_cost');
            $table->boolean('is_active')->default(true)->after('default_hourly_rate');
            $table->string('theme_preference', 10)->default('system')->after('is_active');
            $table->string('locale', 5)->default('es')->after('theme_preference');
            $table->json('notification_preferences')->nullable()->after('locale');
            $table->string('avatar_path')->nullable()->after('notification_preferences');

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_active']);
            $table->dropConstrainedForeignId('department_id');
            $table->dropColumn([
                'hourly_cost',
                'default_hourly_rate',
                'is_active',
                'theme_preference',
                'locale',
                'notification_preferences',
                'avatar_path',
            ]);
        });
    }
};
