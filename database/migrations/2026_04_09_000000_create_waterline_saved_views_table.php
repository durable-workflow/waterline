<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waterline_saved_views', static function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('name', 120);
            $table->string('scope', 120)->default('default');
            $table->string('bucket', 32);
            $table->json('filters')->nullable();
            $table->unsignedSmallInteger('filter_version')->default(6);
            $table->boolean('shared')->default(false);
            $table->string('owner_type')->nullable();
            $table->string('owner_id')->nullable();
            $table->timestamps();

            $table->unique(['scope', 'bucket', 'name']);
            $table->index(['scope', 'bucket']);
            $table->index(['scope', 'bucket', 'shared'], 'waterline_saved_views_scope_bucket_shared_index');
            $table->index(['scope', 'bucket', 'owner_type', 'owner_id'], 'waterline_saved_views_scope_bucket_owner_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waterline_saved_views');
    }
};
