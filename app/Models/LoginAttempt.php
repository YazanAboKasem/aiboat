<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoginAttempt extends Model
{
    use HasFactory;

    /**
     * الخصائص التي يمكن تعيينها بشكل جماعي
     *
     * @var array
     */
    protected $fillable = [
        'email',
        'ip_address',
        'user_agent',
        'successful',
        'user_id'
    ];

    /**
     * العلاقة مع نموذج المستخدم
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
