<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waterline_user_preferences', static function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('scope', 120)->default('default');
            $table->string('subject_key', 191);
            $table->string('surface', 80);
            $table->json('preferences')->nullable();
            $table->timestamps();

            $table->unique(['scope', 'subject_key', 'surface'], 'waterline_preferences_scope_subject_surface_unique');
            $table->index(['scope', 'surface'], 'waterline_preferences_scope_surface_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waterline_user_preferences');
    }
};
