<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\User;
use App\Services\PhoneNumberService;
use App\Services\Tenancy\LegacyEventResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminUserController extends Controller
{
    public function index(): View
    {
        return view('admin.users.index', [
            'users' => $this->legacyUsers()
                ->with(['event' => fn ($query) => $query->useWritePdo()->whereBetween('id', [1, PHP_INT_MAX])
                    ->with(['workspace' => fn ($workspace) => $workspace->useWritePdo()->select(['id', 'name'])])])
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

        (new User)->getConnection()->transaction(function () use ($validated): void {
            $this->lockParents(null, $validated['event_id']);
            // A scanner always has an explicit eligible parent here, so the
            // model's historical default-event creating hook cannot run.
            User::query()->create($validated);
        });

        return redirect()
            ->route('admin.users.index')
            ->with('success', 'User created.');
    }

    public function edit(User $user): View
    {
        $user = $this->legacyUser($user);

        return view('admin.users.edit', [
            'userRecord' => $user,
            'activeEvents' => $this->activeEvents(),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $user = $this->legacyUser($user);
        $oldEventId = $this->assignment($user);
        $validated = $this->validateUser($request, $user);

        $user->getConnection()->transaction(function () use ($user, $oldEventId, $validated): void {
            // Offline package generation also locks Event before User. Never
            // discover and acquire another parent after taking the User lock.
            $this->lockParents($oldEventId, $validated['event_id']);
            $locked = $this->lockUser($user, $oldEventId);

            if (isset($validated['password'])) {
                $validated['password'] = Hash::make($validated['password']);
                $locked->setRememberToken(Str::random(60));
            }

            $locked->fill($validated);
            $revoke = $locked->isDirty(['password', 'is_active', 'role', 'event_id', 'gate_name']);
            $locked->save();

            if ($revoke) {
                $locked->tokens()->delete();
            }
        });

        return redirect()
            ->route('admin.users.index')
            ->with('success', 'User updated.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $user = $this->legacyUser($user);
        $oldEventId = $this->assignment($user);

        if ($request->user()?->is($user)) {
            return back()->withErrors(['user' => 'You cannot delete your own admin account.']);
        }

        $user->getConnection()->transaction(function () use ($user, $oldEventId): void {
            $this->lockParents($oldEventId, null);
            $locked = $this->lockUser($user, $oldEventId);
            $locked->tokens()->delete();
            $locked->delete();
        });

        return redirect()
            ->route('admin.users.index')
            ->with('success', 'User deleted.');
    }

    private function legacyUsers(): Builder
    {
        return User::query()->useWritePdo()->whereBetween('users.id', [1, PHP_INT_MAX])
            ->whereIn('users.role', [User::ROLE_ADMIN, User::ROLE_SCANNER])
            ->whereRaw('HEX(users.role) IN (?, ?)', [strtoupper(bin2hex(User::ROLE_ADMIN)), strtoupper(bin2hex(User::ROLE_SCANNER))])
            ->where('users.is_platform_admin', false)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('workspace_users')
                ->whereColumn('workspace_users.user_id', 'users.id'))
            ->where(function ($query): void {
                $query->whereNull('users.event_id')->orWhere(function ($query): void {
                    // P4-048: staff scanners may serve an admin (legacy) Event or a customer Event.
                    $query->whereBetween('users.event_id', [1, PHP_INT_MAX])
                        ->whereExists(fn ($events) => $events->selectRaw('1')->from('events')
                            ->whereColumn('events.id', 'users.event_id')
                            ->whereBetween('events.id', [1, PHP_INT_MAX]));
                });
            });
    }

    private function legacyUser(User $user): User
    {
        abort_unless($user->hasSafeIdentityId(), 404);
        $id = $user->getKey();
        $fresh = $this->legacyUsers()->whereKey($id)->firstOrFail();
        $this->checkIdentity($fresh, $id);
        $this->assignment($fresh);

        return $fresh;
    }

    private function checkIdentity(User $user, int $id): void
    {
        abort_unless($this->canonicalId($user->getRawOriginal($user->getKeyName())) === $id
            && in_array($user->getRawOriginal('role'), [User::ROLE_ADMIN, User::ROLE_SCANNER], true)
            && in_array($user->getRawOriginal('is_platform_admin'), [false, 0, '0'], true), 404);
    }

    private function assignment(User $user): ?int
    {
        $raw = $user->getRawOriginal('event_id');
        if ($raw === null) {
            return null;
        }
        $id = $this->canonicalId($raw);
        abort_if($id === null, 404);

        return $id;
    }

    private function lockParents(?int $oldEventId, ?int $newEventId): void
    {
        $ids = array_values(array_unique(array_filter([$oldEventId, $newEventId], static fn ($id) => $id !== null)));
        usort($ids, static fn (int $left, int $right): int => $left <=> $right);
        foreach ($ids as $id) {
            $event = Event::query()->useWritePdo()->whereKey($id)->lockForUpdate()->first();
            $safe = $event !== null
                && $this->canonicalId($event->getRawOriginal($event->getKeyName())) === $id;
            if (! $safe) {
                abort_if($id === $oldEventId, 404);
                $this->invalidEvent();
            }
            if ($id === $newEventId && $event->getRawOriginal('status') !== Event::STATUS_ACTIVE) {
                $this->invalidEvent();
            }
        }
    }

    private function lockUser(User $user, ?int $oldEventId): User
    {
        $locked = User::query()->useWritePdo()->whereKey($user->getKey())
            ->whereIn('role', [User::ROLE_ADMIN, User::ROLE_SCANNER])->lockForUpdate()->firstOrFail();
        $this->checkIdentity($locked, $user->getKey());
        abort_unless($this->assignment($locked) === $oldEventId, 404);
        // A current locking read is required even inside a caller's existing
        // repeatable-read transaction. All compliant membership writers first
        // lock this User row; do not replace this with a snapshot exists().
        $membership = $locked->getConnection()->table('workspace_users')->select('id')
            ->where('user_id', $locked->getKey())->orderBy('id')->lockForUpdate()->first();
        abort_if($membership !== null, 404);

        return $locked;
    }

    private function validateUser(Request $request, ?User $user = null): array
    {
        $phones = app(PhoneNumberService::class);

        if ($request->exists('phone_number') && is_string($request->input('phone_number'))) {
            $phoneInput = trim($request->input('phone_number'));
            $request->merge([
                'phone_number' => $phoneInput === '' ? null : ($phones->normalize($phoneInput) ?? $phoneInput),
            ]);
        }

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
            'phone_number' => [
                'bail', 'sometimes', 'nullable', 'string', 'max:40',
                function (string $attribute, mixed $value, \Closure $fail) use ($phones): void {
                    if ($phones->normalize($value) === null) {
                        $fail(PhoneNumberService::INVALID_MESSAGE);
                    }
                },
                Rule::unique('users', 'phone_number')->ignore($user?->id),
            ],
            'is_active' => ['sometimes', 'boolean'],
            'preferred_language' => ['sometimes', 'required', Rule::in(User::SUPPORTED_LANGUAGES)],
            'role' => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_SCANNER])],
            'event_id' => ['nullable', function (string $attribute, mixed $value, \Closure $fail): void {
                // Admin (legacy) or customer Event; it must exist and be active.
                $id = $this->canonicalId($value);
                $event = $id === null ? null : Event::query()->useWritePdo()->whereKey($id)->first();
                if ($event === null || $event->getRawOriginal('status') !== Event::STATUS_ACTIVE) {
                    $fail('Choose an active event.');
                }
            }],
            'gate_name' => ['nullable', 'string', 'max:120', 'required_if:role,'.User::ROLE_SCANNER],
        ], [
            'gate_name.required_if' => 'Assign a gate name for scanner users.',
        ]);

        if ($user !== null && $request->user()?->is($user)
            && array_key_exists('is_active', $validated) && ! (bool) $validated['is_active']) {
            throw ValidationException::withMessages([
                'is_active' => 'You cannot deactivate your own administrator account.',
            ]);
        }

        if ($validated['role'] !== User::ROLE_SCANNER) {
            $validated['gate_name'] = null;
            $validated['event_id'] = null;
        } else {
            $id = $this->canonicalId($validated['event_id'] ?? null);
            if ($id === null) {
                $id = $this->canonicalId(app(LegacyEventResolver::class)->soleActive()?->getRawOriginal('id'));
            }
            if ($id === null) {
                $this->invalidEvent();
            }
            $validated['event_id'] = $id;
        }

        if (($validated['password'] ?? '') === '') {
            unset($validated['password']);
        }

        return $validated;
    }

    private function invalidEvent(): never
    {
        throw ValidationException::withMessages(['event_id' => 'Choose an active event.']);
    }

    private function canonicalId(mixed $id): ?int
    {
        if (is_int($id)) {
            return $id > 0 ? $id : null;
        }
        if (! is_string($id) || preg_match('/\A[1-9][0-9]*\z/', $id) !== 1) {
            return null;
        }
        $parsed = filter_var($id, FILTER_VALIDATE_INT);

        return $parsed === false ? null : $parsed;
    }

    private function activeEvents()
    {
        // Admin events first, then customer events (labelled with their workspace in the form).
        return Event::query()->useWritePdo()->whereBetween('id', [1, PHP_INT_MAX])
            ->where('status', Event::STATUS_ACTIVE)->whereRaw('HEX(status) = ?', [strtoupper(bin2hex(Event::STATUS_ACTIVE))])
            ->with(['workspace' => fn ($workspace) => $workspace->useWritePdo()->select(['id', 'name'])])
            ->orderByRaw('CASE WHEN workspace_id IS NULL THEN 0 ELSE 1 END')
            ->orderBy('event_date')
            ->orderBy('event_name')
            ->get();
    }
}
