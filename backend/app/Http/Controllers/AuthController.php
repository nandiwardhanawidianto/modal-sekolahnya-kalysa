<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request; use Illuminate\Support\Facades\Auth; use Illuminate\Support\Facades\Hash; use Illuminate\Validation\ValidationException;
class AuthController extends Controller
{
 public function csrf(Request $request){return response()->json(['token'=>csrf_token()]);}
 public function me(Request $request){return response()->json(['user'=>$request->user()?->only(['id','username','name'])]);}
 public function login(Request $request){$data=$request->validate(['username'=>'required|string|max:50','password'=>'required|string|max:255']);if(!Auth::attempt(['username'=>mb_strtolower(trim($data['username'])),'password'=>$data['password']],true))throw ValidationException::withMessages(['username'=>'Username atau password salah.']);$request->session()->regenerate();return response()->json(['user'=>$request->user()->only(['id','username','name'])]);}
 public function changePassword(Request $request){$d=$request->validate(['current_password'=>'required|string','password'=>'required|string|min:10|confirmed']);if(!Hash::check($d['current_password'],$request->user()->password))throw ValidationException::withMessages(['current_password'=>'Password sekarang salah.']);$request->user()->update(['password'=>$d['password']]);return response()->json(['ok'=>true]);}
 public function logout(Request $request){Auth::logout();$request->session()->invalidate();$request->session()->regenerateToken();return response()->json(['ok'=>true]);}
}
