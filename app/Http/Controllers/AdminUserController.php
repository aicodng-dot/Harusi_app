<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class AdminUserController extends Controller
{
    public function index(): View
    {
        return view('admin.users.index', [
            'users' => User::query()
                ->with('event')
                ->latest()
                ->paginate(15),
        ]);
    }

    public function create(): View
    {
        return view('admin.users.create', [
            'userRecord' => new User([
                'role' => User::ROLE_SCANNER,
            ]),
            'activeEvents' => $this->activeEvents(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateUser($request);

        User::query()->create($validated);

        return redirect()
            ->route('admin.users.index')
            ->with('success', 'User created.');
    }

    public function edit(User $user): View
    {
        return view('admin.users.edit', [
            'userRecord' => $user,
            'activeEvents' => $this->activeEvents(),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $this->validateUser($request, $user);

        if (! array_key_exists('password', $validated)) {
            unset($validated['password']);
        }

        $user->update($validated);

        return redirect()
            ->route('admin.users.index')
            ->with('success', 'User updated.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($request->user()?->is($user)) {
            return back()->withErrors(['user' => 'You cannot delete your own admin account.']);
        }

        $user->delete();

        return redirect()
            ->route('admin.users.index')
            ->with('success', 'User deleted.');
    }

    private function validateUser(Request $request, ?User $user = null): array
    {
        $passwordRules = $user
            ? ['nullable', 'confirmed', Password::min(8)]
            : ['required', 'confirmed', Password::min(8)];

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => [
                'required',
                'email',
                'max:160',
                Rule::unique('users', 'email')->ignore($user?->id),
            ],
            'password' => $passwordRules,
            'role' => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_SCANNER])],
            'event_id' => ['nullable', 'integer', Rule::exists('events', 'id')->where('status', Event::STATUS_ACTIVE)],
            'gate_name' => ['nullable', 'string', 'max:120', 'required_if:role,'.User::ROLE_SCANNER],
        ], [
            'gate_name.required_if' => 'Assign a gate name for scanner users.',
        ]);

        if (($validated['role'] ?? null) !== User::ROLE_SCANNER) {
            $validated['gate_name'] = null;
            $validated['event_id'] = null;
        } elseif (empty($validated['event_id'])) {
            $validated['event_id'] = Event::defaultEvent()->id;
        }

        if (($validated['password'] ?? '') === '') {
            unset($validated['password']);
        }

        return $validated;
    }

    private function activeEvents()
    {
        return Event::query()
            ->where('status', Event::STATUS_ACTIVE)
            ->orderBy('event_date')
            ->orderBy('event_name')
            ->get();
    }
}
