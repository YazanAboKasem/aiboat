<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>عرض السؤال</title>
    <style>
        body{font-family:Arial, sans-serif;background:#f8f8f8;margin:0;padding:24px}
        .container{max-width:720px;margin:0 auto}
        .card{background:#fff;border-radius:10px;box-shadow:0 6px 18px rgba(0,0,0,.06);padding:16px;margin-bottom:20px}
        h2{margin:0 0 16px;text-align:center}
        .content{margin-top:16px;line-height:1.6}
        .label{font-weight:bold;margin-top:16px;color:#555;display:block}
        .answer{background:#f3f5f7;border-radius:8px;padding:12px;white-space:pre-wrap;line-height:1.7;margin-top:8px}
        .meta{color:#666;font-size:14px;margin-top:16px;text-align:left}
        .meta .kv{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-start}
        .tag{display:inline-block;background:#eef2ff;color:#1a237e;border-radius:16px;padding:4px 10px;font-size:13px}
        .list{margin:8px 0 0; padding-right:20px}
        .muted{color:#888;font-size:14px}
        .table{width:100%;border-collapse:collapse;margin-top:8px}
        .table th,.table td{border:1px solid #eee;padding:8px;text-align:right}
        .table th{background:#fafafa;color:#555}
        .actions{display:flex;gap:8px;justify-content:center;margin-top:24px}
        button{min-width:100px;border:0;border-radius:8px;padding:8px 12px;cursor:pointer;color:#fff;font-weight:bold}
        .btn-primary{background:#1a73e8;}
        .btn-warning{background:#ff9800;}
        .btn-danger{background:#f44336;}
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <h2>{{ $question->title }}</h2>

        <div class="content">
            <span class="label">محتوى السؤال:</span>
            <p>{{ $question->content }}</p>

            @if($question->answer)
                <span class="label">الإجابة:</span>
                <div class="answer">{{ $question->answer }}</div>
            @else
                <span class="label">الإجابة:</span>
                <div class="answer">لم يتم إضافة إجابة بعد</div>
            @endif

            <!-- جديد: نية السؤال -->
            <span class="label">النية (Intent):</span>
            @php
                $intentMap = [
                    'access' => 'الوصول / الاتجاهات',
                    'location' => 'الموقع',
                    'time' => 'الأوقات',
                    'price' => 'الأسعار/التذاكر',
                ];
            @endphp
            <div class="muted">
                {{ $intentMap[$question->intent] ?? ($question->intent ?? '— غير محدد —') }}
            </div>

            <!-- جديد: الكلمات المفتاحية -->
            <span class="label">الكلمات المفتاحية (Keywords):</span>
            @if(is_array($question->keywords) && count($question->keywords))
                <div class="meta">
                    <div class="kv">
                        @foreach($question->keywords as $kw)
                            <span class="tag">{{ $kw }}</span>
                        @endforeach
                    </div>
                </div>
            @else
                <div class="muted">— لا توجد كلمات مفتاحية —</div>
            @endif

            <!-- جديد: قاموس الاستبدال -->
            <span class="label">قاموس الاستبدال (Lex Map):</span>
            @if(is_array($question->lex_map) && count($question->lex_map))
                <table class="table">
                    <thead>
                    <tr>
                        <th>من</th>
                        <th>إلى</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($question->lex_map as $from => $to)
                        <tr>
                            <td>{{ $from }}</td>
                            <td>{{ $to }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                <div class="muted">تُطبَّق هذه القواعد وقت المطابقة فقط لتوحيد المرادفات؛ لا تغيّر نص السؤال الأصلي.</div>
            @else
                <div class="muted">— لا توجد قواعد استبدال —</div>
            @endif
        </div>

        <div class="meta">تاريخ الإنشاء: {{ $question->created_at->format('Y-m-d H:i') }}</div>

        <div class="actions">
            <a href="{{ route('questions.edit', $question) }}"><button class="btn-warning">تعديل</button></a>
            <form action="{{ route('questions.destroy', $question) }}" method="POST" onsubmit="return confirm('هل أنت متأكد من حذف هذا السؤال؟');">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn-danger">حذف</button>
            </form>
            <a href="{{ route('questions.index') }}"><button class="btn-primary">عودة للقائمة</button></a>
        </div>
    </div>
</div>
</body>
</html>
