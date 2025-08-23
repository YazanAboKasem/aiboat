<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class KbTransfer extends Command
{
    protected $signature = 'kb:transfer {--truncate : Truncate MySQL tables before copy}';
    protected $description = 'Copy kb tables from sqlite_old (source) to default MySQL (target)';

    public function handle()
    {
        $src = DB::connection('sqlite_old');   // المصدر: SQLite
        $dst = DB::connection();               // الهدف: الافتراضي (MySQL)

        if ($this->option('truncate')) {
            $this->info('Truncating MySQL targets (kb_vectors, kb_items)...');
            $dst->table('kb_vectors')->truncate();
            $dst->table('kb_items')->truncate();
        }

        // -------- kb_items --------
        $items = $src->table('kb_items')->orderBy('id')->get();
        $this->info('Copying kb_items: '.$items->count().' rows');
        foreach ($items as $it) {
            $dst->table('kb_items')->updateOrInsert(
                ['id' => $it->id],
                [
                    'source'     => $it->source,
                    'content'    => $it->content,
                    'created_at' => $it->created_at,
                    'updated_at' => $it->updated_at,
                ]
            );
        }

        // -------- kb_vectors --------
        $vecs = $src->table('kb_vectors')->orderBy('id')->get();
        $this->info('Copying kb_vectors: '.$vecs->count().' rows');
        foreach ($vecs as $v) {
            $embedding = is_string($v->embedding) ? $v->embedding : json_encode($v->embedding, JSON_UNESCAPED_UNICODE);

            $dst->table('kb_vectors')->updateOrInsert(
                ['id' => $v->id],
                [
                    'kb_item_id' => $v->kb_item_id,
                    'embedding'  => $embedding, // عمود JSON في MySQL
                    'created_at' => $v->created_at,
                    'updated_at' => $v->updated_at,
                ]
            );
        }

        $this->info('Done copying.');
        return self::SUCCESS;
    }
}
