<?php
namespace Database\Seeders;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
class DatabaseSeeder extends Seeder{
 public function run():void{$email=env('ADMIN_EMAIL');$password=env('ADMIN_PASSWORD');if(!$email||!$password)return;$user=User::firstOrNew(['email'=>strtolower($email)]);$user->name=env('ADMIN_NAME','CVPilot Admin');$user->password=Hash::make($password);$user->role='admin';$user->verified_at=now();$user->suspended=false;$user->save();}
}
