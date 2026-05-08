<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the Scolta config table.
 *
 * Generic key/value store for Scolta package configuration that must
 * be persisted across requests — currently used for Amazee.ai credentials.
 * The primary key is the config key string; values are stored as text
 * (callers encrypt sensitive values before writing).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scolta_config', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->longText('value');
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scolta_config');
    }
};
