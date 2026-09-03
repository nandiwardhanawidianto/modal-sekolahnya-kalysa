<?php
namespace Database\Seeders;
use App\Models\User; use Illuminate\Database\Seeder; use Illuminate\Support\Facades\Hash; use RuntimeException;
class InitialUsersSeeder extends Seeder
{
 public function run(): void {
  $password=(string)env('INITIAL_LOGIN_PASSWORD','');
  if($password==='') throw new RuntimeException('Isi INITIAL_LOGIN_PASSWORD di .env terlebih dahulu.');
  foreach([['username'=>'mia','name'=>'Mia'],['username'=>'nandi','name'=>'Nandi']] as $u)User::updateOrCreate(['username'=>$u['username']],['name'=>$u['name'],'password'=>Hash::make($password)]);
 }
}
