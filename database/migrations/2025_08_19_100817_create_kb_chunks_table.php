<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('kb_chunks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('kb_item_id')->nullable();
            $table->string('source')->index();  // اسم/مسار الملف أو المرجع
            $table->text('text');               // نص المقطع
            $table->timestamps();
        });

        // عمود المتجه (pgvector) بطول 3072 لتضمينات text-embedding-3-large
        DB::statement('ALTER TABLE kb_chunks ADD COLUMN embedding vector(1536)');
        // فهرس ivfflat لمسافة L2 (قابل للتعديل لاحقاً)
        DB::statement('CREATE INDEX kb_chunks_embedding_hnsw ON kb_chunks USING hnsw (embedding vector_l2_ops) WITH (m = 16, ef_construction = 64)');
        // فهرس نصي (trigram) للبحث الهجين مستقبلاً
        DB::statement('CREATE INDEX kb_chunks_text_trgm ON kb_chunks USING gin (text gin_trgm_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_chunks');
    }
};
