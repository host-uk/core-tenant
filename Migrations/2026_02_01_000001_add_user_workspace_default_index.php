<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add composite index for default workspace lookup.
     *
     * The User::defaultHostWorkspace() method queries by user_id and is_default
     * on every authenticated request needing workspace context. This index
     * optimises that common query pattern.
     */
    public function up(): void
    {
        Schema::table('user_workspace', function (Blueprint $table) {
            $table->index(['user_id', 'is_default'], 'user_workspace_user_default_idx');
        });
    }

    public function down(): void
    {
        Schema::table('user_workspace', function (Blueprint $table) {
            $table->dropIndex('user_workspace_user_default_idx');
        });
    }
};
