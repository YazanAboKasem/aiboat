<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            if (!Schema::hasColumn('questions', 'embedding')) {
                $table->json('embedding')->nullable()->after('question');
            }
            if (!Schema::hasColumn('questions', 'embedding_quality')) {
                $table->float('embedding_quality')->nullable()->after('embedding');
            }
        });
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            if (Schema::hasColumn('questions', 'embedding')) {
                $table->dropColumn('embedding');
            }
            if (Schema::hasColumn('questions', 'embedding_quality')) {
                $table->dropColumn('embedding_quality');
            }
        });
    }
};
