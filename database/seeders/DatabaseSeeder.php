<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\KbItem;

class KnowledgeSeeder extends Seeder
{
    public function run(): void
    {
        $path = storage_path('app/knowledge/knowledge_ar.json');
        $data = json_decode(file_get_contents($path), true) ?: [];

        foreach ($data as $row) {
            KbItem::create([
                'source'  => 'FAQ Arabic',
                'content' => "سؤال: {$row['question']}\nإجابة: {$row['answer']}",
            ]);
        }
    }
}
