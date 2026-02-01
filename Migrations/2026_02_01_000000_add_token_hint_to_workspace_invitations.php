<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add token_hint column for indexed token lookup.
     *
     * Stores first 8 characters of plaintext token to enable
     * fast filtering before bcrypt verification.
     */
    public function up(): void
    {
        Schema::table('workspace_invitations', function (Blueprint $table) {
            $table->string('token_hint', 8)->nullable()->after('token');
            $table->index('token_hint');
        });
    }

    public function down(): void
    {
        Schema::table('workspace_invitations', function (Blueprint $table) {
            $table->dropIndex(['token_hint']);
            $table->dropColumn('token_hint');
        });
    }
};
