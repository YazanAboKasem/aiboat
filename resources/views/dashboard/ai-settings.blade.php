@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h4>إعدادات الذكاء الاصطناعي</h4>
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

                    @if(isset($tableExists) && !$tableExists)
                        <div class="alert alert-danger">
                            <strong>خطأ في قاعدة البيانات:</strong> جدول "settings" غير موجود أو غير قابل للوصول.
                            <br>قم بتنفيذ الأمر التالي في وحدة التحكم: <code>php artisan migrate</code>
                            <br>بعد ذلك قم بتحديث هذه الصفحة.
                        </div>
                    @endif

                    <form method="POST" action="{{ route('ai.settings.update') }}">
                        @csrf
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5>اختيار نموذج الذكاء الاصطناعي</h5>
                            </div>
                            <div class="card-body">
                                <p class="text-muted">حدد النموذج الذي سيتم استخدامه للإجابة على الأسئلة:</p>

                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="radio" name="ai_model" id="model_one"
                                           value="model_one" {{ $currentModel === 'model_one' ? 'checked' : '' }}>
                                    <label class="form-check-label" for="model_one">
                                        <strong>النموذج الأول (ChatGPT)</strong>
                                        <p class="text-muted mb-0">يستخدم واجهة ChatGPT مباشرة دون تدريب خاص بالمحتوى.</p>
                                    </label>
                                </div>

                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="ai_model" id="assistant"
                                           value="assistant" {{ $currentModel === 'assistant' ? 'checked' : '' }}>
                                    <label class="form-check-label" for="assistant">
                                        <strong>المساعد الثاني (Assistant)</strong>
                                        <p class="text-muted mb-0">يستخدم المساعد المدرب على المحتوى الخاص بالموقع.</p>
                                    </label>
                                </div>

                                @if(!$assistant)
                                    <div class="alert alert-warning mt-3">
                                        <strong>تنبيه:</strong> لم يتم إعداد المساعد الثاني بعد. يرجى إعداده أولاً
                                        <a href="{{ route('second.assistant') }}" class="alert-link">من هنا</a>
                                        إذا كنت ترغب باستخدامه.
                                    </div>
                                @endif
                            </div>
                        </div>

                        <div class="card mb-4">
                            <div class="card-header">
                                <h5>مقارنة بين النموذجين</h5>
                            </div>
                            <div class="card-body">
                                <table class="table table-bordered">
                                    <thead class="table-light">
                                        <tr>
                                            <th scope="col">الميزة</th>
                                            <th scope="col">النموذج الأول (ChatGPT)</th>
                                            <th scope="col">المساعد الثاني (Assistant)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td>المعرفة العامة</td>
                                            <td class="table-success">ممتاز</td>
                                            <td class="table-warning">محدود</td>
                                        </tr>
                                        <tr>
                                            <td>المعرفة بالمحتوى الخاص</td>
                                            <td class="table-warning">محدود</td>
                                            <td class="table-success">ممتاز</td>
                                        </tr>
                                        <tr>
                                            <td>الاتصال بالإنترنت</td>
                                            <td class="table-warning">لا</td>
                                            <td class="table-warning">لا</td>
                                        </tr>
                                        <tr>
                                            <td>سرعة الإجابة</td>
                                            <td class="table-success">سريع</td>
                                            <td class="table-warning">متوسط</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> حفظ الإعدادات
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
