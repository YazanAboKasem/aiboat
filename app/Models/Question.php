<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Question extends Model
{
    protected $table = 'questions';

    // الحقول المسموح تعبئتها جماعيًا
    protected $fillable = [
        'title',
        'content',
        'answer',
        'intent',              // إن كنت تستخدم النية
        'title_embedding',
        'content_embedding',
        'embedding_quality',
        'keywords',            // جديد: كلمات مفتاحية (JSON array)
        'lex_map',             // جديد: قاموس الاستبدال (JSON object)
    ];

    // تحويل تلقائي للأنواع
    protected $casts = [
        'title_embedding'   => 'array',
        'content_embedding' => 'array',
        'keywords'          => 'array', // ["درون","طائرة بدون طيار", ...]
        'lex_map'           => 'array', // {"عندكن":"يوجد","في":"يوجد", ...}
        'embedding_quality' => 'float',
    ];
}
