<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A member edits their own name only. The email (login identity), the account
 * itself and its deletion belong to the admin's team screens.
 */
class ProfileController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('settings/Profile');
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);

        $request->user()->forceFill(['name' => $data['name']])->save();

        return to_route('profile.edit');
    }
}
