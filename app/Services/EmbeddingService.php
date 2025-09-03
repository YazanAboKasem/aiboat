<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EmbeddingService
{
    private string $apiKey;
    private string $model;

    public function __construct(?string $apiKey = null, ?string $model = null)
    {
        Log::info('OPENAI_API_KEY:', ['key' => env('OPENAI_API_KEY')]);

        $this->apiKey = $apiKey ?? config('services.openai.key', env('OPENAI_API_KEY'));
        $this->model  = $model  ?? env('OPENAI_EMBED_MODEL', 'text-embedding-3-large');
    }

    public function embed(?string $text): array
    {
        if ($text === null) {
            return [];
        }

        // تنظيف عام + تطبيع عربي
        $clean = (string) Str::of($text)->squish();
        $clean = trim(preg_replace('/\s+/u', ' ', $clean));
        $clean = $this->normalizeAr($clean);

        if ($clean === '') {
            return [];
        }

        $res = Http::withHeaders([
            'Authorization' => 'Bearer '.$this->apiKey,
            'Content-Type'  => 'application/json',
        ])->post('https://api.openai.com/v1/embeddings', [
            'input' => $clean,
            'model' => $this->model,
        ])->throw()->json();

        return $res['data'][0]['embedding'] ?? [];
    }

    public static function cosine(array $a, array $b): float
    {
        $dot = 0.0; $na = 0.0; $nb = 0.0;
        $len = min(count($a), count($b));
        for ($i = 0; $i < $len; $i++) {
            $dot += $a[$i] * $b[$i];
            $na  += $a[$i] * $a[$i];
            $nb  += $b[$i] * $b[$i];
        }
        if ($na == 0 || $nb == 0) return 0.0;
        return $dot / (sqrt($na) * sqrt($nb));
    }

    // --- تطبيع عربي بسيط ---
    private function normalizeAr(string $t): string
    {
        // إزالة التشكيل
        $t = preg_replace('/[ًٌٍَُِّْـ]/u', '', $t);
        // توحيد الألف
        $t = str_replace(['أ','إ','آ'], 'ا', $t);
        // توحيد الياء/الألف المقصورة
        $t = str_replace(['ى'], 'ي', $t);
        // توحيد التاء المربوطة (اختياري)
        $t = str_replace(['ة'], 'ه', $t);
        // مسافات
        $t = preg_replace('/\s+/u', ' ', trim($t));
        return $t;
    }
}
