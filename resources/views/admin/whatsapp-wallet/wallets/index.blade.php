@extends('layouts.admin')

@section('title', 'Wallet users')
@section('page-title', 'WhatsApp wallet — Users')

@section('content')
<div class="space-y-6">
    @include('admin.whatsapp-wallet.partials.nav')

    @if(session('success'))
        <div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg text-sm">{{ session('success') }}</div>
    @endif

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h3 class="text-lg font-semibold text-gray-900">Wallet users</h3>
            <p class="text-sm text-gray-600 mt-1">Search by phone, pay code, name, or wallet ID.</p>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
        <form method="GET" action="{{ route('admin.whatsapp-wallet.wallets.index') }}" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3">
            <div class="sm:col-span-2">
                <label class="block text-xs font-medium text-gray-700 mb-1">Search</label>
                <input type="text" name="search" value="{{ request('search') }}"
                    placeholder="Phone, pay code, name, VA number…"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Sort</label>
                <select name="sort" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    <option value="newest" @selected($sort === 'newest')>Newest first</option>
                    <option value="oldest" @selected($sort === 'oldest')>Oldest first</option>
                    <option value="most_active" @selected($sort === 'most_active')>Most active</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Status</label>
                <select name="status" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    <option value="">All</option>
                    <option value="active" @selected(request('status') === 'active')>Active</option>
                    <option value="on_hold" @selected(request('status') === 'on_hold')>On hold</option>
                    <option value="suspended" @selected(request('status') === 'suspended')>Suspended</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Flags</label>
                <label class="inline-flex items-center gap-2 text-sm text-gray-700 mt-2">
                    <input type="checkbox" name="pnd" value="1" class="rounded border-gray-300" @checked(request()->boolean('pnd'))>
                    Post no debit
                </label>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Tier</label>
                <select name="tier" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    <option value="">All</option>
                    <option value="1" @selected(request('tier') === '1')>Tier 1</option>
                    <option value="2" @selected(request('tier') === '2')>Tier 2 (VA)</option>
                </select>
            </div>
            <div class="flex items-end gap-2">
                <label class="inline-flex items-center gap-2 text-sm text-gray-700 pb-2">
                    <input type="checkbox" name="needs_setup" value="1" class="rounded border-gray-300" @checked(request()->boolean('needs_setup'))>
                    Needs PIN/name
                </label>
                <label class="inline-flex items-center gap-2 text-sm text-gray-700 pb-2">
                    <input type="checkbox" name="manual_chat" value="1" class="rounded border-gray-300" @checked(request()->boolean('manual_chat'))>
                    Manual chat
                </label>
            </div>
            <div class="flex items-end gap-2 lg:col-span-6">
                <button type="submit" class="bg-primary text-white px-4 py-2 rounded-lg text-sm">Filter</button>
                <a href="{{ route('admin.whatsapp-wallet.wallets.index') }}" class="text-sm text-gray-600 py-2">Reset</a>
            </div>
        </form>
        @if($sort === 'most_active')
            <p class="text-xs text-gray-500 mt-3">Most active = latest wallet transaction (top-ups, transfers, VTU, etc.). Wallets with no transactions appear last.</p>
        @endif
    </div>

    <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
        {{-- Desktop table --}}
        <div class="hidden lg:block overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium text-gray-600">ID</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-600">Phone</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-600">Name</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-600">Balance</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-600" title="Tier 1 outbound send today / daily cap (incoming not counted)">T1 send today</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-600">Tier</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-600">Status</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-600">PIN</th>
                        <th class="px-4 py-3 text-right font-medium text-gray-600"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($wallets as $w)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-gray-500">#{{ $w->id }}</td>
                            <td class="px-4 py-3 font-mono text-gray-900">{{ $w->phone_e164 }}</td>
                            <td class="px-4 py-3 text-gray-700">{{ $w->displayName() ?? '—' }}</td>
                            <td class="px-4 py-3 text-right font-semibold">₦{{ number_format((float) $w->balance, 2) }}</td>
                            <td class="px-4 py-3 text-right text-xs whitespace-nowrap">
                                @if($w->isTier1())
                                    <span class="font-medium text-gray-900">₦{{ number_format($w->tier1DailyOutUsed(), 0) }}</span>
                                    <span class="text-gray-500">/ ₦{{ number_format($w->tier1DailyOutLimit(), 0) }}</span>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @if($w->isTier2())
                                    <span class="text-xs bg-indigo-100 text-indigo-800 px-2 py-0.5 rounded">Tier 2</span>
                                @else
                                    <span class="text-xs bg-gray-100 text-gray-700 px-2 py-0.5 rounded">Tier 1</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @if($w->isActive())
                                    <span class="text-xs bg-green-100 text-green-800 px-2 py-0.5 rounded">Active</span>
                                @else
                                    <span class="text-xs bg-red-100 text-red-800 px-2 py-0.5 rounded">Suspended</span>
                                @endif
                                @if($w->isAdminBotPaused())
                                    <span class="text-xs bg-amber-100 text-amber-900 px-2 py-0.5 rounded ml-1">Manual chat</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-xs text-gray-600">{{ $w->hasPin() ? 'Set' : 'Missing' }}</td>
                            <td class="px-4 py-3 text-right">
                                <a href="{{ route('admin.whatsapp-wallet.wallets.show', $w) }}" class="text-primary hover:underline">Manage</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-4 py-10 text-center text-gray-500">No wallets match your filters.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Mobile list (no table) --}}
        <div class="lg:hidden divide-y divide-gray-100">
            @forelse($wallets as $w)
                <a href="{{ route('admin.whatsapp-wallet.wallets.show', $w) }}" class="flex items-center gap-3 px-4 py-3 active:bg-gray-50">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2 flex-wrap">
                            <p class="font-mono text-sm font-semibold text-gray-900 truncate">{{ $w->phone_e164 }}</p>
                            @if($w->isActive())
                                <span class="text-[10px] bg-green-100 text-green-800 px-1.5 py-0.5 rounded">Active</span>
                            @else
                                <span class="text-[10px] bg-red-100 text-red-800 px-1.5 py-0.5 rounded">Suspended</span>
                            @endif
                            @if($w->isTier2())
                                <span class="text-[10px] bg-indigo-100 text-indigo-800 px-1.5 py-0.5 rounded">T2</span>
                            @endif
                            @if($w->isAdminBotPaused())
                                <span class="text-[10px] bg-amber-100 text-amber-900 px-1.5 py-0.5 rounded">Manual</span>
                            @endif
                        </div>
                        <p class="text-xs text-gray-500 truncate mt-0.5">
                            {{ $w->displayName() ?? 'No name' }}
                            · #{{ $w->id }}
                            · PIN {{ $w->hasPin() ? 'set' : 'missing' }}
                        </p>
                    </div>
                    <div class="text-right shrink-0">
                        <p class="text-sm font-bold text-gray-900">₦{{ number_format((float) $w->balance, 2) }}</p>
                        <i class="fas fa-chevron-right text-gray-300 text-xs"></i>
                    </div>
                </a>
            @empty
                <div class="px-4 py-10 text-center text-sm text-gray-500">No wallets match your filters.</div>
            @endforelse
        </div>

        @if($wallets->hasPages())
            <div class="px-4 py-3 border-t border-gray-200">{{ $wallets->links() }}</div>
        @endif
    </div>
</div>
@endsection
