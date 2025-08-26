<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>واجهة الأسئلة والأجوبة</title>
    <style>
        .result.good {
            background: #e6f4ea;       /* أخضر فاتح */
            border: 1px solid #a8e6b0; /* إطار أخضر خفيف */
        }

        /* صندوق الاقتراحات */
        .suggests .chip {
            border:1px solid #1a73e8;
            border-radius:999px;
            padding:6px 12px;
            background:#e8f0fe;    /* أزرق فاتح */
            color:#1a73e8;
            cursor:pointer;
            font-size:13px;
            font-weight:bold;
            transition: background 0.2s, color 0.2s;
        }
        .suggests .chip:hover {
            background:#1a73e8;    /* أزرق غامق عند المرور */
            color:#fff;
        }
        .suggests h4 { margin:0 0 10px; font-size:14px; color:#333; }
        .suggests .chips { display:flex; flex-wrap:wrap; gap:8px; }
        .suggests .chip {
            border:1px solid #ddd; border-radius:999px; padding:6px 10px;
            background:#f8f8f8; cursor:pointer; font-size:13px;
        }
        .suggests .chip:hover { background:#f0f0f0; }
        body{font-family:Arial, sans-serif;background:#f8f8f8;margin:0;padding:24px}
        .container{max-width:720px;margin:0 auto}
        .card{background:#fff;border-radius:10px;box-shadow:0 6px 18px rgba(0,0,0,.06);padding:16px;margin-bottom:20px}
        h2{margin:0 0 12px;text-align:center}
        .row{display:flex;gap:8px;margin-top:8px}
        textarea{flex:1;min-height:80px;padding:10px;border:1px solid #ddd;border-radius:8px;resize:vertical}
        button{min-width:120px;border:0;border-radius:8px;padding:10px 14px;cursor:pointer;background:#1a73e8;color:#fff;font-weight:bold}
        button:disabled{opacity:.6;cursor:not-allowed}
        .answer{margin-top:12px;background:#f3f5f7;border-radius:8px;padding:12px;white-space:pre-wrap;line-height:1.7}
        .meta{margin-top:8px;font-size:13px;color:#555}
        .badge{display:inline-block;padding:3px 8px;border-radius:999px;font-size:12px;margin-inline-start:6px}
        .ok{background:#e6f4ea;color:#1e7e34}
        .handover{background:#fdecea;color:#b00020}
        .muted{color:#777}
        .small{font-size:12px}
        .spinner{display:none;margin-top:8px}
        .src{direction:ltr; unicode-bidi:bidi-override;}
        .admin-actions {display:flex;justify-content:center;margin-top:20px}
        .admin-button {background:#4CAF50;margin:0 5px}

        /* جديد: تنسيق قائمة أفضل النتائج */
        .results {display:flex; flex-direction:column; gap:12px; margin-top:12px}
        .result {background:#f3f5f7;border-radius:8px;padding:12px}
        .result h4 {margin:0 0 8px; font-size:15px}
        .result .q {font-weight:bold; margin-bottom:6px}
        .result .a {white-space:pre-wrap; line-height:1.8}
        .result .meta-line {margin-top:6px; font-size:12px; color:#666}
        .rank {display:inline-block; width:22px; height:22px; border-radius:999px; background:#1a73e8; color:#fff; text-align:center; line-height:22px; font-size:12px; margin-inline-start:6px}
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <h2>اسأل سؤالًا</h2>
        <div class="row">
            <textarea id="msg" placeholder="اكتب سؤالك هنا..."></textarea>
            <button id="send">إرسال</button>
        </div>
        <div id="spin" class="spinner muted small">...جاري المعالجة</div>
        <div id="status" class="meta"></div>

        <!-- عدّلنا هذا: بدل جواب واحد، عندنا حاوية نتائج متعددة -->
        <div id="answers" class="results" style="display:none"></div>
        <div id="suggestions" class="suggests" style="display:none">
            <h4>أسئلة أخرى قد تفيد:</h4>
            <div class="chips" id="suggestionChips"></div>
        </div>
        <div id="extra" class="meta"></div>

        <div class="admin-actions">
            <a href="{{ route('questions.index') }}"><button class="admin-button">إدارة الأسئلة</button></a>
            <a href="{{ route('questions.create') }}"><button class="admin-button">إضافة سؤال جديد</button></a>
        </div>
    </div>
</div>

<script>
    const $msg   = document.getElementById('msg');
    const $btn   = document.getElementById('send');
    const $answers = document.getElementById('answers');
    const $extra = document.getElementById('extra');
    const $status= document.getElementById('status');
    const $spin  = document.getElementById('spin');
    const $suggestions = document.getElementById('suggestions');
    const $chips = document.getElementById('suggestionChips');
    // لو صفحة الواجهة على دومين آخر، ضع هنا الـ base URL
    const API_BASE = ''; // مثال: 'http://127.0.0.1:8000'
    function toPercent(val){
        if (val == null) return null;
        if (typeof val === 'string') {
            const m = val.match(/(\d+(?:\.\d+)?)\s*%/);
            if (m) return Math.round(parseFloat(m[1]));
            // لو كانت قيمة رقم نصي بدون %
            const f = parseFloat(val);
            if (!isNaN(f)) return f <= 1 ? Math.round(f*100) : Math.round(f);
            return null;
        }
        if (typeof val === 'number') {
            return val <= 1 ? Math.round(val*100) : Math.round(val);
        }
        return null;
    }
    function setLoading(on){
        $btn.disabled = on;
        $spin.style.display = on ? 'block' : 'none';
    }

    async function callAPI(message){
        const res = await fetch(`${API_BASE}/api/ask`, {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            // ✅ الراوت يتوقع { q: "..." }
            body: JSON.stringify({ q: message })
        });

        let data;
        try { data = await res.json(); }
        catch(e) {
            const txt = await res.text();
            throw new Error('HTTP '+res.status+' '+txt);
        }
        if(!res.ok){
            throw new Error('HTTP '+res.status+' '+(data.error || JSON.stringify(data)));
        }
        return data;
    }

    function round(n){
        if(typeof n !== 'number' || isNaN(n)) return null;
        return Math.round(n * 100) / 100; // دقة أوضح لعرض السكور
    }

    function escapeHtml(s){
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }
    function renderResults(results, query){
        // تنظيف
        $answers.innerHTML = '';
        $answers.style.display = 'none';
        $chips.innerHTML = '';
        $suggestions.style.display = 'none';

        if (!Array.isArray(results) || !results.length) return;

        // رتب تنازليًا حسب match_percent أو score
        results = results.slice().sort((a,b)=>{
            const ap = toPercent(a.match_percent ?? a.score);
            const bp = toPercent(b.match_percent ?? b.score);
            if (ap == null && bp == null) return 0;
            if (ap == null) return 1;
            if (bp == null) return -1;
            return bp - ap;
        });

        const PASS_THRESHOLD_PERCENT = 82; // نفس عتبة الكونترولر
        const top  = results[0];
        const rest = results.slice(1);
        const topPct = toPercent(top?.match_percent ?? top?.score);

        // بطاقة الجواب: نعرض "الجواب فقط" بلا السؤال
        if (top) {
            const el = document.createElement('div');
            el.className = 'result' + (topPct != null && topPct >= PASS_THRESHOLD_PERCENT ? ' good' : '');
            const answer   = top.answer   || '';
            const source   = top.source   || '';
            const parts    = top.parts    || null;

            const partsTxt = parts ? (() => {
                const p = [];
                if (parts.semantic != null) p.push(`Semantic=${round(parts.semantic)}`);
                if (parts.lexical  != null) p.push(`Lexical=${round(parts.lexical)}`);
                if (parts.intent   != null) p.push(`Intent=${round(parts.intent)}`);
                if (parts.keywords != null) p.push(`Keywords=${round(parts.keywords)}`);
                return p.length ? ` | المقاييس: ${p.join(' , ')}` : '';
            })() : '';

            el.innerHTML = `
            <h4><span class="rank">1</span>${topPct!=null ? `أفضل تطابقرر (${topPct}%)` : 'أفضل تطابق'}</h4>
            <div class="a">${escapeHtml(answer || '—')}</div>
            <div class="meta-line small muted">
                ${source ? `المصدر: <span class="src">${escapeHtml(source)}</span>` : ''}
                ${partsTxt}
            </div>
        `;
            $answers.appendChild(el);
            $answers.style.display = 'flex';
        }

        // سيكشن مستقل للاقتراحات (أسئلة فقط)
        if (rest.length) {
            rest.slice(0, 8).forEach((r)=>{
                const q = r.question || '';
                if (!q) return;
                const chip = document.createElement('button');
                chip.className = 'chip';
                const pct = toPercent(r.match_percent ?? r.score);
                chip.innerHTML = `${escapeHtml(q)}${pct!=null ? ` (${pct}%)` : ''}`;
                chip.addEventListener('click', ()=>{ $msg.value = q; send(); });
                $chips.appendChild(chip);
            });
            if ($chips.children.length) $suggestions.style.display = 'block';
        }
    }
    // جديد: عرض قائمة النتائج (أفضل 3)
    async function send(){
        const message = ($msg.value || '').trim();
        if (!message){ alert('الرجاء كتابة سؤال.'); return; }

        setLoading(true);
        $status.textContent = '';
        $answers.style.display = 'none';
        $answers.innerHTML = '';
        $extra.textContent = '';
        $suggestions.style.display = 'none';
        $chips.innerHTML = '';

        try {
            const data = await callAPI(message);

            // شارة الحالة
            const handover = !!data.handover;
            $status.innerHTML = handover
                ? '<span class="badge handover">سيتم تحويل المحادثة للموظف</span>'
                : '<span class="badge ok">تمت المطابقة من قاعدة المعرفة</span>';

            // ✨ التوافق الخلفي: لو ما في results[]، نبنيها من الحقول القديمة
            let results = Array.isArray(data.results) ? data.results : null;
            if (!results) {
                const best = {
                    question: data.match_question || message,
                    answer:   data.answer || '',
                    score:    typeof data.similarity === 'number' ? data.similarity : null,
                    match_percent: typeof data.similarity === 'number' ? Math.round(data.similarity * 100) : null,
                    parts:    data.parts || null,
                    source:   data.source || ''
                };
                const rest = (data.alternatives || []).map((a, i) => ({
                    question: a.question,
                    answer:   a.answer,
                    score:    a.similarity,
                    match_percent: typeof a.similarity === 'number' ? Math.round(a.similarity * 100) : null,
                    parts:    a.parts || null,
                    source:   a.source || ''
                }));
                results = [best, ...rest];
            }

            renderResults(results, message);

            // معلومات إضافية اختيارية
            const extras = [];
            if (data.message && !data.answer && !Array.isArray(data.results)) {
                extras.push(escapeHtml(data.message));
            }
            if (data.parts) {
                const p = [];
                if (data.parts.semantic != null) p.push(`Semantic=${round(data.parts.semantic)}`);
                if (data.parts.lexical  != null) p.push(`Lexical=${round(data.parts.lexical)}`);
                if (data.parts.intent   != null) p.push(`Intent=${round(data.parts.intent)}`);
                if (data.parts.keywords != null) p.push(`Keywords=${round(data.parts.keywords)}`);
                if (p.length) extras.push('تفصيل الأجزاء: ' + p.join(' , '));
            }
            $extra.innerHTML = extras.join(' | ');

        } catch(err) {
            console.error(err);
            $status.innerHTML = '<span class="badge handover">خطأ في الاتصال بالـ API</span>';
            $answers.style.display = 'flex';
            $answers.innerHTML = '<div class="result">تعذّر جلب الإجابات، حاول لاحقًا.</div>';
        } finally {
            setLoading(false);
        }
    }
    $btn.addEventListener('click', send);
    $msg.addEventListener('keydown', (e)=>{
        if(e.key === 'Enter' && (e.ctrlKey || e.metaKey)){ e.preventDefault(); send(); }
    });
</script>
</body>
</html>
