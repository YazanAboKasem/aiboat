<?php

namespace App\Services;

class AnswerService
{
    public function fromQA(string $qaText): string
    {
        if (preg_match('/جواب\s*:\s*(.+)/u', $qaText, $m)) {
            return trim($m[1]);
        }
        // تنظيف إن كانت القطعة تحوي "سؤال:" و "جواب:"
        $clean = preg_replace('/سؤال\s*:\s*/u', '', $qaText);
        $clean = preg_replace('/جواب\s*:\s*/u', '', $clean);
        // إن بقي نص طويل جدًا، اقتطع أول 350–400 حرف
        if (mb_strlen($clean) > 400) $clean = mb_substr($clean, 0, 400).'…';
        return trim($clean);
    }
}
