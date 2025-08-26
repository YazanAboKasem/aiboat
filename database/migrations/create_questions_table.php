<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * تشغيل عملية الهجرة.
     */
    public function up(): void
    {
        // إذا لم يكن الجدول موجوداً
        if (!Schema::hasTable('questions')) {
            Schema::create('questions', function (Blueprint $table) {
                $table->id();
                $table->string('title');
                $table->text('content')->nullable();
                $table->text('answer')->nullable();
                $table->string('intent')->nullable();
                $table->json('title_embedding')->nullable();
                $table->json('content_embedding')->nullable();
                $table->float('embedding_quality')->default(0);
                $table->json('keywords')->nullable();
                $table->json('lex_map')->nullable();
                $table->timestamps();
            });
        }
        // إذا كان الجدول موجوداً ولكن ينقصه بعض الأعمدة
        else {
            Schema::table('questions', function (Blueprint $table) {
                if (!Schema::hasColumn('questions', 'keywords')) {
                    $table->json('keywords')->nullable();
                }
                if (!Schema::hasColumn('questions', 'lex_map')) {
                    $table->json('lex_map')->nullable();
                }
                if (!Schema::hasColumn('questions', 'intent')) {
                    $table->string('intent')->nullable();
                }
            });
        }
    }

    /**
     * التراجع عن عملية الهجرة.
     */
    public function down(): void
    {
        // لا تقم بإزالة الجدول إذا كان موجوداً مسبقاً
        if (Schema::hasColumns('questions', ['keywords', 'lex_map', 'intent'])) {
            Schema::table('questions', function (Blueprint $table) {
                $table->dropColumn(['keywords', 'lex_map', 'intent']);
            });
        }
    }
};
