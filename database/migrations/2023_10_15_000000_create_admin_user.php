<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class CreateAdminUser extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // إذا لم يكن هناك مستخدم admin، قم بإنشائه
        $admin = User::where('email', 'admin@example.com')->first();

        if (!$admin) {
            User::create([
                'name' => 'Admin',
                'email' => 'admin@example.com',
                'password' => Hash::make('123QWEDSJR$#'),
                'email_verified_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // حذف المستخدم admin عند التراجع
        User::where('email', 'admin@example.com')->delete();
    }
}
