<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

class CreateAssistantTable extends Command
{
    /**
     * اسم وتوصيف الأمر.
     *
     * @var string
     */
    protected $signature = 'assistants:create-table';

    /**
     * وصف الأمر.
     *
     * @var string
     */
    protected $description = 'إنشاء جدول المساعدين في قاعدة البيانات إذا لم يكن موجوداً';

    /**
     * تنفيذ الأمر.
     */
    public function handle()
    {
        if (Schema::hasTable('assistants')) {
            $this->info('جدول "assistants" موجود بالفعل.');
            return;
        }

        try {
            Schema::create('assistants', function (Blueprint $table) {
                $table->id();
                $table->string('assistant_id')->nullable();
                $table->string('vector_store_id')->nullable();
                $table->string('name')->nullable();
                $table->timestamps();
            });

            $this->info('تم إنشاء جدول "assistants" بنجاح!');
        } catch (\Exception $e) {
            $this->error('حدث خطأ أثناء إنشاء الجدول: ' . $e->getMessage());
        }
    }
}
