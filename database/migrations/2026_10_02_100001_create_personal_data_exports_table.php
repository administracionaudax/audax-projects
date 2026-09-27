<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Exportaciones de los datos personales de una persona (SPEC §15, D-075): un ZIP generado en
     * cola, en el disco privado, que se descarga con URL firmada y caduca a los pocos días.
     */
    public function up(): void
    {
        Schema::create('personal_data_exports', function (Blueprint $table) {
            $table->id();
            // De quién son los datos. Los usuarios nunca se borran (SPEC §14): restrict.
            $table->foreignId('subject_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('pending');
            $table->string('disk', 32)->default('local');
            $table->string('path')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamps();

            $table->index(['subject_user_id', 'created_at']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_data_exports');
    }
};
