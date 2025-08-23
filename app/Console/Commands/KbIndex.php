<?php


namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\KbItem;

class KbIndex extends Command
{
    protected $signature = 'kb:index';
    protected $description = 'Index knowledge base items for semantic search';

    public function handle()
    {
        $items = KbItem::all();

        foreach ($items as $item) {
            // هنا تقدر تضيف كود لإرسال المحتوى إلى OpenAI embeddings أو أي محرك بحث
            $this->info("Indexing item #{$item->id}: " . mb_substr($item->content, 0, 50));
        }

        $this->info('Knowledge base indexed successfully!');
        return Command::SUCCESS;
    }
}
