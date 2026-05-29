@extends('layouts.admin', ['title' => 'Scanner Users', 'headerTitle' => 'Scanner Users'])

@section('content')
    @php
        $roleBadge = fn (string $role) => match ($role) {
            \App\Models\User::ROLE_ADMIN => 'bg-zinc-950 text-white',
            \App\Models\User::ROLE_SCANNER => 'bg-emerald-100 text-emerald-800',
            default => 'bg-zinc-100 text-zinc-700',
        };

        $gateBadge = fn (?string $gateName) => $gateName
            ? 'bg-sky-100 text-sky-800'
            : 'bg-amber-100 text-amber-800';
    @endphp

    <section class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="section-kicker">Access control</p>
                <h2 class="mt-2 text-2xl font-semibold sm:text-3xl">Scanner user management</h2>
            </div>
            <a href="{{ route('admin.users.create') }}" class="primary-button">Add user</a>
        </div>

        @if (session('success'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-800">
                {{ session('success') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-bold text-rose-800">
                {{ $errors->first() }}
            </div>
        @endif

        <div class="app-card overflow-hidden">
            <div class="border-b border-stone-200 bg-white px-5 py-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 class="text-base font-bold text-zinc-950">System users</h3>
                        <p class="mt-1 text-sm font-semibold text-zinc-500">Create scanner logins, assign gates, and reset passwords.</p>
                    </div>
                    <span class="status-badge bg-stone-100 text-zinc-700">{{ $users->total() }} users</span>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-stone-200 text-sm">
                    <thead class="bg-stone-50 text-left text-xs font-bold uppercase text-zinc-500">
                        <tr>
                            <th class="px-5 py-3">Name</th>
                            <th class="px-5 py-3">Email</th>
                            <th class="px-5 py-3">Role</th>
                            <th class="px-5 py-3">Gate name</th>
                            <th class="px-5 py-3">Status</th>
                            <th class="px-5 py-3">Created</th>
                            <th class="px-5 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @forelse ($users as $userRecord)
                            <tr>
                                <td class="px-5 py-4 font-semibold text-zinc-950">{{ $userRecord->name }}</td>
                                <td class="px-5 py-4">{{ $userRecord->email }}</td>
                                <td class="px-5 py-4">
                                    <span class="status-badge {{ $roleBadge($userRecord->role) }}">{{ $userRecord->role }}</span>
                                </td>
                                <td class="px-5 py-4">
                                    <span class="status-badge {{ $gateBadge($userRecord->gate_name) }}">
                                        {{ $userRecord->gate_name ?: 'No gate' }}
                                    </span>
                                </td>
                                <td class="px-5 py-4">
                                    <span class="status-badge bg-emerald-100 text-emerald-800">Active</span>
                                </td>
                                <td class="px-5 py-4 font-semibold text-zinc-600">{{ $userRecord->created_at?->format('M j, Y') }}</td>
                                <td class="px-5 py-4">
                                    <div class="flex flex-wrap justify-end gap-2">
                                        <a href="{{ route('admin.users.edit', $userRecord) }}" class="table-action">Edit</a>

                                        @if (auth()->id() === $userRecord->id)
                                            <span class="table-action bg-stone-100 text-zinc-500">Current admin</span>
                                        @else
                                            <form method="POST" action="{{ route('admin.users.destroy', $userRecord) }}" data-confirm data-confirm-title="Delete user account" data-confirm-message="This user will lose access immediately. This cannot be undone.">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="table-action table-action-danger">Delete</button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-5 py-12 text-center text-sm font-semibold text-zinc-500">No scanner users have been created yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($users->hasPages())
                <div class="border-t border-stone-200 px-5 py-4">
                    {{ $users->links() }}
                </div>
            @endif
        </div>
    </section>
@endsection
