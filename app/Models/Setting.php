<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class Setting extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'value',
        'group',
        'display_name',
        'description'
    ];

    /**
     * الحصول على قيمة إعداد معين
     */
    public static function get(string $key, $default = null)
    {
        return Cache::remember('setting_'.$key, 60, function () use ($key, $default) {
            if (!Schema::hasTable('settings')) {
                return $default;
            }

            $setting = self::where('key', $key)->first();
            return $setting ? $setting->value : $default;
        });
    }

    /**
     * تعيين قيمة إعداد معين
     */
    public static function set(string $key, $value)
    {
        if (!Schema::hasTable('settings')) {
            return false;
        }

        $setting = self::where('key', $key)->first();

        if (!$setting) {
            $setting = new self();
            $setting->key = $key;
            $setting->group = 'general';
        }

        $setting->value = $value;
        $setting->save();

        Cache::forget('setting_'.$key);

        return true;
    }

    /**
     * الحصول على جميع الإعدادات ضمن مجموعة معينة
     */
    public static function getGroup(string $group = 'general')
    {
        if (!Schema::hasTable('settings')) {
            return collect([]);
        }

        return self::where('group', $group)->get();
    }
}
