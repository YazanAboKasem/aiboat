<?php

namespace App\Http\Controllers;

use App\Models\Question;
use App\Services\EmbeddingService;
use Illuminate\Http\Request;

class QuestionController extends Controller
{
    /**
     * Display a listing of the questions.
     */
    private function parseKeywords(?string $raw): array
    {
        if (!$raw) return [];
        // يقبل أسطر أو فواصل
        $raw = str_replace(["\r\n","\r"], "\n", $raw);
        $parts = preg_split('/[\n,]+/u', $raw);
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '') $out[$p] = true;
        }
        return array_keys($out); // فريد
    }

    private function parseLexMap(?string $raw): array
    {
        if (!$raw) return [];
        $raw = str_replace(["\r\n","\r"], "\n", $raw);
        $lines = explode("\n", $raw);
        $map = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, '=')) continue;
            [$from, $to] = array_map('trim', explode('=', $line, 2));
            if ($from !== '' && $to !== '') {
                $map[$from] = $to;
            }
        }
        return $map;
    }
    public function index()
    {
        $questions = Question::latest()->get();
        return view('questions.index', compact('questions'));
    }

    /**
     * Show the form for creating a new question.
     */
    public function create()
    {
        return view('questions.create');
    }

    /**
     * Store a newly created question in storage.
     */
// ...

    public function store(Request $request, EmbeddingService $svc)
    {
        $validated = $request->validate([
            'title'   => 'required|string|max:255',
            'content' => 'required|string',
            'answer'  => 'nullable|string',
            // جديد (اختياريان عند الإدخال من الداشبورد):
            'keywords' => 'nullable|string',
            'lex_map'  => 'nullable|string',
        ]);

        // حوّل المدخلات النصية إلى JSON منسّق
        $keywords = $this->parseKeywords($validated['keywords'] ?? null);
        $lexMap   = $this->parseLexMap($validated['lex_map']  ?? null);

        // أنشئ السجل
        $q = Question::create([
            'title'   => $validated['title'],
            'content' => $validated['content'],
            'answer'  => $validated['answer'] ?? null,
            'keywords'=> $keywords ?: null,
            'lex_map' => $lexMap   ?: null,
        ]);

        // ابني Embeddings للعنوان والمحتوى (كي تعمل المطابقة مباشرة)
        try {
            $titleEmb   = $q->title   ? $svc->embed($q->title)   : [];
            $contentEmb = $q->content ? $svc->embed($q->content) : [];

            $q->title_embedding   = $titleEmb ?: null;
            $q->content_embedding = $contentEmb ?: null;
            $q->embedding_quality = ($q->title_embedding || $q->content_embedding) ? 1.0 : 0.0;
            $q->save();
        } catch (\Throwable $e) {
            // لو فشل الـ API، نخزن بدون embeddings ونكمل
            \Log::warning('Embed failed on store: '.$e->getMessage());
        }

        return redirect()->route('questions.index')->with('success', 'تم إضافة السؤال بنجاح');
    }
    /**
     * Display the specified question.
     */
    public function show(Question $question)
    {
        return view('questions.show', compact('question'));
    }

    /**
     * Show the form for editing the specified question.
     */
    public function edit(Question $question)
    {
        return view('questions.edit', compact('question'));
    }

    /**
     * Update the specified question in storage.
     */
    public function update(Request $request, Question $question, EmbeddingService $svc)
    {
        $validated = $request->validate([
            'title'    => 'required|string|max:255',
            'content'  => 'required|string',
            'answer'   => 'nullable|string',
            'intent'   => 'nullable|string|in:access,location,time,price',
            'keywords' => 'nullable|string',
            'lex_map'  => 'nullable|string',
            'refresh_embeddings' => 'nullable|boolean',
        ]);

        $payload = [
            'title'   => $validated['title'],
            'content' => $validated['content'],
            'answer'  => $validated['answer'] ?? null,
            'intent'  => $validated['intent'] ?? null,
        ];

        // تحويل الإدخالات النصية إلى JSON (تُحفَظ كمصفوفات/كائنات بفضل $casts في الموديل)
        $keywords = $this->parseKeywords($validated['keywords'] ?? null);
        $lexMap   = $this->parseLexMap($validated['lex_map']  ?? null);

        $payload['keywords'] = $keywords ?: null;
        $payload['lex_map']  = $lexMap   ?: null;

        // املأ واحفظ
        $question->fill($payload);

        // خيار إعادة توليد المتجهات
        if ($request->boolean('refresh_embeddings')) {
            try {
                $question->title_embedding   = $question->title   ? $svc->embed($question->title)   : null;
                $question->content_embedding = $question->content ? $svc->embed($question->content) : null;
                $question->embedding_quality = ($question->title_embedding || $question->content_embedding) ? 1.0 : 0.0;
            } catch (\Throwable $e) {
                Log::warning('Embed failed on update: '.$e->getMessage());
            }
        }

        $question->save();

        return redirect()->route('questions.index')->with('success', 'تم تحديث السؤال بنجاح');
    }

    /**
     * Remove the specified question from storage.
     */
    public function destroy(Question $question)
    {
        $question->delete();

        return redirect()->route('questions.index')->with('success', 'تم حذف السؤال بنجاح');
    }
}
