<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class EmbedService
{
    public function embed(string $text): array
    {
        $apiKey = env('OPENAI_API_KEY');
        $model  = env('OPENAI_EMBED_MODEL', 'text-embedding-3-small');

        $res = Http::withHeaders([
            'Authorization' => 'Bearer '.$apiKey,
            'Content-Type'  => 'application/json',
        ])->post('https://api.openai.com/v1/embeddings', [
            'model' => $model,
            'input' => $text,
        ]);

        if (!$res->ok()) {
            throw new \RuntimeException('OpenAI embeddings error: '.$res->body());
        }

        return $res->json('data.0.embedding');
    }

    public function cosine(array $a, array $b): float
    {
        $dot = 0; $na = 0; $nb = 0;
        $n = min(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $na  += $a[$i] ** 2;
            $nb  += $b[$i] ** 2;
        }
        $den = (sqrt($na) * sqrt($nb)) ?: 1e-9;
        return $dot / $den;
    }
}
