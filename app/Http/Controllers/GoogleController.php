<?php

namespace App\Http\Controllers;

use Laravel\Socialite\Facades\Socialite;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Google\Client; // ✅ Correct namespace for google/apiclient v2.18.3

class GoogleController extends Controller
{
    /**
     * Step 1: Redirect user to Google login
     */
    public function redirectToGoogle()
    {
        // Redirect to Google's OAuth consent screen
        return Socialite::driver('google')->redirect();
    }

    /**
     * Step 2: Handle Google token from frontend
     */
    public function handleGoogleToken(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
        ]);

        try {
            // ✅ FIXED LINE — use new class name "Client", not "Google_Client"
            $client = new Client(['client_id' => env('GOOGLE_CLIENT_ID')]);

            // Verify the ID token from frontend
            $payload = $client->verifyIdToken($request->token);

            if (!$payload) {
                return response()->json(['error' => 'Invalid Google token'], 401);
            }

            // Extract user info
            $email = $payload['email'];
            $name = $payload['name'] ?? 'Google User';
            $avatar = $payload['picture'] ?? null;

            // Create or update user
            $user = User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'email_verified_at' => now(),
                    'password' => bcrypt(Str::random(16)),
                ]
            );

            // Generate Sanctum token
            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'message' => 'Google login successful',
                'access_token' => $token,
                'token_type' => 'Bearer',
                'user' => $user,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Google login failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
