<?php

namespace App\Http\Controllers;

use App\Models\Question;
use App\Services\EmbeddingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
        Log::info('META_APP_SECRET:', ['key' => env('META_APP_SECRET')]);

        $questions = Question::latest()->get();
        return view('questions.index', compact('questions'));
    }
    public function updateWithChatGptAnswer(Request $request, EmbeddingService $svc, KeywordExtractorService $keywordExtractor)
    {
        // التحقق من صحة البيانات المدخلة
        $validated = $request->validate([
            'question_id' => 'required|exists:questions,id',
            'chatgpt_answer' => 'required|string',
            'keywords' => 'nullable|string',
            'lex_map' => 'nullable|string',
            'intent' => 'nullable|string',
        ]);

        // البحث عن السؤال المطلوب باستخدام معرّف السؤال
        $question = \App\Models\Question::findOrFail($validated['question_id']);
        $question->answer = $validated['chatgpt_answer'];

        // استخراج الكلمات المفتاحية المدخلة أو باستخدام الخدمة
        $providedKeywords = !empty($validated['keywords'])
            ? $this->parseKeywordString($validated['keywords'])
            : [];
        $extractedKeywords = empty($providedKeywords)
            ? $keywordExtractor->extract($question->title)
            : [];
        $keywords = array_unique(array_merge($providedKeywords, $extractedKeywords));

        // استخراج قاموس المرادفات المدخل أو باستخدام الخدمة
        $providedLexMap = !empty($validated['lex_map'])
            ? $this->parseLexMapString($validated['lex_map'])
            : [];
        $extractedLexMap = empty($providedLexMap)
            ? $keywordExtractor->generateLexMap($question->title)
            : [];
        $lexMap = array_merge($providedLexMap, $extractedLexMap);

        // تحديث البيانات
        $question->keywords = $keywords;
        $question->lex_map = $lexMap;

        // تحديث النية إذا تم توفيرها
        if (!empty($validated['intent'])) {
            $question->intent = $validated['intent'];
        }

        // بناء الـ embeddings بناءً على عنوان السؤال ومحتواه
        $question->title_embedding = $svc->embed($question->title);
        $question->content_embedding = $svc->embed($question->content);
        $question->embedding_quality = 1.0;

        // حفظ التغييرات
        $question->save();

        // إعادة استجابة JSON إيجابية
        return response()->json([
            'status' => 'success',
            'message' => 'تم تحديث الإجابة والكلمات المفتاحية بنجاح',
            'data' => [
                'id' => $question->id,
                'title' => $question->title,
                'answer' => $question->answer,
                'keywords' => $question->keywords,
                'lex_map' => $question->lex_map,
                'intent' => $question->intent,
            ],
        ]);
    }

    /**
     * تحويل نص الكلمات المفتاحية إلى مصفوفة
     */
    private function parseKeywordString(?string $keywordsStr): array
    {
        if (empty($keywordsStr)) return [];

        // تقبل أسطر أو فواصل
        $keywordsStr = str_replace(["\r\n", "\r"], "\n", $keywordsStr);
        $parts = preg_split('/[\n,]+/u', $keywordsStr);

        $keywords = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if (!empty($part)) {
                $keywords[] = $part;
            }
        }

        return array_unique($keywords);
    }

    /**
     * تحويل نص قاموس المرادفات إلى مصفوفة ترابطية
     */
    private function parseLexMapString(?string $lexMapStr): array
    {
        if (empty($lexMapStr)) return [];

        $lexMapStr = str_replace(["\r\n", "\r"], "\n", $lexMapStr);
        $lines = explode("\n", $lexMapStr);

        $lexMap = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || !str_contains($line, '=')) continue;

            [$from, $to] = array_map('trim', explode('=', $line, 2));
            if (!empty($from) && !empty($to)) {
                $lexMap[$from] = $to;
            }
        }

        return $lexMap;
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
