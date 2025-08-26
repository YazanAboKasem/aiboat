<?php

namespace App\Console\Commands;

use App\Models\KbItem;
use App\Models\KbVector;
use App\Services\EmbedService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class KbEmbedCommand extends Command
{
    protected $signature = 'kb:embed {--force : Override existing embeddings}';
    protected $description = 'Generate embeddings for knowledge base items';

    protected $embedService;

    public function __construct(EmbedService $embedService)
    {
        parent::__construct();
        $this->embedService = $embedService;
    }

    public function handle()
    {
        $force = $this->option('force');
        $items = KbItem::whereDoesntHave('vector')->orWhere(function($query) use ($force) {
            if ($force) {
                $query->whereHas('vector');
            }
        })->get();

        $total = $items->count();
        $this->info("Found {$total} items that need embeddings" . ($force ? ' (including existing ones)' : ''));

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $processed = 0;
        $failed = 0;

        foreach ($items as $item) {
            // Get question from content
            $question = $item->getQuestionAttribute();

            if (empty($question)) {
                $this->warn("Item #{$item->id} has no question text");
                $failed++;
                $bar->advance();
                continue;
            }

            // Generate embedding
            $embedding = $this->embedService->generateEmbedding($question);

            if (!$embedding) {
                $this->error("Failed to generate embedding for item #{$item->id}");
                $failed++;
                $bar->advance();
                continue;
            }

            // Save embedding
            KbVector::updateOrCreate(
                ['kb_item_id' => $item->id],
                ['embedding' => $embedding]
            );

            $processed++;
            $bar->advance();

            // Small delay to avoid rate limiting
            usleep(100000); // 100ms
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Processed {$processed} items, failed {$failed} items");

        return Command::SUCCESS;
    }
}
