<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KbItem extends Model
{
    protected $table = 'kb_items';
    protected $fillable = ['source','content'];
    public function vector(){ return $this->hasOne(KbVector::class); }
}
