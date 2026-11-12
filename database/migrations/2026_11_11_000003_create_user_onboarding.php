<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('tour_auto_start')->default(true);
            $table->boolean('onboarding_collapsed')->default(false);
        });
        Schema::create('user_tour_progress', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('tour_id', 80);
            $table->unsignedInteger('version');
            $table->string('status', 20)->default('not_started');
            $table->string('step_id', 100);
            $table->unsignedInteger('step_index')->default(0);
            $table->timestampTz('last_started_at')->nullable();
            $table->timestampTz('last_offered_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('next_eligible_at')->nullable();
            $table->timestampsTz();
            $table->unique(['user_id', 'tour_id', 'version']);
            $table->index(['user_id', 'last_offered_at']);
        });
        DB::statement("ALTER TABLE user_tour_progress ADD CONSTRAINT tour_progress_check CHECK (version BETWEEN 1 AND 1000000 AND step_index BETWEEN 0 AND 199 AND status IN ('not_started','in_progress','completed','skipped','snoozed') AND (status <> 'completed' OR completed_at IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('user_tour_progress');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['tour_auto_start', 'onboarding_collapsed']));
    }
};
