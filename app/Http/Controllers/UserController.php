<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;

class UserController extends Controller {
    
    public function me(Request $r) {
        return response()->json($r->user());
    }
}
