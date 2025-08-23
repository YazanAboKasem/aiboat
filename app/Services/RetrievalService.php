<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use App\Services\EmbedService;

class RetrievalService
{
    public function __construct(private EmbedService $embed) {}

    private function detectScript(string $s): string {
        // العربية: النطاق الأساسي + ملحقات شائعة
        $hasArabic = preg_match('/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $s);
        // اللاتينية (أساسي + موسّع)
        $hasLatin  = preg_match('/[\x{0041}-\x{007A}\x{00C0}-\x{024F}]/u', $s);

        if ($hasArabic && !$hasLatin) return 'arabic';
        if ($hasLatin  && !$hasArabic) return 'latin';
        if ($hasArabic && $hasLatin)   return 'mixed';
        return 'other';
    }

    private function sameScript(string $qScript, string $text): bool {
        $tScript = $this->detectScript($text);

        // إن كان السؤال مختلط/غير محدد، لا نقيّد
        if ($qScript === 'mixed' || $qScript === 'other') return true;

        // طباعة صارمة: العربي مع العربي فقط، واللاتيني مع اللاتيني فقط
        return $qScript === $tScript;
    }

    public function topKHybrid(string $question, int $k = 6, int $vecN = 50, int $textN = 50): array
    {
        // استخدم السؤال الخام لكشف السكربت (قبل normalize)
        $qScript = $this->detectScript($question);

        // طبّع للسيرش فقط (لا تستخدمه لكشف اللغة)
        $q = $this->normalize($question);

        // 1) تضمين
        $vec = $this->embed->embed($q);
        if (!$vec) return [];
        $lit = '['.implode(',', array_map(fn($n)=>(string)$n, $vec)).']';

        try { DB::statement('SET hnsw.ef_search = 120'); } catch (\Throwable $e) {}

        // 2) متجهات
        $vecRows = DB::select("
        SELECT id, source, text, (embedding <-> ?::vector) AS l2
        FROM kb_chunks
        ORDER BY embedding <-> ?::vector
        LIMIT {$vecN}
    ", [$lit, $lit]);

        // 3) نصي
        DB::statement("SET pg_trgm.similarity_threshold = 0.2");
        $textRows = DB::select("
        SELECT id, source, text, similarity(text, ?) AS sim
        FROM kb_chunks
        WHERE text % ?
        ORDER BY similarity(text, ?) DESC
        LIMIT {$textN}
    ", [$q, $q, $q]);

        // 3.1) تصفية بحسب سكربت السؤال
        $vecFiltered  = array_values(array_filter($vecRows,  fn($r) => $this->sameScript($qScript, $r->text)));
        $textFiltered = array_values(array_filter($textRows, fn($r) => $this->sameScript($qScript, $r->text)));

        // 3.2) إن صُفّر المرشّح لأي سبب، نعمل Fail-open (لا نقيّد)
        if (empty($vecFiltered))  $vecFiltered  = $vecRows;
        if (empty($textFiltered)) $textFiltered = $textRows;

        // 4) RRF
        $rrfK = 60;
        $scores = [];

        foreach ($vecFiltered as $i => $r) {
            $scores[$r->id] = ($scores[$r->id] ?? 0) + 1.0/($rrfK + ($i+1));
        }
        foreach ($textFiltered as $i => $r) {
            $scores[$r->id] = ($scores[$r->id] ?? 0) + 1.0/($rrfK + ($i+1));
        }

        // 5) تفاصيل
        $byId = [];
        foreach ($vecFiltered as $r) $byId[$r->id] = ['id'=>$r->id,'source'=>$r->source,'text'=>$r->text,'l2'=>$r->l2,'sim'=>null];
        foreach ($textFiltered as $r) {
            if (!isset($byId[$r->id])) $byId[$r->id] = ['id'=>$r->id,'source'=>$r->source,'text'=>$r->text,'l2'=>null,'sim'=>$r->sim];
            else $byId[$r->id]['sim'] = $r->sim;
        }

        // 6) ترتيب واختيار
        uasort($scores, fn($a,$b)=> $b<=>$a);
        $topIds = array_slice(array_keys($scores), 0, $k);

        $out = [];
        foreach ($topIds as $id) {
            if (isset($byId[$id])) $out[] = $byId[$id] + ['rrf'=>$scores[$id]];
        }
        return $out;
    }

    // استخراج "الجواب" من نص الـ QA ("سؤال:\nجواب:")
    public function extractAnswer(string $qaText): string
    {
        // نحاول التقاط السطر بعد "جواب:"
        if (preg_match('/جواب\s*:\s*(.+)/u', $qaText, $m)) {
            return trim($m[1]);
        }
        // fallback: إن لم نجد، رجع النص كاملاً (ثم نلخّص لاحقاً)
        return trim($qaText);
    }

    private function normalize(string $s): string
    {
        $s = preg_replace('/[ًٌٍَُِّْـ]/u', '', $s); // حذف التشكيل/التطويل
        $s = str_replace(['إ','أ','آ'], 'ا', $s);
        $s = str_replace(['ى'], 'ي', $s);
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim($s);
    }
}
