@extends('layouts.admin')

@section('title', 'Signup attempts')
@section('page-title', 'WhatsApp wallet — Signup attempts')

@section('content')
<div class="space-y-6">
    @include('admin.whatsapp-wallet.partials.nav')

    @if(session('success'))
        <div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg text-sm">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg text-sm">{{ session('error') }}</div>
    @endif

    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
        <div>
            <h3 class="text-lg font-semibold text-gray-900">Signup attempts</h3>
            <p class="text-sm text-gray-600 mt-1">
                Incomplete Xtrapay app registrations (not a wallet yet). If resend “works” but no email arrives,
                they are usually on the <span class="font-medium">5-minute email OTP hold</span> — clear it below.
            </p>
        </div>
        <form method="POST" action="{{ route('admin.whatsapp-wallet.signup-attempts.email-hold.clear') }}"
              class="flex flex-col sm:flex-row gap-2 sm:items-end shrink-0">
            @csrf
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Clear hold by email</label>
                <input type="email" name="email" required placeholder="user@email.com"
                       value="{{ old('email') }}"
                       class="w-full sm:w-56 px-3 py-2 border border-gray-300 rounded-lg text-sm">
            </div>
            <button type="submit"
                    class="bg-amber-600 hover:bg-amber-700 text-white px-3 py-2 rounded-lg text-sm whitespace-nowrap"
                    onclick="return confirm('Clear email OTP hold for this address so they can get a new code?')">
                Clear email hold
            </button>
        </form>
    </div>

    @php $signupAttempts = $signupAttempts ?? []; @endphp
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
        @if(count($signupAttempts) === 0)
            <p class="px-4 py-10 text-center text-sm text-gray-500">No open signup attempts in cache right now.</p>
        @else
            <div class="hidden lg:block overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Name / contact</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Started</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Why stuck</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Email hold</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-600"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($signupAttempts as $attempt)
                            <tr class="{{ !empty($attempt['is_stuck']) ? 'bg-amber-50/40' : '' }} hover:bg-gray-50">
                                <td class="px-4 py-3">
                                    <div class="font-medium text-gray-900">{{ $attempt['full_name'] !== '' ? $attempt['full_name'] : '—' }}</div>
                                    <div class="text-xs text-gray-600 font-mono mt-0.5">{{ $attempt['email'] }}</div>
                                    <div class="text-xs text-gray-500 font-mono">{{ $attempt['phone_e164'] ?: $attempt['phone'] }}</div>
                                    @if(!empty($attempt['wallet_id']))
                                        <a href="{{ route('admin.whatsapp-wallet.wallets.show', $attempt['wallet_id']) }}"
                                           class="text-xs text-primary hover:underline">Wallet #{{ $attempt['wallet_id'] }}</a>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-xs text-gray-600 whitespace-nowrap">
                                    {{ $attempt['started_at'] ?? '—' }}
                                </td>
                                <td class="px-4 py-3 text-xs text-gray-700">
                                    <ul class="list-disc pl-4 space-y-1">
                                        @foreach(($attempt['stuck_reasons'] ?? []) as $reason)
                                            <li>{{ $reason }}</li>
                                        @endforeach
                                    </ul>
                                </td>
                                <td class="px-4 py-3 text-xs whitespace-nowrap">
                                    @if(!empty($attempt['email_hold']['held']))
                                        <span class="text-amber-800 font-medium">Held</span>
                                        <div class="text-gray-500">{{ $attempt['email_hold']['seconds_remaining'] ?? 0 }}s left</div>
                                    @else
                                        <span class="text-green-700 font-medium">Clear</span>
                                    @endif
                                    <div class="text-gray-500 mt-0.5">
                                        OTP {{ !empty($attempt['has_register_otp']) ? 'in cache' : 'missing' }}
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    @if($attempt['email'] !== '')
                                        <form method="POST" action="{{ route('admin.whatsapp-wallet.signup-attempts.email-hold.clear') }}">
                                            @csrf
                                            <input type="hidden" name="email" value="{{ $attempt['email'] }}">
                                            <button type="submit"
                                                    class="text-sm {{ !empty($attempt['email_hold']['held']) ? 'text-amber-700 font-medium' : 'text-gray-600' }} hover:underline"
                                                    onclick="return confirm('Clear email OTP hold for {{ $attempt['email'] }}?')">
                                                Clear hold
                                            </button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="lg:hidden divide-y divide-gray-100">
                @foreach($signupAttempts as $attempt)
                    <div class="px-4 py-3 space-y-2 {{ !empty($attempt['is_stuck']) ? 'bg-amber-50/40' : '' }}">
                        <div>
                            <p class="font-medium text-gray-900 text-sm">{{ $attempt['full_name'] !== '' ? $attempt['full_name'] : '—' }}</p>
                            <p class="text-xs font-mono text-gray-600">{{ $attempt['email'] }}</p>
                            <p class="text-xs font-mono text-gray-500">{{ $attempt['phone_e164'] ?: $attempt['phone'] }}</p>
                        </div>
                        <ul class="text-xs text-gray-700 list-disc pl-4 space-y-1">
                            @foreach(($attempt['stuck_reasons'] ?? []) as $reason)
                                <li>{{ $reason }}</li>
                            @endforeach
                        </ul>
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-xs {{ !empty($attempt['email_hold']['held']) ? 'text-amber-800 font-medium' : 'text-green-700' }}">
                                @if(!empty($attempt['email_hold']['held']))
                                    Hold {{ $attempt['email_hold']['seconds_remaining'] ?? 0 }}s
                                @else
                                    No hold
                                @endif
                            </span>
                            @if($attempt['email'] !== '')
                                <form method="POST" action="{{ route('admin.whatsapp-wallet.signup-attempts.email-hold.clear') }}">
                                    @csrf
                                    <input type="hidden" name="email" value="{{ $attempt['email'] }}">
                                    <button type="submit" class="text-sm text-amber-700 font-medium"
                                            onclick="return confirm('Clear email OTP hold for {{ $attempt['email'] }}?')">
                                        Clear hold
                                    </button>
                                </form>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
@endsection
