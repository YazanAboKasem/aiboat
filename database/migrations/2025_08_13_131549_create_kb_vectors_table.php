<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('kb_vectors')) {
            Schema::create('kb_vectors', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('kb_item_id');
                $table->json('embedding');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_vectors');
    }
};
