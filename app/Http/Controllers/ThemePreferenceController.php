<?php

namespace App\Http\Controllers;

// ─── ThemePreferenceController ────────────────────────────────────────────────
// Persists the authenticated user's display theme preference (light/dark).
//
// Route: POST /user/theme (inside 'auth' middleware group)
// Payload: { theme: 'light' | 'dark' }
// Response: { theme: 'light' | 'dark' }
//
// The frontend (AppShell.tsx) also mirrors the value to localStorage for
// FOUC prevention. The server value is the source of truth across sessions.
// ─────────────────────────────────────────────────────────────────────────────

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ThemePreferenceController extends Controller
{
    /**
     * Update the authenticated user's theme preference.
     * Validates that the value is one of the two allowed enum values.
     */
    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'theme' => ['required', 'string', 'in:light,dark'],
        ]);

        Auth::user()->update(['theme_preference' => $request->theme]);

        return response()->json(['theme' => $request->theme]);
    }
}
