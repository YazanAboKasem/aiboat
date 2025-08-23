<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class RerankerService
{
    public function scorePairs(string $question, array $candidates): array
    {
        // $candidates: [['id'=>..,'text'=>..,'source'=>..], ...]
        $apiKey = env('OPENAI_API_KEY');
        $model  = env('OPENAI_MODEL', 'gpt-4o-mini');

        // نبني مطالبة مختصرة
        $parts = [];
        foreach ($candidates as $i => $c) {
            $parts[] = "[{$i}] ".$c['text'];
        }
        $prompt = "قيّم صلة كل مقطع بالسؤال من 0 إلى 1 (رقم فقط لكل سطر بنفس الترتيب).\n".
            "السؤال: {$question}\n".
            implode("\n", $parts);

        $res = Http::withHeaders([
            'Authorization' => 'Bearer '.$apiKey,
            'Content-Type'  => 'application/json',
        ])->post('https://api.openai.com/v1/chat/completions', [
            'model' => $model,
            'temperature' => 0,
            'messages' => [['role'=>'user','content'=>$prompt]],
        ]);

        if (!$res->ok()) return $candidates;

        $txt = trim($res->json('choices.0.message.content') ?? '');
        // نتوقع أرقامًا سطرية: 0.82\n0.31\n...
        $lines = preg_split('/\R/u', $txt);
        foreach ($lines as $i => $ln) {
            if (!isset($candidates[$i])) continue;
            $score = (float)trim($ln);
            $candidates[$i]['rerank'] = max(0.0, min(1.0, $score));
        }

        // رتب تنازليًا حسب rerank (ثم rrf كربط)
        usort($candidates, function($a,$b){
            return ($b['rerank'] <=> $a['rerank']) ?: (($b['rrf'] ?? 0) <=> ($a['rrf'] ?? 0));
        });

        return $candidates;
    }
}
