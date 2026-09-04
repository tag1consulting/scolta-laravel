<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record the index item id alongside the Eloquent key on tracker rows.
 *
 * `content_id` is an Eloquent primary key; everything downstream of the tracker
 * is keyed by ContentItem::$id, and only a live model instance maps between the
 * two. A pending delete is exactly the case where no live instance is left, so
 * the observer stores the item id here when it records the deletion.
 *
 * Nullable: rows written before this migration, and models whose
 * toSearchableContent() throws mid-delete, have no answer to give. ContentSource
 * falls back to reloading the record, and a row it still cannot resolve escalates
 * the run to a full one rather than leaving a page behind — the meaning
 * scolta-drupal gives to a queue payload with no item ids ("correct but slow —
 * never wrong").
 *
 * Singular where scolta-drupal's queue payload carries `item_ids`, because the
 * cardinality genuinely differs: toSearchableContent() returns one ContentItem
 * per record, where a Drupal node yields one page per translation. Same column
 * either way — the id the index is keyed by, captured while the record can still
 * be asked for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('scolta_tracker') || Schema::hasColumn('scolta_tracker', 'item_id')) {
            return;
        }

        Schema::table('scolta_tracker', function (Blueprint $table) {
            // Not part of the (content_id, content_type) unique key: the item id
            // is a property of the row, not part of its identity.
            $table->string('item_id', 191)->nullable()->after('content_type');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('scolta_tracker') || ! Schema::hasColumn('scolta_tracker', 'item_id')) {
            return;
        }

        Schema::table('scolta_tracker', function (Blueprint $table) {
            $table->dropColumn('item_id');
        });
    }
};
