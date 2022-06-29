<?php

use App\Http\Controllers\FirebaseController;
use App\Http\Controllers\JarakController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::group(['middleware' => ['token']], function () {

    Route::get('/', function (Request $request) {
        // return $request->user(); // this will return the user data
        return response()->json($request->get('user'));
    });

    Route::post('/searchAngkot', [FirebaseController::class, 'searchAngkot']);
    Route::post("/setLocation", [FirebaseController::class, 'setLocation']);
});

route::get('/cobainfirebase', [FirebaseController::class, 'index']);



// Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
//     return $request->user();
// });
