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
        if (!Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->text('value')->nullable();
                $table->string('group')->default('general');
                $table->string('display_name')->nullable();
                $table->text('description')->nullable();
                $table->timestamps();
            });

            // إضافة إعداد افتراضي لنموذج الذكاء الاصطناعي
            DB::table('settings')->insert([
                'key' => 'ai_model',
                'value' => 'assistant', // افتراضيًا استخدم المساعد
                'group' => 'ai',
                'display_name' => 'نموذج الذكاء الاصطناعي',
                'description' => 'اختيار النموذج المستخدم للإجابة على الأسئلة: model_one (ChatGPT) أو assistant (المساعد الثاني)',
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
