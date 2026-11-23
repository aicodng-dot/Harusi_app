<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\PhoneNumberService;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RegistrationController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        if ($request->user() !== null) {
            return redirect()->route('login');
        }

        return view('reference_ui.02_register_account', ['language' => $this->language($request)]);
    }

    public function store(Request $request, PhoneNumberService $phones): RedirectResponse
    {
        if ($request->user() !== null) {
            return redirect()->route('login');
        }

        $language = $this->language($request);
        if (is_string($request->input('email'))) {
            $request->merge(['email' => Str::lower(trim($request->input('email')))]);
        }
        if (is_string($request->input('phone_number'))) {
            $phone = trim($request->input('phone_number'));
            $request->merge(['phone_number' => $phones->normalize($phone) ?? $phone]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/u'],
            'email' => [
                'bail', 'required', 'string', 'email', 'max:160',
                function (string $attribute, mixed $value, \Closure $fail) use ($language): void {
                    if (User::query()->useWritePdo()->whereRaw('LOWER(email) = ?', [$value])->exists()) {
                        $fail(__('register.identity_exists', [], $language));
                    }
                },
            ],
            'phone_number' => [
                'bail', 'required', 'string', 'max:40',
                function (string $attribute, mixed $value, \Closure $fail) use ($phones, $language): void {
                    if ($phones->normalize($value) === null) {
                        $fail(__('register.phone_error', [], $language));
                    }
                },
                Rule::unique('users', 'phone_number'),
            ],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
            'preferred_language' => ['required', 'string', Rule::in(User::SUPPORTED_LANGUAGES)],
            'terms' => ['accepted'],
        ], [
            'name.*' => __('register.name_error', [], $language),
            // Explicit keys (not "email.*") so the duplicate-identity closure keeps its own message.
            'email.required' => __('register.email_error', [], $language),
            'email.string' => __('register.email_error', [], $language),
            'email.email' => __('register.email_error', [], $language),
            'email.max' => __('register.email_error', [], $language),
            'phone_number.unique' => __('register.identity_exists', [], $language),
            'phone_number.*' => __('register.phone_error', [], $language),
            'password.confirmed' => __('register.password_mismatch', [], $language),
            'password.*' => __('register.password_error', [], $language),
            'preferred_language.*' => __('register.language_error', [], $language),
            'terms.*' => __('register.terms_required', [], $language),
        ]);

        try {
            // Whitelist only profile fields. Public signup never grants staff,
            // platform administration, event assignments, or workspace access.
            User::query()->create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone_number' => $validated['phone_number'],
                'password' => $validated['password'],
                'preferred_language' => $validated['preferred_language'],
                'role' => User::ROLE_CUSTOMER,
                'is_active' => true,
                'event_id' => null,
                'gate_name' => null,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A competing request can claim the identity after validation.
            throw ValidationException::withMessages([
                'email' => __('register.identity_exists', [], $language),
            ]);
        } catch (QueryException $exception) {
            // Any other storage failure: log it, never show SQL, and let the visitor retry.
            report($exception);

            throw ValidationException::withMessages([
                'form' => __('register.unexpected_error', [], $language),
            ]);
        }

        return redirect()->route('login')->with('status', __('register.created', [], $language));
    }

    private function language(Request $request): string
    {
        $requested = $request->query('lang');
        if (in_array($requested, User::SUPPORTED_LANGUAGES, true)) {
            $request->session()->put('login_language', $requested);
        }

        $language = $request->session()->get('login_language', 'sw');

        return in_array($language, User::SUPPORTED_LANGUAGES, true) ? $language : 'sw';
    }
}
