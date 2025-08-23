<?php

namespace App\Console\Commands;

use App\Models\Question;
use App\Services\EmbeddingService;
use Illuminate\Console\Command;

class BackfillQuestionEmbeddings extends Command
{
    protected $signature = 'embeddings:backfill-questions {--force : Rebuild even if present}';
    protected $description = 'Build embeddings for all questions';
    public function handle(\App\Services\EmbeddingService $svc): int
    {
        $this->info('Backfilling title/content embeddings with the current model...');
        $updated = 0; $skipped = 0; $failed = 0;

        \App\Models\Question::orderBy('id')
            ->chunkById(200, function ($chunk) use ($svc, &$updated, &$skipped, &$failed) {
                foreach ($chunk as $q) {
                    $title   = is_string($q->title)   ? trim($q->title)   : '';
                    $content = is_string($q->content) ? trim($q->content) : '';

                    if ($title === '' && $content === '') {
                        $skipped++;
                        continue;
                    }

                    try {
                        $embTitle = $title   !== '' ? $svc->embed($title)   : [];
                        $embCont  = $content !== '' ? $svc->embed($content) : [];

                        if ((!$embTitle || count($embTitle) === 0) && (!$embCont || count($embCont) === 0)) {
                            $q->title_embedding   = null;
                            $q->content_embedding = null;
                            $q->embedding_quality = 0.0;
                            $q->save();
                            $skipped++;
                            continue;
                        }

                        $q->title_embedding   = $embTitle ?: null;
                        $q->content_embedding = $embCont  ?: null;
                        $q->embedding_quality = 1.0;

                        // (اختياري) إن كنت لا تريد استخدام العمود القديم بعد الآن:
                        // $q->embedding = null;

                        $q->save();
                        $updated++;
                    } catch (\Throwable $e) {
                        $failed++;
                        $this->error("ID {$q->id} failed: ".$e->getMessage());
                    }
                }
            });

        $this->info("Done. Updated={$updated}, Skipped={$skipped}, Failed={$failed}");
        return self::SUCCESS;
    }
}
