<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>إدارة الأسئلة</title>
    <style>
        body{font-family:Arial, sans-serif;background:#f8f8f8;margin:0;padding:24px}
        .container{max-width:960px;margin:0 auto}
        .card{background:#fff;border-radius:10px;box-shadow:0 6px 18px rgba(0,0,0,.06);padding:16px;margin-bottom:20px}
        h2{margin:0 0 16px;text-align:center}
        .row{display:flex;gap:8px;margin-top:8px}
        button{min-width:100px;border:0;border-radius:8px;padding:8px 12px;cursor:pointer;color:#fff;font-weight:bold}
        .btn-primary{background:#1a73e8;}
        .btn-success{background:#4CAF50;}
        .btn-danger{background:#f44336;}
        .btn-warning{background:#ff9800;}
        .table{width:100%;border-collapse:collapse;margin-top:16px}
        .table th, .table td{padding:12px;text-align:right;border-bottom:1px solid #eee}
        .table th{background:#f5f5f5}
        .actions{display:flex;gap:8px;justify-content:flex-end}
        .alert{padding:12px;border-radius:8px;margin-bottom:16px}
        .alert-success{background:#e6f4ea;color:#1e7e34}
        .alert-danger{background:#fdecea;color:#b00020}
        .pagination{display:flex;justify-content:center;margin-top:16px;gap:8px}
        .pagination a{padding:8px 12px;border-radius:4px;background:#fff;color:#1a73e8;text-decoration:none;box-shadow:0 2px 4px rgba(0,0,0,.1)}
        .pagination a.active{background:#1a73e8;color:#fff}
        .header-actions{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px}
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="header-actions">
                <h2>إدارة الأسئلة</h2>
                <a href="{{ route('questions.create') }}"><button class="btn-success">إضافة سؤال جديد</button></a>
            </div>

            @if(session('success'))
                <div class="alert alert-success">{{ session('success') }}</div>
            @endif

            <table class="table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>السوال</th>
                        <th>الجواب</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($questions as $question)
                        <tr>
                            <td>{{ $question->id }}</td>
                            <td>{{ $question->content }}</td>
                            <td>{{ $question->answer }}</td>
                            <td class="actions">
                                <a href="{{ route('questions.show', $question) }}"><button class="btn-primary">عرض</button></a>
                                <a href="{{ route('questions.edit', $question) }}"><button class="btn-warning">تعديل</button></a>
                                <form action="{{ route('questions.destroy', $question) }}" method="POST" onsubmit="return confirm('هل أنت متأكد من حذف هذا السؤال؟');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn-danger">حذف</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" style="text-align:center">لا توجد أسئلة مضافة</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            <div style="margin-top:20px; text-align:center">
                <a href="{{ url('/') }}"><button class="btn-primary">العودة للصفحة الرئيسية</button></a>
            </div>
        </div>
    </div>
</body>
</html>
