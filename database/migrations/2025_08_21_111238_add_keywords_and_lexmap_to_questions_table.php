<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            // قائمة كلمات مفتاحية (JSON array of strings)
            if (!Schema::hasColumn('questions', 'keywords')) {
                $table->json('keywords')->nullable()->after('intent');
            }
            // قاموس استبدال مرادفات -> هدف موحّد (JSON object)
            if (!Schema::hasColumn('questions', 'lex_map')) {
                $table->json('lex_map')->nullable()->after('keywords');
            }
        });
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            if (Schema::hasColumn('questions', 'keywords')) $table->dropColumn('keywords');
            if (Schema::hasColumn('questions', 'lex_map'))  $table->dropColumn('lex_map');
        });
    }
};
