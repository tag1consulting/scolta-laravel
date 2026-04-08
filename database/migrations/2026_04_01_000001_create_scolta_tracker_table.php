<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the Scolta change tracker table.
 *
 * Laravel's migration system is elegant — versioned, reversible,
 * database-agnostic schema definitions. This table mirrors the
 * WordPress wp_scolta_tracker table, adapted for Eloquent conventions.
 *
 * The tracker records content changes (create, update, delete) detected
 * by model observers. The build command consumes these records to
 * perform incremental index updates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scolta_tracker', function (Blueprint $table) {
            // Use the model's primary key as the content identifier.
            // String type handles both integer IDs and UUIDs — Laravel
            // supports both, and good packages don't assume either.
            $table->id();
            $table->string('content_id', 64);
            $table->string('content_type', 128);
            $table->enum('action', ['index', 'delete'])->default('index');
            $table->timestamp('changed_at')->useCurrent();

            // Unique on content_id + content_type: one tracker entry per
            // content item. New changes overwrite the previous entry.
            $table->unique(['content_id', 'content_type'], 'scolta_tracker_content_unique');

            // Index for efficient queries by action type.
            $table->index('action', 'scolta_tracker_action_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scolta_tracker');
    }
};
