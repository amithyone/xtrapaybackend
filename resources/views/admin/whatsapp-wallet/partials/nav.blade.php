@php
    $failedCount = $failedCount ?? \App\Models\WalletTransaction::countFailedBankPayoutsRecent();
    $pendingCount = $pendingCount ?? \App\Models\WalletTransaction::countPendingBankPayoutsRecent();
    $navClass = fn (array $patterns): string => collect($patterns)->contains(fn ($p) => request()->routeIs($p))
        ? 'bg-green-50 text-green-800 border-green-200 font-semibold'
        : 'bg-white text-gray-700 border-gray-200 hover:bg-gray-50';
@endphp
<nav class="bg-white border border-gray-200 rounded-lg shadow-sm p-2 flex gap-2 overflow-x-auto admin-table-scroll whitespace-nowrap -mx-0" aria-label="WhatsApp wallet admin">
    <a href="{{ route('admin.whatsapp-wallet.index') }}"
       class="inline-flex items-center shrink-0 px-3 py-2 rounded-lg border text-sm {{ $navClass(['admin.whatsapp-wallet.index']) }}">
        <i class="fas fa-chart-pie mr-2 text-green-600"></i> Overview
    </a>
    <a href="{{ route('admin.whatsapp-wallet.wallets.index') }}"
       class="inline-flex items-center shrink-0 px-3 py-2 rounded-lg border text-sm {{ $navClass(['admin.whatsapp-wallet.wallets.*']) }}">
        <i class="fas fa-users mr-2 text-green-600"></i> Wallet users
    </a>
    <a href="{{ route('admin.whatsapp-wallet.signup-attempts.index') }}"
       class="inline-flex items-center shrink-0 px-3 py-2 rounded-lg border text-sm {{ $navClass(['admin.whatsapp-wallet.signup-attempts.*']) }}">
        <i class="fas fa-user-plus mr-2 text-green-600"></i> Signup attempts
    </a>
    <a href="{{ route('admin.app-sessions.index') }}"
       class="inline-flex items-center shrink-0 px-3 py-2 rounded-lg border text-sm {{ $navClass(['admin.app-sessions.*']) }}">
        <i class="fas fa-mobile-alt mr-2 text-green-600"></i> App sessions
    </a>
    <a href="{{ route('admin.whatsapp-wallet.transactions.index') }}"
       class="inline-flex items-center shrink-0 px-3 py-2 rounded-lg border text-sm {{ $navClass(['admin.whatsapp-wallet.transactions.index', 'admin.whatsapp-wallet.transactions.show']) }}">
        <i class="fas fa-list mr-2 text-green-600"></i> All transactions
    </a>
    <a href="{{ route('admin.whatsapp-wallet.transactions.p2p') }}"
       class="inline-flex items-center shrink-0 px-3 py-2 rounded-lg border text-sm {{ $navClass(['admin.whatsapp-wallet.transactions.p2p']) }}">
        <i class="fas fa-paper-plane mr-2 text-green-600"></i> P2P transfers
    </a>
    <a href="{{ route('admin.whatsapp-wallet.money-requests.index') }}"
       class="inline-flex items-center shrink-0 px-3 py-2 rounded-lg border text-sm {{ $navClass(['admin.whatsapp-wallet.money-requests.*']) }}">
        <i class="fas fa-hand-holding-usd mr-2 text-green-600"></i> Money requests
    </a>
    <a href="{{ route('admin.whatsapp-wallet.save-together.index') }}"
       class="inline-flex items-center shrink-0 px-3 py-2 rounded-lg border text-sm {{ $navClass(['admin.whatsapp-wallet.save-together.*']) }}">
        <i class="fas fa-users mr-2 text-green-600"></i> Save Together
    </a>
    <a href="{{ route('admin.whatsapp-wallet.referrals.index') }}"
       class="inline-flex items-center shrink-0 px-3 py-2 rounded-lg border text-sm {{ $navClass(['admin.whatsapp-wallet.referrals.*']) }}">
        <i class="fas fa-user-friends mr-2 text-green-600"></i> Referrals
    </a>
    <a href="{{ route('admin.whatsapp-wallet.transactions.pending') }}"
       class="inline-flex items-center shrink-0 px-3 py-2 rounded-lg border text-sm {{ $navClass(['admin.whatsapp-wallet.transactions.pending']) }}">
        <i class="fas fa-clock mr-2 text-amber-600"></i> Pending payouts
        @if($pendingCount > 0)
            <span class="ml-2 bg-amber-100 text-amber-800 rounded-full px-2 py-0.5 text-xs font-bold">{{ $pendingCount }}</span>
        @endif
    </a>
    <a href="{{ route('admin.whatsapp-wallet.transactions.failed') }}"
       class="inline-flex items-center shrink-0 px-3 py-2 rounded-lg border text-sm {{ $navClass(['admin.whatsapp-wallet.transactions.failed']) }}">
        <i class="fas fa-times-circle mr-2 text-red-600"></i> Failed payouts
        @if($failedCount > 0)
            <span class="ml-2 bg-red-100 text-red-800 rounded-full px-2 py-0.5 text-xs font-bold">{{ $failedCount }}</span>
        @endif
    </a>
    @php $bnrPending = \App\Models\BusinessNameRegistration::countPending(); @endphp
    @if(auth('admin')->user()?->canManageSettings())
    <a href="{{ route('admin.business-name-registrations.index') }}"
       class="inline-flex items-center shrink-0 px-3 py-2 rounded-lg border text-sm {{ $navClass(['admin.business-name-registrations.*']) }}">
        <i class="fas fa-briefcase mr-2 text-green-600"></i> Business names
        @if($bnrPending > 0)
            <span class="ml-2 bg-amber-100 text-amber-800 rounded-full px-2 py-0.5 text-xs font-bold">{{ $bnrPending }}</span>
        @endif
    </a>
    <a href="{{ route('admin.whatsapp-wallet.settings') }}"
       class="inline-flex items-center shrink-0 px-3 py-2 rounded-lg border text-sm {{ $navClass(['admin.whatsapp-wallet.settings', 'admin.whatsapp-wallet.update', 'admin.whatsapp-wallet.fx-rates.update']) }}">
        <i class="fas fa-cog mr-2 text-gray-600"></i> Settings &amp; FX
    </a>
    @endif
</nav>
