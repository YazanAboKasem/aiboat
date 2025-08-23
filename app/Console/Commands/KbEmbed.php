<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class KbEmbed extends Command
{
    protected $signature = 'kb:embed
    {--truncate : Clear kb_chunks before inserting}
    {--from-table= : Use a PostgreSQL table as source (e.g. questions)}';

    protected $description = 'Chunk knowledge files and embed them into Postgres (pgvector). Supports .jsonl (one Q/A per line) and plain text, or a DB table via --from-table.';

    public function handle(): int
    {
        $model  = env('OPENAI_EMBED_MODEL', 'text-embedding-3-small'); // 1536 dim
        $apiKey = env('OPENAI_API_KEY');
        if (!$apiKey) {
            $this->error('OPENAI_API_KEY is missing in .env');
            return self::FAILURE;
        }

        // ✅ مسار القراءة من جدول PostgreSQL إن تم تمرير --from-table
        if ($fromTable = $this->option('from-table')) {
            // تفريغ الجدول إن طُلب ذلك
            if ($this->option('truncate')) {
                DB::connection('pgsql')->statement('TRUNCATE TABLE kb_chunks RESTART IDENTITY');
                $this->warn('kb_chunks truncated.');
            }

            $total = 0;

            // نقرأ من pgsql: content = سؤال، answer = جواب
            $cursor = DB::connection('pgsql')->table($fromTable)
                ->select(['id', 'content as q', 'answer as a'])
                ->orderBy('id')
                ->cursor();

            foreach ($cursor as $row) {
                $q = trim((string)($row->q ?? ''));
                $a = trim((string)($row->a ?? ''));
                if ($q === '' && $a === '') continue;

                $qaText = $this->buildQAText($q, $a); // "سؤال: ... \nجواب: ..."
                foreach ($this->chunkText($qaText, 1400, 220) as $i => $chunk) {
                    $clean = $this->normalizeArabic($chunk);
                    $vec   = $this->embed($clean, $apiKey, $model);
                    if (!$vec || !is_array($vec)) {
                        $this->warn("Embed failed: {$fromTable}#{$row->id}#$i");
                        continue;
                    }

                    $id = DB::connection('pgsql')->table('kb_chunks')->insertGetId([
                        'kb_item_id' => $row->id,
                        'source'     => $fromTable.'#'.$row->id.( $i ? "#$i" : '' ),
                        'text'       => $chunk,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    // كتابة الـ vector literal
                    $lit = '['.implode(',', array_map(fn($n)=> (string)$n, $vec)).']';
                    DB::connection('pgsql')->update(
                        'UPDATE kb_chunks SET embedding = ? WHERE id = ?',
                        [$lit, $id]
                    );

                    $total++;
                    if ($total % 20 === 0) $this->info("Inserted $total chunks…");
                }
            }

            // تحسينات اختيارية للجلسة
            try { DB::connection('pgsql')->statement('SET hnsw.ef_search = 80'); } catch (\Throwable $e) {}

            $this->info("Done. Inserted $total chunks from table {$fromTable}.");
            return self::SUCCESS;
        }

        // 🗂️ مسار الملفات القديم (لو ما تم تمرير --from-table)
        $dir = storage_path('app/knowledge');
        if (!is_dir($dir)) {
            $this->error("Directory not found: $dir");
            return self::FAILURE;
        }

        if ($this->option('truncate')) {
            DB::statement('TRUNCATE TABLE kb_chunks RESTART IDENTITY');
            $this->warn('kb_chunks truncated.');
        }

        $files = glob($dir.'/*.*') ?: [];
        if (empty($files)) {
            $this->warn('No files found in storage/app/knowledge');
            return self::SUCCESS;
        }

        $total = 0;
        foreach ($files as $path) {
            $name = basename($path);
            $lower = mb_strtolower($name);

            // ✅ JSONL: كل سطر = {question, answer, source}
            if (str_ends_with($lower, '.jsonl')) {
                $fh = @fopen($path, 'r');
                if (!$fh) { $this->warn("Cannot open: $name"); continue; }

                $lineNo = 0;
                while (!feof($fh)) {
                    $line = fgets($fh);
                    if ($line === false) break;
                    $lineNo++;

                    $line = trim($line);
                    if ($line === '') continue;

                    $row = json_decode($line, true);
                    if (!is_array($row)) { $this->warn("Bad JSON at $name#$lineNo"); continue; }

                    $q   = trim((string)($row['question'] ?? ''));
                    $a   = trim((string)($row['answer'] ?? ''));
                    $src = (string)($row['source'] ?? $name);

                    if ($q === '' && $a === '') continue;

                    $chunk = $this->buildQAText($q, $a);
                    $clean = $this->normalizeArabic($chunk);

                    $vec = $this->embed($clean, $apiKey, $model);
                    if (!$vec || !is_array($vec)) { $this->warn("Embed failed: $name#$lineNo"); continue; }

                    $id = DB::table('kb_chunks')->insertGetId([
                        'kb_item_id' => null,
                        'source'     => $src.'#'.$lineNo,
                        'text'       => $chunk,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $lit = '['.implode(',', array_map(fn($n)=> (string) $n, $vec)).']';
                    DB::update('UPDATE kb_chunks SET embedding = ? WHERE id = ?', [$lit, $id]);

                    $total++;
                    if ($total % 20 === 0) $this->info("Inserted $total chunks…");
                }
                fclose($fh);
                continue;
            }

            // 📄 باقي الصيغ (txt/md…): تقطيع إلى مقاطع
            $raw  = @file_get_contents($path) ?: '';
            $text = trim($raw);
            if ($text === '') { $this->line("Skip empty: $name"); continue; }

            foreach ($this->chunkText($text, 1400, 220) as $i => $chunk) {
                $clean = $this->normalizeArabic($chunk);
                $vec   = $this->embed($clean, $apiKey, $model);
                if (!$vec || !is_array($vec)) { $this->warn("Embed failed: $name#$i"); continue; }

                $id = DB::table('kb_chunks')->insertGetId([
                    'kb_item_id' => null,
                    'source'     => $name.'#'.$i,
                    'text'       => trim($chunk),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $lit = '['.implode(',', array_map(fn($n)=> (string) $n, $vec)).']';
                DB::update('UPDATE kb_chunks SET embedding = ? WHERE id = ?', [$lit, $id]);

                $total++;
                if ($total % 20 === 0) $this->info("Inserted $total chunks…");
            }
        }

        try { DB::statement('SET hnsw.ef_search = 80'); } catch (\Throwable $e) {}

        $this->info("Done. Inserted $total chunks.");
        return self::SUCCESS;
    }

    /**
     * يبني نصًا صغيرًا لسياق البحث من سؤال/جواب
     */
    private function buildQAText(string $q, string $a): string
    {
        if ($q !== '' && $a !== '') {
            return "سؤال: {$q}\nجواب: {$a}";
        } elseif ($q !== '') {
            return "سؤال: {$q}";
        } else {
            return "جواب: {$a}";
        }
    }

    /**
     * تقسيم نص لحجوم مناسبة للسياق.
     */
    private function chunkText(string $txt, int $target = 1400, int $overlap = 220): array
    {
        $txt = preg_replace("/(\r\n|\r)/", "\n", $txt);
        $parts = [];
        $len = mb_strlen($txt);
        $start = 0;

        while ($start < $len) {
            $end = min($len, $start + $target);
            $slice = mb_substr($txt, $start, $end - $start);

            $lastPunct = max(
                mb_strrpos($slice, '۔') ?: -1,
                mb_strrpos($slice, '.') ?: -1,
                mb_strrpos($slice, '؟') ?: -1,
                mb_strrpos($slice, '!') ?: -1
            );
            if ($lastPunct > $target * 0.6) {
                $slice = mb_substr($slice, 0, $lastPunct + 1);
                $end   = $start + mb_strlen($slice);
            }

            $slice = trim($slice);
            if ($slice !== '') $parts[] = $slice;

            if ($end >= $len) break;
            $start = max(0, $end - $overlap);
        }

        return $parts;
    }

    /**
     * تبسيط للنص العربي لتحسين الاسترجاع (لا نعرض النسخة المنقحة للمستخدم).
     */
    private function normalizeArabic(string $s): string
    {
        $s = preg_replace('/[ًٌٍَُِّْـ]/u', '', $s); // حذف التشكيل والتطويل
        $s = str_replace(['إ','أ','آ'], 'ا', $s);
        $s = str_replace(['ى'], 'ي', $s);
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim($s);
    }

    /**
     * استدعاء واجهة OpenAI Embeddings (تعيد مصفوفة أعداد بطول 1536 لنموذج text-embedding-3-small).
     */
    private function embed(string $text, string $apiKey, string $model): ?array
    {
        $resp = Http::withHeaders([
            'Authorization' => 'Bearer '.$apiKey,
            'Content-Type'  => 'application/json',
        ])->post('https://api.openai.com/v1/embeddings', [
            'model' => $model,
            'input' => $text,
        ]);

        if (!$resp->ok()) {
            \Log::error('EMBED_FAIL', ['status'=>$resp->status(),'body'=>$resp->body()]);
            return null;
        }
        return $resp->json('data.0.embedding') ?? null;
    }
}
