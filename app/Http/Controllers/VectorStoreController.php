<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

set_time_limit(120);

class VectorStoreController extends Controller
{
    private string $base = 'https://api.openai.com/v1';
    private array $betaHeader = ['OpenAI-Beta' => 'assistants=v2'];

    private function authHeaders(): array
    {
        return array_merge($this->betaHeader, [
            'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
        ]);
    }

    public function setup()
    {
        Log::info('Question updated with ChatGPT answer', [
            'status'          => 'ready',

            'hint'            => 'انسخ الـ vector_store_id وضعه في .env كـ VECTOR_STORE_ID'
        ]);
        $path = storage_path('app/company_knowledge.txt');
        if (!file_exists($path)) {
            return response()->json([
                'error' => 'File not found at storage/app/company_knowledge.txt'
            ], 422);
        }

        // 1) إنشاء Vector Store
        $vsResp = Http::withHeaders($this->authHeaders())
            ->post($this->base . '/vector_stores', [
                'name' => 'Company Knowledge',
            ]);

        if (!$vsResp->successful()) {
            return response()->json([
                'error' => 'Failed to create vector store',
                'details' => $vsResp->json()
            ], 500);
        }
        $vectorStoreId = $vsResp->json('id');


        // 2) رفع الملف لغرض assistants
        $fileResp = Http::withHeaders([
            'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
            'OpenAI-Beta'   => 'assistants=v2',
        ])
            ->attach('file', file_get_contents($path), 'company_knowledge.txt')
            ->post($this->base . '/files', [
                'purpose' => 'assistants',
            ]);

        if (!$fileResp->successful()) {
            return response()->json([
                'error' => 'Failed to upload file',
                'details' => $fileResp->json()
            ], 500);
        }

        $fileId = $fileResp->json('id');
        \Log::info('تم رفع الملف بنجاح، معرّف الملف: ' . $fileId);

        // 3) ربط الملف بالـ Vector Store
        $fileAttachResp = Http::withHeaders($this->authHeaders())
            ->post($this->base . '/vector_stores/' . $vectorStoreId . '/files', [
                'file_id' => $fileId
            ]);

        if (!$fileAttachResp->successful()) {
            return response()->json([
                'error' => 'Failed to attach file to vector store',
                'details' => $fileAttachResp->json()
            ], 500);
        }

        \Log::info('تم ربط الملف بمخزن المتجهات بنجاح');

        $assistantController = new AssistantController();

        $vectorStoreSetupResponse = $assistantController->createAssistant($vectorStoreId);

        if ($vectorStoreSetupResponse->getStatusCode() !== 200) {
            return response()->json([
                'error' => 'Failed to create assistant with vector store',
                'details' => $vectorStoreSetupResponse->getData(true) // Access JSON data as an array
            ], 500);
        }


        // 4) التحقق من حالة المعالجة

        \Log::info("تم إنشاء المساعد ومخزن المتجهات بنجاح: ", $vectorStoreSetupResponse->getData(true));

        return response()->json([
            'status'          => 'processing',
            'vector_store_id' => $vectorStoreId,
            'file_id'         => $fileId,
            'message'         => 'المعالجة ما زالت جارية، تحقق لاحقاً من حالة الـ Vector Store.'
        ]);

    }
}
