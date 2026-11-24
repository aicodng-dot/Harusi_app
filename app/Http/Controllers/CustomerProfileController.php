<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\PhoneNumberService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * My Profile for a signed-in customer: view and edit their own details only. The account
 * is always the authenticated user (no id in the URL). Changing the email or password
 * requires the current password.
 */
class CustomerProfileController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $this->customer($request);

        return $this->page($request, $user);
    }

    /** Help & Support: a static guide to the customer workflow (no data is shown). */
    public function help(Request $request): Response
    {
        $user = $this->customer($request);

        return response()->view('reference_ui.customer_help', [
            'language' => CustomerHomeController::language($request, $user),
        ])->withHeaders(['Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer']);
    }

    public function update(Request $request, PhoneNumberService $phones): RedirectResponse
    {
        $user = $this->customer($request);
        $language = CustomerHomeController::language($request, $user);
        $t = fn (string $key) => __('customer_manage.profile.'.$key, [], $language);
        if (is_string($request->input('email'))) {
            $request->merge(['email' => Str::lower(trim($request->input('email')))]);
        }
        if (is_string($request->input('phone_number'))) {
            $phone = trim($request->input('phone_number'));
            $request->merge(['phone_number' => $phones->normalize($phone) ?? $phone]);
        }
        $validated = $request->validateWithBag('profile', [
            'name' => ['required', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'email' => ['required', 'string', 'email', 'max:160', Rule::unique('users', 'email')->ignore($user->id)],
            'phone_number' => ['required', 'string', 'max:40',
                function (string $attribute, mixed $value, \Closure $fail) use ($phones, $t): void {
                    if ($phones->normalize($value) === null) {
                        $fail($t('phone_invalid'));
                    }
                },
                Rule::unique('users', 'phone_number')->ignore($user->id)],
            'preferred_language' => ['required', Rule::in(User::SUPPORTED_LANGUAGES)],
            'current_password' => [Rule::requiredIf(fn () => $request->input('email') !== $user->email), 'nullable', 'string'],
        ], [
            'current_password.required' => $t('current_required'),
            'email.unique' => $t('identity_taken'),
            'phone_number.unique' => $t('identity_taken'),
        ]);
        if ($validated['email'] !== $user->email && ! Hash::check((string) $validated['current_password'], $user->password)) {
            return back()->withErrors(['current_password' => $t('current_wrong')], 'profile')->withInput($request->except('current_password'));
        }

        DB::transaction(function () use ($user, $validated): void {
            $locked = User::query()->useWritePdo()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($locked->email !== $validated['email']) {
                DB::table('password_reset_tokens')->where('email', $locked->email)->delete();
            }
            $locked->fill(collect($validated)->only(['name', 'email', 'phone_number', 'preferred_language'])->all())->save();
        });

        return redirect()->route('customer.profile', ['lang' => $validated['preferred_language']])->with('profile_notice', __('customer_manage.profile.saved', [], $validated['preferred_language']));
    }

    public function password(Request $request): RedirectResponse
    {
        $user = $this->customer($request);
        $language = CustomerHomeController::language($request, $user);
        $t = fn (string $key) => __('customer_manage.profile.'.$key, [], $language);
        $validated = $request->validateWithBag('password', [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::min(8), 'max:255'],
        ], [
            'current_password.required' => $t('current_required'),
            'password.confirmed' => $t('password_mismatch'),
            'password.min' => $t('password_short'),
        ]);
        if (! Hash::check($validated['current_password'], $user->password)) {
            return back()->withErrors(['current_password' => $t('current_wrong')], 'password');
        }

        DB::transaction(function () use ($user, $validated): void {
            $locked = User::query()->useWritePdo()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $locked->password = $validated['password'];
            $locked->setRememberToken(Str::random(60));
            $locked->save();
            $locked->tokens()->delete();
        });
        // Keep this browser signed in; other sessions fail the password-hash check.
        $fresh = $user->fresh();
        Auth::guard('web')->setUser($fresh);
        $request->session()->put('password_hash_web', Auth::guard('web')->hashPasswordForCookie($fresh->getAuthPassword()));
        $request->session()->regenerate();

        return redirect()->route('customer.profile')->with('profile_notice', $t('password_saved'));
    }

    private function page(Request $request, User $user): Response
    {
        $workspaces = DB::table('workspace_users')->where('user_id', $user->id)->count();
        $events = DB::table('events')->whereIn('workspace_id', DB::table('workspace_users')->where('user_id', $user->id)->select('workspace_id'))->count();

        return response()->view('reference_ui.customer_profile', [
            'language' => CustomerHomeController::language($request, $user),
            'user' => $user,
            'workspaceCount' => $workspaces,
            'eventCount' => $events,
        ])->withHeaders(['Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer']);
    }

    private function customer(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasSafeIdentityId() && $user->isCustomer() && $user->canAuthenticate(), 403);

        return $user;
    }
}
