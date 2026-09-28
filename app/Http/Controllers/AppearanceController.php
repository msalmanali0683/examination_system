<?php

namespace App\Http\Controllers;

use App\Support\Themes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AppearanceController extends Controller
{
    /**
     * Saves the signed-in user's colour theme and light/dark choice so it
     * follows them to every device. The picker has already applied the
     * change in the browser by the time this is called.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'theme' => ['required', 'string', Rule::in(Themes::keys())],
            'appearance' => ['required', 'string', Rule::in(Themes::appearances())],
        ]);

        $request->user()->forceFill($validated)->save();

        return response()->json($validated);
    }
}
