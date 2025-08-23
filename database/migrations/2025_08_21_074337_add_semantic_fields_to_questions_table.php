<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            // أعمدة للبحث الدلالي
            if (!Schema::hasColumn('questions', 'embedding')) {
                $table->json('embedding')->nullable()->after('answer'); // أو longText لو تفضّل
            }
            if (!Schema::hasColumn('questions', 'embedding_quality')) {
                $table->float('embedding_quality')->nullable()->after('embedding');
            }
            if (!Schema::hasColumn('questions', 'language')) {
                $table->string('language', 8)->nullable()->after('embedding_quality'); // مثل 'ar' أو 'en'
            }

            // (اختياري) فهارس مساعدة
            if (!Schema::hasColumn('questions', 'normalized_title')) {
                $table->text('normalized_title')->nullable()->after('title');
            }
            if (!Schema::hasColumn('questions', 'normalized_content')) {
                $table->text('normalized_content')->nullable()->after('content');
            }
        });
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            if (Schema::hasColumn('questions', 'embedding')) $table->dropColumn('embedding');
            if (Schema::hasColumn('questions', 'embedding_quality')) $table->dropColumn('embedding_quality');
            if (Schema::hasColumn('questions', 'language')) $table->dropColumn('language');
            if (Schema::hasColumn('questions', 'normalized_title')) $table->dropColumn('normalized_title');
            if (Schema::hasColumn('questions', 'normalized_content')) $table->dropColumn('normalized_content');
        });
    }
};
