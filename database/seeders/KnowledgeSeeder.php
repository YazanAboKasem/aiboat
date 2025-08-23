<?php

namespace Database\Seeders;

use App\Models\KbItem;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class KnowledgeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
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
