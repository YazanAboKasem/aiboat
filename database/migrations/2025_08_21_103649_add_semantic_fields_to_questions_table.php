<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            // نية السؤال (location, time, price, ...)
            if (!Schema::hasColumn('questions', 'intent')) {
                $table->string('intent', 32)->nullable()->index()->after('answer');
            }

            // Embedding خاص بالعنوان
            if (!Schema::hasColumn('questions', 'title_embedding')) {
                $table->json('title_embedding')->nullable()->after('intent');
            }

            // Embedding خاص بالمحتوى
            if (!Schema::hasColumn('questions', 'content_embedding')) {
                $table->json('content_embedding')->nullable()->after('title_embedding');
            }

            // مؤشر جودة للتجربة
            if (!Schema::hasColumn('questions', 'embedding_quality')) {
                $table->float('embedding_quality')->nullable()->after('content_embedding');
            }
        });
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            if (Schema::hasColumn('questions', 'intent')) $table->dropColumn('intent');
            if (Schema::hasColumn('questions', 'title_embedding')) $table->dropColumn('title_embedding');
            if (Schema::hasColumn('questions', 'content_embedding')) $table->dropColumn('content_embedding');
            if (Schema::hasColumn('questions', 'embedding_quality')) $table->dropColumn('embedding_quality');
        });
    }
};
