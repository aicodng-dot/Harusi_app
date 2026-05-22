@extends('layouts.admin', ['title' => 'Scanner Users'])

@section('content')
    <section class="space-y-6">
        <div>
            <p class="section-kicker">Admin panel</p>
            <h2 class="mt-2 text-2xl font-semibold sm:text-3xl">Scanner users</h2>
        </div>

        <div class="app-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-stone-200 text-sm">
                    <thead class="bg-stone-50 text-left text-xs font-bold uppercase text-zinc-500">
                        <tr>
                            <th class="px-5 py-3">Name</th>
                            <th class="px-5 py-3">Email</th>
                            <th class="px-5 py-3">Gate</th>
                            <th class="px-5 py-3">Created</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @forelse ($scannerUsers as $scanner)
                            <tr>
                                <td class="px-5 py-4 font-semibold">{{ $scanner->name }}</td>
                                <td class="px-5 py-4">{{ $scanner->email }}</td>
                                <td class="px-5 py-4">{{ $scanner->gate_name ?? 'Unassigned' }}</td>
                                <td class="px-5 py-4">{{ $scanner->created_at?->format('M j, Y') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-5 py-12 text-center text-sm font-semibold text-zinc-500">No scanner users yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>
@endsection
