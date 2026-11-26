<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\ImportRun;
use App\Services\ImportControl;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $request, ImportControl $control): RedirectResponse
    {
        $user = $request->user();

        DB::transaction(function () use ($user, $control): void {
            DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            $runs = ImportRun::query()->where('user_id', $user->id)->get();
            if ($runs->contains(fn (ImportRun $run) => in_array($run->status, ['queued', 'running'], true))) {
                throw ValidationException::withMessages(['password' => 'Stop active imports before deleting your account.']);
            }
            foreach ($runs as $run) {
                if (! Storage::disk('local')->deleteDirectory('imports/'.$run->id)) {
                    throw new RuntimeException('Could not remove retained import files.');
                }
                $control->clearAbort($run->id);
            }
            $user->delete();
        });

        Auth::logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
