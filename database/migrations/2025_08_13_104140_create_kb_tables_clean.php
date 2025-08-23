<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('kb_items')) {
            Schema::create('kb_items', function (Blueprint $t) {
                $t->id();
                $t->string('source')->nullable(); // اسم الملف/القسم
                $t->text('content');              // فقرة المعرفة
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('kb_vectors')) {
            Schema::create('kb_vectors', function (Blueprint $t) {
                $t->id();
                $t->foreignId('kb_item_id')->constrained('kb_items')->cascadeOnDelete();
                $t->json('embedding');            // مصفوفة الأرقام
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_vectors');
        Schema::dropIfExists('kb_items');
    }
};
