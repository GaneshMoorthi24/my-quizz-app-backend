<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;
use Illuminate\Foundation\Auth\EmailVerificationRequest; // ✅ Add this line
use App\Http\Controllers\GoogleController; // ✅ Add this line
use App\Http\Controllers\Admin\AdminExamController;
use App\Http\Controllers\Admin\AdminPaperController;
use App\Http\Controllers\Admin\AdminQuestionController;
use App\Http\Controllers\Admin\AdminUploadController;
use App\Http\Controllers\Admin\AdminImportController;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\URL;

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



    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    // ✅ Google Sign-in using token (from frontend)
    Route::post('/auth/google', [GoogleController::class, 'handleGoogleToken']);


    Route::get('/email/verify/{id}/{hash}', function (Request $request, $id, $hash) {
        $user = User::find($id);

        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        // Check if the link is still valid (signed)
        if (! URL::hasValidSignature($request)) {
            return response()->json(['message' => 'Invalid or expired verification link.'], 403);
        }

        // Verify hash manually
        if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return response()->json(['message' => 'Invalid verification link.'], 403);
        }

        if ($user->hasVerifiedEmail()) {
            return redirect('http://localhost:3000/verified?status=already');
        }

        // Mark email as verified
        $user->markEmailAsVerified();
        event(new Verified($user));

        return redirect('http://localhost:3000/verified?status=success');
    })->name('verification.verify');


    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/user', [UserController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });

    Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {
        // Exams
        Route::apiResource('exams', AdminExamController::class);

        // Question Papers
        Route::get('exams/{exam}/papers', [AdminPaperController::class, 'index']);
        Route::post('exams/{exam}/papers', [AdminPaperController::class, 'store']);
        Route::apiResource('papers', AdminPaperController::class)->except(['index', 'store']);

        // Questions
        Route::get('papers/{paper}/questions', [AdminQuestionController::class, 'index']);
        Route::post('papers/{paper}/questions', [AdminQuestionController::class, 'store']);
        Route::apiResource('questions', AdminQuestionController::class)->except(['index', 'store']);

        // Upload & Parse PDF
        Route::post('papers/{paper}/upload', [AdminUploadController::class, 'upload']);
        // Route::post('exams/{exam}/papers/upload', [AdminUploadController::class, 'upload']);
        Route::post('papers/{paper}/parse/{upload}', [AdminUploadController::class, 'parse']);
        Route::get('papers/{paper}/parse/{upload}', [AdminUploadController::class, 'getParsed']);
        Route::post('papers/{paper}/parse/{upload}/save', [AdminUploadController::class, 'saveParsed']);

        // Import History
        Route::get('imports', [AdminImportController::class, 'index']);
        Route::get('imports/{import}', [AdminImportController::class, 'show']);
    });
