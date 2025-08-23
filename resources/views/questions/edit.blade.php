<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>تعديل السؤال</title>
    <style>
        body{font-family:Arial, sans-serif;background:#f8f8f8;margin:0;padding:24px}
        .container{max-width:720px;margin:0 auto}
        .card{background:#fff;border-radius:10px;box-shadow:0 6px 18px rgba(0,0,0,.06);padding:16px;margin-bottom:20px}
        h2{margin:0 0 16px;text-align:center}
        .form-group{margin-bottom:16px}
        label{display:block;margin-bottom:8px;font-weight:bold;color:#555}
        input, textarea, select{width:100%;padding:10px;border:1px solid #ddd;border-radius:8px;font-size:16px}
        textarea{min-height:120px;resize:vertical}
        small{display:block;color:#777;margin-top:6px;line-height:1.6}
        button{min-width:120px;border:0;border-radius:8px;padding:10px 14px;cursor:pointer;color:#fff;font-weight:bold}
        .btn-primary{background:#1a73e8;}
        .btn-secondary{background:#6c757d;}
        .actions{display:flex;gap:8px;justify-content:center;margin-top:24px}
        .alert{padding:12px;border-radius:8px;margin-bottom:16px}
        .alert-danger{background:#fdecea;color:#b00020}
        .errors{color:#b00020;margin-top:5px;font-size:14px}
        .row-inline{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
        .checkbox{display:flex;gap:8px;align-items:center}
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <h2>تعديل السؤال</h2>

        @if($errors->any())
            <div class="alert alert-danger">
                <ul style="margin:0;padding-right:20px">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @php
            // تجهيز قيم النصوص المركّبة من الكائن/المصفوفة
            $kwOld = old('keywords');
            $kwValue = $kwOld !== null
                ? $kwOld
                : (is_array($question->keywords) ? implode("\n", $question->keywords) : '');

            $lexOld = old('lex_map');
            if ($lexOld !== null) {
                $lexValue = $lexOld;
            } else {
                $lexValue = '';
                if (is_array($question->lex_map)) {
                    foreach ($question->lex_map as $from => $to) {
                        $lexValue .= $from.'='.$to."\n";
                    }
                    $lexValue = trim($lexValue);
                }
            }
        @endphp

        <form action="{{ route('questions.update', $question) }}" method="POST">
            @csrf
            @method('PUT')

            <div class="form-group">
                <label for="title">عنوان السؤال</label>
                <input type="text" id="title" name="title" value="{{ old('title', $question->title) }}" required>
            </div>

            <div class="form-group">
                <label for="content">محتوى السؤال</label>
                <textarea id="content" name="content" required>{{ old('content', $question->content) }}</textarea>
            </div>

            <div class="form-group">
                <label for="answer">الإجابة (اختياري)</label>
                <textarea id="answer" name="answer">{{ old('answer', $question->answer) }}</textarea>
            </div>

            <!-- جديد: النية -->
            <div class="form-group">
                <label for="intent">نية السؤال (اختياري)</label>
                <select id="intent" name="intent">
                    @php $intent = old('intent', $question->intent); @endphp
                    <option value="" {{ $intent==='' || $intent===null ? 'selected' : '' }}>— بدون —</option>
                    <option value="access"   {{ $intent==='access'   ? 'selected' : '' }}>الوصول / الاتجاهات</option>
                    <option value="location" {{ $intent==='location' ? 'selected' : '' }}>الموقع</option>
                    <option value="time"     {{ $intent==='time'     ? 'selected' : '' }}>الأوقات</option>
                    <option value="price"    {{ $intent==='price'    ? 'selected' : '' }}>الأسعار/التذاكر</option>
                </select>
                <small>تساعد على إعادة الترتيب عند المطابقة.</small>
            </div>

            <!-- جديد: الكلمات المفتاحية -->
            <div class="form-group">
                <label for="keywords">الكلمات المفتاحية — اكتب سطر لكل كلمة أو افصل بفواصل</label>
                <textarea id="keywords" name="keywords" placeholder="مثال:
طائرة بدون طيار
درون
طائرة مروحية">{{ $kwValue }}</textarea>
                <small>تُستخدم لرفع سكور التطابق (Boost). لا تُرسل للـ Embedding.</small>
            </div>

            <!-- جديد: قاموس الاستبدال -->
            <div class="form-group">
                <label for="lex_map">قاموس الاستبدال — قاعدة في كل سطر بصيغة "من=إلى"</label>
                <textarea id="lex_map" name="lex_map" placeholder="مثال:
عندكن=يوجد
في=يوجد
لوكيشن=موقع
الطريق=الوصول">{{ $lexValue }}</textarea>
                <small>لا نغيّر نص السؤال الأصلي؛ هذه القواعد تُطبّق فقط وقت المطابقة لتوحيد المرادفات.</small>
            </div>

            <!-- جديد: خيار إعادة توليد الـ Embeddings بعد التعديل -->
            <div class="form-group">
                <label class="checkbox">
                    <input type="checkbox" name="refresh_embeddings" value="1" {{ old('refresh_embeddings') ? 'checked' : '' }}>
                    إعادة توليد Embeddings للعنوان/المحتوى بعد الحفظ
                </label>
                <small>فعّله إذا عدّلت العنوان/المحتوى وتريد تحديث المتجهات فورًا.</small>
            </div>

            <div class="actions">
                <button type="submit" class="btn-primary">تحديث السؤال</button>
                <a href="{{ route('questions.index') }}"><button type="button" class="btn-secondary">إلغاء</button></a>
            </div>
        </form>
    </div>
</div>
</body>
</html>
