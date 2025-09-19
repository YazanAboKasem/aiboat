<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->string('sender_id')->index(); // Meta platform sender ID
            $table->string('sender_name')->nullable(); // Name if available
            $table->text('message'); // Message content
            $table->enum('source', ['facebook', 'instagram']); // Source platform
            $table->boolean('is_reply')->default(false); // Whether this is our reply
            $table->text('attachment_url')->nullable(); // URL to any attachment
            $table->string('attachment_type')->nullable(); // Type of attachment
            $table->timestamp('read_at')->nullable(); // When the message was read
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
