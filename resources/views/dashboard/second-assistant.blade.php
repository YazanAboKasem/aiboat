@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h4>إدارة المساعد الثاني</h4>
                </div>
                <div class="card-body">

                    @if(session('success'))
                        <div class="alert alert-success">
                            {{ session('success') }}
                        </div>
                    @endif

                    @if(session('error'))
                        <div class="alert alert-danger">
                            {{ session('error') }}
                        </div>
                    @endif

                    @if(isset($fileExists) && !$fileExists)
                        <div class="alert alert-warning">
                            <strong>تنبيه:</strong> الملف غير موجود حاليًا وسيتم إنشاؤه عند الحفظ.
                        </div>
                    @endif

                    @if(isset($tableExists) && !$tableExists)
                        <div class="alert alert-danger">
                            <strong>خطأ في قاعدة البيانات:</strong> جدول "assistants" غير موجود أو غير قابل للوصول.
                            <br>قم بتنفيذ الأمر التالي في وحدة التحكم: <code>php artisan migrate</code>
                            <br>بعد ذلك قم بتحديث هذه الصفحة.

                            @if(session('pending_vector_store_id'))
                                <div class="mt-2">
                                    <button type="button" class="btn btn-sm btn-info check-status"
                                            data-vector-id="{{ session('pending_vector_store_id') }}">
                                        التحقق من حالة المعالجة
                                    </button>
                                </div>

                                <script>
                                    document.addEventListener('DOMContentLoaded', function() {
                                        document.querySelector('.check-status').addEventListener('click', function() {
                                            const vectorId = this.getAttribute('data-vector-id');

                                            fetch('{{ route("vector_status") }}', {
                                                method: 'POST',
                                                headers: {
                                                    'Content-Type': 'application/json',
                                                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                                                },
                                                body: JSON.stringify({ vector_store_id: vectorId })
                                            })
                                            .then(response => response.json())
                                            .then(data => {
                                                if (data.status === 'ready') {
                                                    alert('اكتملت معالجة مخزن المتجهات! معرف المخزن: ' + vectorId);
                                                    window.location.reload();
                                                } else {
                                                    alert('المعالجة ما زالت جارية. قيد التقدم: ' +
                                                          (data.file_counts?.in_progress || 0) +
                                                          '، مكتمل: ' +
                                                          (data.file_counts?.completed || 0));
                                                }
                                            })
                                            .catch(error => {
                                                console.error('Error:', error);
                                                alert('حدث خطأ أثناء التحقق من الحالة');
                                            });
                                        });
                                    });
                                </script>
                            @endif
                        </div>
                    @endif

                    @if($assistant)
                        <div class="alert alert-info">
                            <strong>معلومات المساعد الحالي:</strong><br>
                            معرف المساعد: {{ $assistant->assistant_id ?: 'غير محدد' }}<br>
                            معرف مخزن المتجهات: {{ $assistant->vector_store_id ?: 'غير محدد' }}<br>
                            آخر تحديث: {{ $assistant->updated_at->format('Y-m-d H:i:s') }}
                        </div>
                    @endif

                    <form method="POST" action="{{ route('second.assistant.update') }}">
                        @csrf

                        <div class="form-group mb-3">
                            <label for="content" class="form-label fw-bold">محتوى ملف المعرفة (نص):</label>
                            <div class="mb-2">
                                <small class="text-muted">هذا المحتوى سيتم حفظه في الملف <code>app/company_knowledge.txt</code></small>
                            </div>
                            <textarea id="content" name="content" class="form-control" style="direction: ltr; min-height: 400px; font-family: monospace; white-space: pre-wrap;" spellcheck="false">{{ $content }}</textarea>
                            <small class="text-muted">قم بتحرير محتوى ملف المعرفة هنا. أدخل كل معلومة في سطر منفصل للحصول على أفضل النتائج.</small>
                        </div>

                        <div class="alert alert-warning">
                            <strong>ملاحظة هامة:</strong> عند النقر على زر الحفظ، سيتم تحديث الملف وإنشاء مخزن متجهات جديد ومساعد جديد.
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> حفظ وتحديث المساعد
                        </button>
                        <a href="{{ route('dashboard') }}" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> عودة
                        </a>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
    // ضبط حجم النص تلقائيًا عند التحميل
    document.addEventListener('DOMContentLoaded', function() {
        // تأكد من أن النص يظهر بشكل صحيح
        const textarea = document.getElementById('content');
        // أعد تعيين قيمة النص (لضمان ظهور محتواه)
        const content = textarea.value;
        textarea.value = '';
        setTimeout(() => {
            textarea.value = content;
        }, 100);
    });
</script>
@endsection
