<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KbVector extends Model
{
    protected $table = 'kb_vectors';
    protected $fillable = ['kb_item_id','embedding'];
    protected $casts = ['embedding' => 'array'];

    public function kbItem(){ return $this->belongsTo(KbItem::class); }
}
