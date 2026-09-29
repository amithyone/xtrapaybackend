@extends('layouts.admin')

@section('title', 'Wallet '.$wallet->phone_e164)
@section('page-title', 'Wallet user')

@section('content')
@php
    $bucketBadge = fn (string $bucket): string => match ($bucket) {
        'failed' => 'bg-red-100 text-red-800',
        'pending' => 'bg-amber-100 text-amber-800',
        'successful' => 'bg-green-100 text-green-800',
        default => 'bg-gray-100 text-gray-700',
    };
@endphp
<div class="space-y-6">
    @include('admin.whatsapp-wallet.partials.nav')

    @if(session('success'))
        <div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg text-sm">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg text-sm">{{ session('error') }}</div>
    @endif
    @if(session('warning'))
        <div class="bg-amber-50 border border-amber-200 text-amber-900 px-4 py-3 rounded-lg text-sm">{{ session('warning') }}</div>
    @endif

    <div class="flex flex-wrap items-center gap-3">
        <a href="{{ route('admin.whatsapp-wallet.wallets.index') }}" class="text-sm text-gray-600 hover:text-gray-900">
            <i class="fas fa-arrow-left mr-1"></i> All wallet users
        </a>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 bg-white rounded-lg border border-gray-200 p-6 shadow-sm space-y-4">
            <div class="flex flex-wrap justify-between gap-4">
                <div>
                    <h2 class="text-xl font-bold text-gray-900 font-mono">{{ $wallet->phone_e164 }}</h2>
                    <p class="text-gray-600 mt-1">{{ $wallet->displayName() ?? 'No display name' }}</p>
                    @if($wallet->pay_code)
                        <p class="text-sm text-gray-500 mt-1">Pay code: <span class="font-mono font-medium">{{ $wallet->pay_code }}</span></p>
                    @endif
                    @if(!empty($referralAsReferred))
                        <p class="text-sm text-gray-500 mt-1">
                            Referred by
                            <a href="{{ route('admin.whatsapp-wallet.wallets.show', $referralAsReferred->referrer_wallet_id) }}" class="text-green-700 hover:underline font-mono">
                                {{ $referralAsReferred->referrerWallet?->phone_e164 ?? ('#'.$referralAsReferred->referrer_wallet_id) }}
                            </a>
                            <span class="text-gray-400">({{ $referralAsReferred->attribution_source }})</span>
                        </p>
                    @endif
                    <p class="text-sm text-gray-500 mt-1">
                        Referrals made:
                        <a href="{{ route('admin.whatsapp-wallet.referrals.index') }}" class="text-green-700 hover:underline">{{ (int) ($referralsMadeCount ?? 0) }}</a>
                    </p>
                </div>
                <div class="text-right">
                    <p class="text-sm text-gray-500">Personal balance</p>
                    <p class="text-3xl font-bold text-gray-900">₦{{ number_format((float) $wallet->balance, 2) }}</p>
                    <p class="text-sm text-gray-500 mt-2">Business balance</p>
                    <p class="text-xl font-bold text-cyan-700">₦{{ number_format((float) $wallet->business_balance, 2) }}</p>
                </div>
            </div>

            @if(auth('admin')->user()?->canMutateWalletAccounts())
            <form method="POST" action="{{ route('admin.whatsapp-wallet.wallets.link-business', $wallet) }}" class="border border-gray-100 rounded-lg p-4 bg-gray-50 space-y-3">
                @csrf
                @method('PUT')
                <p class="text-sm font-semibold text-gray-800">Link merchant business</p>
                <p class="text-xs text-gray-500">Connect this WhatsApp wallet to a Xtrapay business account for a separate business ledger in the app.</p>
                <select name="linked_business_id" class="w-full rounded-lg border-gray-300 text-sm">
                    <option value="">— No linked business —</option>
                    @foreach($linkableBusinesses as $biz)
                        <option value="{{ $biz->id }}" @selected($wallet->linked_business_id === $biz->id)>
                            #{{ $biz->id }} · {{ $biz->name }} ({{ $biz->email }})
                        </option>
                    @endforeach
                </select>
                @if($wallet->linkedBusiness)
                    <p class="text-xs text-gray-600">Linked: <strong>{{ $wallet->linkedBusiness->name }}</strong></p>
                @endif
                <button type="submit" class="px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700">Save link</button>
            </form>
            @elseif($wallet->linkedBusiness)
            <div class="border border-gray-100 rounded-lg p-4 bg-gray-50">
                <p class="text-sm font-semibold text-gray-800">Linked merchant business</p>
                <p class="text-sm text-gray-600 mt-1"><strong>{{ $wallet->linkedBusiness->name }}</strong></p>
            </div>
            @endif

            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                <div><dt class="text-gray-500">Wallet ID</dt><dd class="font-mono">#{{ $wallet->id }}</dd></div>
                <div><dt class="text-gray-500">Tier</dt><dd>{{ $wallet->isTier2() ? 'Tier 2 (Rubies VA)' : 'Tier 1' }}</dd></div>
                <div><dt class="text-gray-500">Status</dt>
                    <dd>
                        @if($wallet->isActive())
                            <span class="text-green-700 font-medium">Active</span>
                        @else
                            <span class="text-red-700 font-medium">Suspended</span>
                        @endif
                    </dd>
                </div>
                <div><dt class="text-gray-500">Bot replies</dt>
                    <dd>
                        @if($wallet->isAdminBotPaused())
                            <span class="text-amber-800 font-medium">Manual chat (paused)</span>
                        @else
                            <span class="text-green-700">Automated</span>
                        @endif
                    </dd>
                </div>
                <div><dt class="text-gray-500">PIN</dt><dd>{{ $wallet->hasPin() ? 'Configured' : 'Not set' }}</dd></div>
                <div><dt class="text-gray-500">Created</dt><dd>{{ $wallet->created_at?->format('M j, Y H:i') ?? '—' }}</dd></div>
                @if($wallet->isTier1())
                    <div class="sm:col-span-2 border-t border-gray-100 pt-3 mt-1">
                        <p class="text-xs font-semibold text-gray-700 uppercase tracking-wide mb-2">Tier 1 limits (today)</p>
                    </div>
                    <div>
                        <dt class="text-gray-500">Max wallet balance</dt>
                        <dd>₦{{ number_format($wallet->tier1MaxBalance(), 2) }}
                            <span class="text-gray-500">(₦{{ number_format($wallet->tier1BalanceHeadroom(), 2) }} headroom)</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Daily send limit (out only)</dt>
                        <dd class="font-medium">₦{{ number_format($wallet->tier1DailyOutLimit(), 2) }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Sent out today</dt>
                        <dd>₦{{ number_format($wallet->tier1DailyOutUsed(), 2) }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Send remaining today</dt>
                        <dd class="{{ $wallet->tier1DailyOutRemaining() < 1 ? 'text-red-700 font-semibold' : 'text-green-700 font-semibold' }}">
                            ₦{{ number_format($wallet->tier1DailyOutRemaining(), 2) }}
                        </dd>
                    </div>
                    <div class="sm:col-span-2">
                        <p class="text-xs text-gray-500">Top-ups and money received do not count toward the daily send limit — only outbound transfers (P2P, bank, airtime/VTU, partner debits).</p>
                    </div>
                @else
                    <div class="sm:col-span-2">
                        <dt class="text-gray-500">Tier 1 daily send tracking</dt>
                        <dd class="text-gray-600">Not applicable — Tier 2 has no Tier 1 send cap.</dd>
                    </div>
                @endif
                @if($wallet->mevon_virtual_account_number)
                    <div class="sm:col-span-2">
                        <dt class="text-gray-500">Pay-in VA</dt>
                        <dd class="font-mono">{{ $wallet->mevon_bank_name ?? 'Bank' }} · {{ $wallet->mevon_virtual_account_number }}</dd>
                    </div>
                @endif
            </dl>

            @if($isNigeriaWallet ?? false)
                @php
                    $provisionStatus = (string) ($wallet->private_account_provision_status ?? '');
                    $hasPayIn = trim((string) $wallet->mevon_virtual_account_number) !== '';
                    $kycReady = ($kycPayInReadiness['ready'] ?? $kycPersonalReadiness['ready'] ?? false) === true;
                    $kycMissing = $kycPayInReadiness['missing'] ?? $kycPersonalReadiness['missing'] ?? [];
                    $bvnDigits = preg_replace('/\D+/', '', (string) $wallet->kyc_bvn) ?? '';
                    $ninDigits = preg_replace('/\D+/', '', (string) $wallet->kyc_nin) ?? '';
                    $canQueuePayIn = auth('admin')->user()?->canMutateWalletAccounts() && ! $hasPayIn;
                    $isBusinessPayIn = ($wallet->rubies_account_type ?? 'personal') === 'business';
                    $provisionBadge = match ($provisionStatus) {
                        'queued', 'processing' => 'bg-blue-100 text-blue-900',
                        'completed', '' => $hasPayIn ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-700',
                        'failed' => 'bg-red-100 text-red-800',
                        default => 'bg-gray-100 text-gray-700',
                    };
                    $provisionLabel = match (true) {
                        $hasPayIn => 'Completed',
                        $provisionStatus === 'queued' => 'Queued',
                        $provisionStatus === 'processing' => 'Processing',
                        $provisionStatus === 'failed' => 'Failed',
                        default => 'Not started',
                    };
                @endphp
                <div class="mt-4 p-4 rounded-lg border {{ $hasPayIn ? 'bg-green-50 border-green-200' : ($provisionStatus === 'failed' ? 'bg-red-50 border-red-200' : 'bg-indigo-50 border-indigo-200') }}">
                    <div class="flex flex-wrap items-start justify-between gap-3 mb-3">
                        <h3 class="text-sm font-semibold text-gray-900">
                            <i class="fas fa-id-card mr-1"></i> Tier 2 KYC &amp; {{ $isBusinessPayIn ? 'business' : 'personal' }} pay-in (Rubies MFB (Mevon))
                        </h3>
                        <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium {{ $provisionBadge }}">{{ $provisionLabel }}</span>
                    </div>

                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-2 text-sm mb-4">
                        <div>
                            <dt class="text-gray-500">Account type</dt>
                            <dd class="capitalize">{{ $wallet->rubies_account_type ?? 'personal' }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">KYC verified at</dt>
                            <dd>{{ $wallet->kyc_verified_at?->format('M j, Y H:i') ?? '—' }}</dd>
                        </div>
                        @if($isBusinessPayIn)
                            <div class="sm:col-span-2">
                                <dt class="text-gray-500">CAC / business registration</dt>
                                <dd class="font-mono">{{ $wallet->kyc_cac ?: '—' }}</dd>
                            </div>
                            <div class="sm:col-span-2">
                                <dt class="text-gray-500">Registered business name</dt>
                                <dd>{{ $wallet->kyc_business_name ?: ($wallet->registeredBusinessNameForPayIn() ?: '—') }}</dd>
                            </div>
                        @endif
                        <div>
                            <dt class="text-gray-500">First name</dt>
                            <dd>{{ $wallet->kyc_fname ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Last name</dt>
                            <dd>{{ $wallet->kyc_lname ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Date of birth</dt>
                            <dd>{{ $wallet->kyc_dob?->format('Y-m-d') ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Gender</dt>
                            <dd class="capitalize">{{ $wallet->kyc_gender ?? '—' }}</dd>
                        </div>
                        <div class="sm:col-span-2">
                            <dt class="text-gray-500">Email</dt>
                            <dd>{{ $wallet->kyc_email ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">BVN</dt>
                            <dd class="font-mono">{{ strlen($bvnDigits) === 11 ? ('*******'.substr($bvnDigits, -4)) : '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">NIN</dt>
                            <dd class="font-mono">{{ strlen($ninDigits) === 11 ? ('*******'.substr($ninDigits, -4)) : '—' }}</dd>
                        </div>
                        @if($wallet->private_account_provision_queued_at)
                            <div>
                                <dt class="text-gray-500">Queue started</dt>
                                <dd>{{ $wallet->private_account_provision_queued_at->format('M j, Y H:i') }}
                                    <span class="text-gray-500">({{ $wallet->private_account_provision_queued_at->diffForHumans() }})</span>
                                </dd>
                            </div>
                        @endif
                        @if($wallet->tier2_provisioned_at)
                            <div>
                                <dt class="text-gray-500">Account provisioned</dt>
                                <dd>{{ $wallet->tier2_provisioned_at->format('M j, Y H:i') }}</dd>
                            </div>
                        @endif
                    </dl>

                    @if($hasPayIn)
                        <dl class="text-sm space-y-1 mb-2 bg-white/60 rounded-lg p-3 border border-green-100">
                            <div><span class="text-gray-600">Account number:</span> <span class="font-mono font-semibold">{{ $wallet->mevon_virtual_account_number }}</span></div>
                            <div><span class="text-gray-600">Bank:</span> {{ $wallet->mevon_bank_name ?? 'Rubies MFB' }} @if($wallet->mevon_bank_code)<span class="text-gray-500">({{ $wallet->mevon_bank_code }})</span>@endif</div>
                            @if($wallet->mevon_account_name)
                                <div><span class="text-gray-600">Account name:</span> {{ $wallet->mevon_account_name }}</div>
                            @endif
                            @if($wallet->mevon_reference)
                                <div><span class="text-gray-600">Reference:</span> <span class="font-mono text-xs">{{ $wallet->mevon_reference }}</span></div>
                            @endif
                        </dl>
                    @elseif(in_array($provisionStatus, ['queued', 'processing'], true))
                        <p class="text-sm text-blue-900 mb-2">
                            Rubies MFB (Mevon) identity verify + permanent account creation is in progress.
                            Jobs run on the KYC queue — process with
                            <a href="{{ url('/cron/process-kyc-queue') }}" target="_blank" rel="noopener" class="underline font-medium">/cron/process-kyc-queue</a>
                            (also listed on the admin dashboard cron URLs).
                        </p>
                    @elseif($provisionStatus === 'failed')
                        <p class="text-sm text-red-900 mb-2">
                            Account creation failed: {{ $wallet->private_account_provision_error ?? 'Unknown error' }}
                        </p>
                        @if(!$kycReady && $kycMissing !== [])
                            <p class="text-sm text-amber-900 mb-1">Fix these before retrying:</p>
                            <ul class="text-sm text-amber-900 list-disc list-inside mb-2 space-y-0.5">
                                @foreach($kycMissing as $item)
                                    <li>{{ $item }}</li>
                                @endforeach
                            </ul>
                        @endif
                    @elseif(!$kycReady && $kycMissing !== [])
                        <p class="text-sm text-amber-900 mb-2">Missing before queueing:</p>
                        <ul class="text-sm text-amber-900 list-disc list-inside mb-2 space-y-0.5">
                            @foreach($kycMissing as $item)
                                <li>{{ $item }}</li>
                            @endforeach
                        </ul>
                    @else
                        <p class="text-sm text-gray-700 mb-2">
                            KYC is on file. Queue Rubies MFB (Mevon) verify and permanent {{ $isBusinessPayIn ? 'business' : 'personal' }} account creation when ready.
                        </p>
                    @endif

                    @if(!($kycProvisionConfigured ?? false))
                        <p class="text-xs text-red-800 bg-red-50 border border-red-100 rounded px-2 py-1.5 mb-2">
                            Rubies MFB (Mevon) private account API is not configured (<code class="bg-red-100 px-1 rounded">MEVONPAY_PRIVATE_ACCOUNT_PATH</code> / credentials).
                        </p>
                    @endif

                    @php
                        $provisionInProgress = in_array($provisionStatus, ['queued', 'processing'], true);
                        $kycFieldGaps = array_values(array_filter(
                            $kycMissing,
                            static fn (string $item): bool => ! in_array($item, [
                                'Account creation is already in progress.',
                                'Permanent account already exists.',
                                'Tier 2 provisioning is not configured.',
                            ], true)
                        ));
                        $kycFieldsReady = $kycFieldGaps === [];
                    @endphp
                    @if($canQueuePayIn)
                        <div class="flex flex-wrap gap-2 pt-2 border-t border-black/5">
                            @if($provisionStatus === 'failed' || ($provisionInProgress && ! $hasPayIn))
                                <form method="POST" action="{{ route('admin.whatsapp-wallet.wallets.retry-pay-in-account', $wallet) }}">
                                    @csrf
                                    <button type="submit"
                                        class="px-3 py-1.5 rounded-lg bg-primary text-white text-xs font-semibold hover:opacity-90 disabled:opacity-50 disabled:cursor-not-allowed"
                                        @disabled(! ($kycProvisionConfigured ?? false) || ! $kycFieldsReady)
                                        onclick="return confirm('{{ $provisionInProgress ? 'Provision looks stuck (no account yet). Re-queue' : 'Retry' }} Rubies MFB (Mevon) verify + permanent account for {{ $wallet->phone_e164 }}?')">
                                        <i class="fas fa-redo mr-1"></i> {{ $provisionInProgress ? 'Re-queue account creation' : 'Retry account creation' }}
                                    </button>
                                </form>
                            @endif
                            @if(! $provisionInProgress)
                                <form method="POST" action="{{ route('admin.whatsapp-wallet.wallets.queue-pay-in-account', $wallet) }}">
                                    @csrf
                                    <button type="submit"
                                        class="px-3 py-1.5 rounded-lg bg-indigo-600 text-white text-xs font-semibold hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed"
                                        @disabled(! ($kycProvisionConfigured ?? false) || ! $kycFieldsReady || $provisionStatus === 'failed')
                                        title="{{ $kycFieldsReady ? 'Queue permanent pay-in account' : 'Complete missing KYC fields first (see list above)' }}"
                                        onclick="return confirm('Queue Rubies MFB (Mevon) verify + permanent {{ $isBusinessPayIn ? 'business' : 'personal' }} pay-in account for {{ $wallet->phone_e164 }}?')">
                                        <i class="fas fa-paper-plane mr-1"></i> Queue pay-in account
                                    </button>
                                </form>
                            @endif
                            @if($provisionInProgress)
                                <p class="w-full text-xs text-blue-800">
                                    Account creation is marked in progress
                                    @if($wallet->private_account_provision_queued_at)
                                        (queued {{ $wallet->private_account_provision_queued_at->diffForHumans() }})
                                    @endif.
                                    Process the KYC queue via
                                    <a href="{{ url('/cron/process-kyc-queue') }}" target="_blank" rel="noopener" class="underline font-medium">/cron/process-kyc-queue</a>,
                                    or use <strong>Re-queue</strong> if it is stuck with no account number.
                                </p>
                            @elseif(! ($kycProvisionConfigured ?? false) || ! $kycFieldsReady)
                                <p class="w-full text-xs text-amber-800">
                                    @if(! ($kycProvisionConfigured ?? false))
                                        Buttons stay disabled until Rubies MFB (Mevon) private-account API is configured.
                                    @elseif($isBusinessPayIn)
                                        Buttons stay disabled until missing business KYC above is saved
                                        (CAC with RC/BN prefix + registered company name, plus signatory BVN/name/DOB/email).
                                    @else
                                        Buttons stay disabled until missing personal KYC above is saved
                                        (first/last name, DOB, gender, email, and BVN or NIN). CAC / company name is not required for personal.
                                    @endif
                                </p>
                            @endif
                        </div>
                    @elseif(! $hasPayIn && auth('admin')->user()?->canAccessWalletOps())
                        <p class="text-xs text-gray-600 pt-2 border-t border-black/5">
                            Your admin role can view KYC but cannot queue pay-in accounts (needs Admin or Super Admin).
                        </p>
                    @endif

                    @if(auth('admin')->user()?->canMutateWalletAccounts())
                        <details class="mt-4 pt-4 border-t border-black/5">
                            <summary class="text-sm font-semibold text-gray-900 cursor-pointer select-none">
                                <i class="fas fa-tools mr-1"></i> Ops: edit KYC / pay-in &amp; test Rubies MFB (Mevon)
                            </summary>
                            <p class="text-xs text-amber-900 bg-amber-50 border border-amber-100 rounded px-2 py-1.5 mt-3 mb-3">
                                If Rubies MFB (Mevon) API calls fail with Imunify360 / access denied, whitelist this server&apos;s <strong>outbound IP</strong> with Rubies MFB (Mevon) and allow HTTPS to the Rubies MFB (Mevon) API host in hosting WAF.
                                For inbound credits, ensure Rubies MFB (Mevon) webhook IPs can reach <code class="bg-amber-100 px-1 rounded">/api/mevonpay/webhook</code> (Imunify360 must not block them).
                            </p>
                            <form method="POST" action="{{ route('admin.whatsapp-wallet.wallets.kyc-pay-in', $wallet) }}" class="space-y-3 mt-2">
                                @csrf
                                @method('PUT')
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                                    <div>
                                        <label class="block text-gray-600 mb-1">Account type</label>
                                        <select name="rubies_account_type" id="wallet-rubies-account-type"
                                                class="w-full rounded-lg border border-gray-300 px-3 py-2"
                                                onchange="(function(sel){ var biz = sel.value === 'business'; document.querySelectorAll('[data-business-kyc]').forEach(function(el){ el.classList.toggle('hidden', !biz); el.querySelectorAll('input').forEach(function(inp){ inp.disabled = !biz; }); }); })(this)">
                                            <option value="personal" @selected(old('rubies_account_type', $wallet->rubies_account_type ?? 'personal') === 'personal')>Personal</option>
                                            <option value="business" @selected(old('rubies_account_type', $wallet->rubies_account_type ?? 'personal') === 'business')>Business</option>
                                        </select>
                                        <p class="text-xs text-gray-500 mt-1">Personal permanent accounts only need personal KYC (no CAC).</p>
                                    </div>
                                    <div data-business-kyc @class(['hidden' => ! $isBusinessPayIn])>
                                        <label class="block text-gray-600 mb-1">CAC / business registration (RC/BN)</label>
                                        <input type="text" name="kyc_cac" value="{{ old('kyc_cac', $wallet->kyc_cac) }}"
                                               maxlength="100" placeholder="e.g. RC1234567 or BN1234567"
                                               class="w-full rounded-lg border border-gray-300 px-3 py-2 font-mono uppercase"
                                               @disabled(! $isBusinessPayIn)>
                                    </div>
                                    <div class="sm:col-span-2" data-business-kyc @class(['hidden' => ! $isBusinessPayIn])>
                                        <label class="block text-gray-600 mb-1">Registered business name (Rubies MFB (Mevon) company name)</label>
                                        <input type="text" name="kyc_business_name" value="{{ old('kyc_business_name', $wallet->kyc_business_name) }}"
                                               maxlength="255" placeholder="Company name on CAC — not the RC/BN number"
                                               class="w-full rounded-lg border border-gray-300 px-3 py-2"
                                               @disabled(! $isBusinessPayIn)>
                                        <p class="text-xs text-gray-500 mt-1">Required only for account type <strong>business</strong>.</p>
                                    </div>
                                    <div>
                                        <label class="block text-gray-600 mb-1">First name</label>
                                        <input type="text" name="kyc_fname" value="{{ old('kyc_fname', $wallet->kyc_fname) }}"
                                               class="w-full rounded-lg border border-gray-300 px-3 py-2">
                                    </div>
                                    <div>
                                        <label class="block text-gray-600 mb-1">Last name</label>
                                        <input type="text" name="kyc_lname" value="{{ old('kyc_lname', $wallet->kyc_lname) }}"
                                               class="w-full rounded-lg border border-gray-300 px-3 py-2">
                                    </div>
                                    <div>
                                        <label class="block text-gray-600 mb-1">Date of birth</label>
                                        <input type="date" name="kyc_dob" value="{{ old('kyc_dob', $wallet->kyc_dob?->format('Y-m-d')) }}"
                                               class="w-full rounded-lg border border-gray-300 px-3 py-2">
                                    </div>
                                    <div>
                                        <label class="block text-gray-600 mb-1">Gender</label>
                                        <select name="kyc_gender" class="w-full rounded-lg border border-gray-300 px-3 py-2">
                                            <option value="">—</option>
                                            <option value="male" @selected(old('kyc_gender', $wallet->kyc_gender) === 'male')>Male</option>
                                            <option value="female" @selected(old('kyc_gender', $wallet->kyc_gender) === 'female')>Female</option>
                                        </select>
                                    </div>
                                    <div class="sm:col-span-2">
                                        <label class="block text-gray-600 mb-1">Email</label>
                                        <input type="email" name="kyc_email" value="{{ old('kyc_email', $wallet->kyc_email) }}"
                                               class="w-full rounded-lg border border-gray-300 px-3 py-2">
                                    </div>
                                    <div>
                                        <label class="block text-gray-600 mb-1">BVN (11 digits)</label>
                                        <input type="text" name="kyc_bvn" value="{{ old('kyc_bvn', $wallet->kyc_bvn) }}"
                                               inputmode="numeric" maxlength="11" autocomplete="off"
                                               class="w-full rounded-lg border border-gray-300 px-3 py-2 font-mono">
                                    </div>
                                    <div>
                                        <label class="block text-gray-600 mb-1">NIN (11 digits)</label>
                                        <input type="text" name="kyc_nin" value="{{ old('kyc_nin', $wallet->kyc_nin) }}"
                                               inputmode="numeric" maxlength="11" autocomplete="off"
                                               class="w-full rounded-lg border border-gray-300 px-3 py-2 font-mono">
                                    </div>
                                </div>
                                <div class="pt-2 border-t border-gray-200">
                                    <p class="text-xs font-semibold text-gray-700 mb-2">Pay-in account (manual — use when Rubies MFB (Mevon) returns account number outside queue)</p>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                                        <div>
                                            <label class="block text-gray-600 mb-1">Account number</label>
                                            <input type="text" name="mevon_virtual_account_number"
                                                   value="{{ old('mevon_virtual_account_number', $wallet->mevon_virtual_account_number) }}"
                                                   inputmode="numeric" class="w-full rounded-lg border border-gray-300 px-3 py-2 font-mono">
                                        </div>
                                        <div>
                                            <label class="block text-gray-600 mb-1">Bank name</label>
                                            <input type="text" name="mevon_bank_name"
                                                   value="{{ old('mevon_bank_name', $wallet->mevon_bank_name ?? 'Rubies MFB') }}"
                                                   class="w-full rounded-lg border border-gray-300 px-3 py-2">
                                        </div>
                                        <div>
                                            <label class="block text-gray-600 mb-1">Bank code</label>
                                            <input type="text" name="mevon_bank_code"
                                                   value="{{ old('mevon_bank_code', $wallet->mevon_bank_code) }}"
                                                   class="w-full rounded-lg border border-gray-300 px-3 py-2 font-mono">
                                        </div>
                                        <div>
                                            <label class="block text-gray-600 mb-1">Account name</label>
                                            <input type="text" name="mevon_account_name"
                                                   value="{{ old('mevon_account_name', $wallet->mevon_account_name) }}"
                                                   class="w-full rounded-lg border border-gray-300 px-3 py-2">
                                        </div>
                                        <div class="sm:col-span-2">
                                            <label class="block text-gray-600 mb-1">Rubies MFB (Mevon) reference</label>
                                            <input type="text" name="mevon_reference"
                                                   value="{{ old('mevon_reference', $wallet->mevon_reference) }}"
                                                   class="w-full rounded-lg border border-gray-300 px-3 py-2 font-mono text-xs">
                                        </div>
                                    </div>
                                    <label class="flex items-start gap-2 text-xs text-gray-700 mt-2">
                                        <input type="checkbox" name="mark_provision_completed" value="1" class="mt-0.5 rounded border-gray-300"
                                               @checked(old('mark_provision_completed', $hasPayIn))>
                                        <span>Mark tier 2 provision completed (sets tier 2 + clears provision error; required for webhook credits even without account number)</span>
                                    </label>
                                </div>
                                <div class="flex flex-wrap gap-2 pt-1">
                                    <button type="submit" class="px-3 py-1.5 rounded-lg bg-gray-900 text-white text-xs font-semibold hover:bg-gray-800"
                                        onclick="return confirm('Save KYC / pay-in changes for wallet #{{ $wallet->id }}?')">
                                        <i class="fas fa-save mr-1"></i> Save KYC &amp; pay-in
                                    </button>
                                    <button type="submit" formaction="{{ route('admin.whatsapp-wallet.wallets.test-mevon-identity', $wallet) }}" formmethod="POST"
                                        class="px-3 py-1.5 rounded-lg bg-slate-600 text-white text-xs font-semibold hover:bg-slate-700"
                                        @disabled(! ($kycProvisionConfigured ?? false))
                                        onclick="var m=this.form.querySelector('input[name=_method]'); if(m){m.disabled=true;} return true;">
                                        <i class="fas fa-vial mr-1"></i> Test Rubies MFB (Mevon) identity verify (uses fields above)
                                    </button>
                                </div>
                            </form>
                        </details>
                    @endif
                </div>
            @endif

            <div class="flex flex-wrap gap-2 pt-2 border-t border-gray-100">
                <a href="{{ route('admin.whatsapp-wallet.transactions.index', ['wallet_id' => $wallet->id]) }}"
                   class="text-sm bg-gray-100 hover:bg-gray-200 px-3 py-2 rounded-lg">All transactions</a>
                <a href="{{ route('admin.whatsapp-wallet.transactions.p2p', ['wallet_id' => $wallet->id]) }}"
                   class="text-sm bg-gray-100 hover:bg-gray-200 px-3 py-2 rounded-lg">P2P only</a>
                <a href="{{ route('admin.whatsapp-wallet.transactions.index', ['wallet_id' => $wallet->id, 'type' => \App\Models\WalletTransaction::TYPE_BANK_TRANSFER_OUT]) }}"
                   class="text-sm bg-gray-100 hover:bg-gray-200 px-3 py-2 rounded-lg">Bank transfers</a>
            </div>
        </div>

        <div class="space-y-4">
            <div class="bg-white rounded-lg border border-gray-200 p-5 shadow-sm">
                <h3 class="font-semibold text-gray-900 mb-3">Activity summary</h3>
                <ul class="text-sm space-y-2">
                    <li class="flex justify-between"><span>Bank transfers</span><span class="font-medium">{{ number_format($wallet->bank_transfers_count ?? 0) }}</span></li>
                    <li class="flex justify-between"><span>P2P legs</span><span class="font-medium">{{ number_format($wallet->p2p_count ?? 0) }}</span></li>
                    <li class="flex justify-between"><span>Top-ups</span><span class="font-medium">{{ number_format($wallet->topups_count ?? 0) }}</span></li>
                    @if(($pendingPayouts ?? 0) > 0)
                        <li class="flex justify-between text-amber-800">
                            <span>Pending payouts (48h)</span>
                            <a href="{{ route('admin.whatsapp-wallet.transactions.pending', ['search' => $wallet->phone_e164]) }}" class="font-bold hover:underline">{{ $pendingPayouts }}</a>
                        </li>
                    @endif
                </ul>
            </div>

            @if(auth('admin')->user()?->canMutateWalletAccounts())
            <div class="bg-white rounded-lg border border-gray-200 p-5 shadow-sm">
                <h3 class="font-semibold text-gray-900 mb-3">Account controls</h3>
                <div class="text-xs text-gray-600 mb-3 space-y-1">
                    <div>Status: <strong>{{ str_replace('_', ' ', (string) $wallet->status) }}</strong></div>
                    <div>Post no debit: <strong class="{{ $wallet->hasPostNoDebit() ? 'text-orange-700' : '' }}">{{ $wallet->hasPostNoDebit() ? 'Yes' : 'No' }}</strong></div>
                    @if($wallet->isOnHold() && $wallet->on_hold_reason)
                        <div class="text-amber-700">Hold reason: {{ $wallet->on_hold_reason }}</div>
                    @endif
                </div>
                <form method="POST" action="{{ route('admin.whatsapp-wallet.wallets.status', $wallet) }}" class="space-y-3">
                    @csrf
                    @method('PUT')
                    @if($wallet->status !== \App\Models\Wallet::STATUS_SUSPENDED)
                        <input type="hidden" name="status" value="suspended">
                        <button type="submit" class="w-full bg-red-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-red-700"
                            onclick="return confirm('Suspend this wallet? User cannot spend until reactivated.')">
                            Suspend wallet
                        </button>
                    @else
                        <input type="hidden" name="status" value="active">
                        <button type="submit" class="w-full bg-green-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-green-700">
                            Reactivate wallet
                        </button>
                    @endif
                </form>

                @if(! $wallet->isOnHold())
                    <form method="POST" action="{{ route('admin.whatsapp-wallet.wallets.hold', $wallet) }}" class="mt-3 space-y-2">
                        @csrf
                        <input type="hidden" name="action" value="hold">
                        <input type="text" name="reason" placeholder="Hold reason (optional)" class="w-full border-gray-300 rounded-lg text-sm">
                        <button type="submit" class="w-full bg-amber-600 text-white px-4 py-2 rounded-lg text-sm"
                            onclick="return confirm('Put wallet on hold? Debits blocked; credits still allowed.')">
                            Put on hold
                        </button>
                    </form>
                @else
                    <form method="POST" action="{{ route('admin.whatsapp-wallet.wallets.hold', $wallet) }}" class="mt-3">
                        @csrf
                        <input type="hidden" name="action" value="release">
                        <button type="submit" class="w-full bg-amber-700 text-white px-4 py-2 rounded-lg text-sm">
                            Release hold
                        </button>
                    </form>
                @endif

                <form method="POST" action="{{ route('admin.whatsapp-wallet.wallets.post-no-debit', $wallet) }}" class="mt-3">
                    @csrf
                    @if($wallet->hasPostNoDebit())
                        <input type="hidden" name="enabled" value="0">
                        <button type="submit" class="w-full border border-orange-300 text-orange-800 px-4 py-2 rounded-lg text-sm hover:bg-orange-50">
                            Clear post no debit
                        </button>
                    @else
                        <input type="hidden" name="enabled" value="1">
                        <button type="submit" class="w-full bg-orange-600 text-white px-4 py-2 rounded-lg text-sm"
                            onclick="return confirm('Enable post no debit? All outbound spends will be blocked.')">
                            Enable post no debit
                        </button>
                    @endif
                </form>

                <form method="POST" action="{{ route('admin.whatsapp-wallet.wallets.pin', $wallet) }}" class="mt-4 pt-4 border-t border-gray-100 space-y-2">
                    @csrf
                    <h4 class="text-sm font-medium text-gray-900">Change PIN</h4>
                    <input type="password" name="pin" inputmode="numeric" maxlength="4" pattern="\d{4}" required placeholder="New 4-digit PIN" class="w-full border-gray-300 rounded-lg text-sm">
                    <input type="password" name="pin_confirmation" inputmode="numeric" maxlength="4" pattern="\d{4}" required placeholder="Confirm PIN" class="w-full border-gray-300 rounded-lg text-sm">
                    <button type="submit" class="w-full bg-primary text-white px-4 py-2 rounded-lg text-sm"
                        onclick="return confirm('Reset this wallet PIN?')">Update PIN</button>
                </form>

                <form method="POST" action="{{ route('admin.whatsapp-wallet.wallets.balance-audit-exempt', $wallet) }}" class="mt-4 pt-4 border-t border-gray-100 space-y-2">
                    @csrf
                    @method('PUT')
                    <label class="flex items-start gap-2 text-sm text-gray-800">
                        <input type="checkbox" name="balance_audit_exempt" value="1" class="mt-1 rounded border border-gray-300"
                               @checked($wallet->balance_audit_exempt)
                               onchange="this.form.submit()">
                        <span>
                            Exclude from bank float audit
                            <span class="block text-xs text-gray-500">Test wallets — won’t count on Audits totals</span>
                        </span>
                    </label>
                    @if($wallet->balance_audit_exempt)
                        <p class="text-xs text-amber-700">Currently excluded from site bank float.</p>
                    @endif
                </form>
            </div>
            @endif

            <div class="bg-white rounded-lg border border-gray-200 p-5 shadow-sm">
                <h3 class="font-semibold text-gray-900 mb-2">App device verification</h3>
                <p class="text-sm text-gray-600 mb-3">
                    When a customer is stuck on <span class="font-medium">“Verify this device to continue”</span>,
                    clear trusted devices so they can sign in with PIN/OTP again.
                </p>

                @if($apiAccount === null)
                    <p class="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
                        No app login account yet — user has not registered on Xtrapay.
                    </p>
                @else
                    <dl class="text-xs text-gray-600 space-y-1 mb-4">
                        <div class="flex justify-between gap-2">
                            <dt>Device trust feature</dt>
                            <dd class="{{ ($deviceTrustEnabled ?? true) ? 'text-green-700 font-medium' : 'text-gray-500' }}">
                                {{ ($deviceTrustEnabled ?? true) ? 'Enabled' : 'Disabled' }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt>Step-up required</dt>
                            <dd class="{{ ($stepUpRequired ?? false) ? 'text-amber-700 font-medium' : 'text-green-700 font-medium' }}">
                                {{ ($stepUpRequired ?? false) ? 'Yes — verify device on login' : 'No' }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt>Trusted devices</dt>
                            <dd class="font-medium">{{ count($trustedDevices ?? []) }}</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt>Pending step-up sessions</dt>
                            <dd>{{ $pendingStepUpSessions ?? 0 }}</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt>High-value transfer lock</dt>
                            <dd class="{{ !empty($transferLockMeta['high_value_transfer_blocked']) ? 'text-amber-700 font-medium' : 'text-gray-500' }}">
                                @if(!empty($transferLockMeta['high_value_transfer_blocked']) && !empty($transferLockMeta['transfer_lock_until']))
                                    Until {{ \Illuminate\Support\Carbon::parse($transferLockMeta['transfer_lock_until'])->timezone(config('app.timezone'))->format('Y-m-d H:i') }}
                                @else
                                    None
                                @endif
                            </dd>
                        </div>
                    </dl>

                    @if(($stepUpRequired ?? false) || count($trustedDevices ?? []) > 0)
                        <form method="POST" action="{{ route('admin.whatsapp-wallet.wallets.devices.reset', $wallet) }}" class="mb-3">
                            @csrf
                            <input type="hidden" name="clear_transfer_lock" value="1">
                            <button type="submit"
                                class="w-full bg-amber-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-amber-700"
                                onclick="return confirm('Clear ALL trusted devices and step-up for {{ $wallet->phone_e164 }}? They will sign in with PIN/OTP without verify-device, and must set up a new passkey.')">
                                <i class="fas fa-unlock mr-1"></i> Unblock login (revoke all devices)
                            </button>
                        </form>
                    @endif

                    <div class="flex flex-col gap-2 mb-4">
                        @if(($pendingStepUpSessions ?? 0) > 0)
                            <form method="POST" action="{{ route('admin.whatsapp-wallet.wallets.step-up.clear', $wallet) }}">
                                @csrf
                                <button type="submit" class="w-full border border-gray-300 text-gray-800 px-4 py-2 rounded-lg text-sm hover:bg-gray-50"
                                    onclick="return confirm('Clear pending verify-device sessions?')">
                                    Clear stuck step-up sessions
                                </button>
                            </form>
                        @endif
                        @if(!empty($transferLockMeta['high_value_transfer_blocked']) && auth('admin')->user()?->canMutateWalletAccounts())
                            <form method="POST" action="{{ route('admin.whatsapp-wallet.wallets.transfer-lock.clear', $wallet) }}">
                                @csrf
                                <button type="submit" class="w-full border border-gray-300 text-gray-800 px-4 py-2 rounded-lg text-sm hover:bg-gray-50"
                                    onclick="return confirm('Clear high-value transfer lock?')">
                                    Clear transfer lock only
                                </button>
                            </form>
                        @endif
                    </div>

                    @if(count($trustedDevices ?? []) > 0)
                        <h4 class="text-xs font-semibold text-gray-700 uppercase tracking-wide mb-2">Trusted devices</h4>
                        <ul class="divide-y divide-gray-100 border border-gray-100 rounded-lg overflow-hidden text-sm">
                            @foreach($trustedDevices as $device)
                                <li class="flex items-center justify-between gap-2 px-3 py-2 bg-gray-50">
                                    <div class="min-w-0">
                                        <p class="font-medium text-gray-900 truncate">{{ $device['label'] ?: ('Device #'.$device['id']) }}</p>
                                        <p class="text-xs text-gray-500">
                                            {{ $device['platform'] ?: 'unknown' }}
                                            @if(!empty($device['last_active_at']))
                                                · {{ \Illuminate\Support\Carbon::parse($device['last_active_at'])->diffForHumans() }}
                                            @endif
                                        </p>
                                    </div>
                                    <form method="POST" action="{{ route('admin.whatsapp-wallet.wallets.devices.revoke', [$wallet, $device['id']]) }}">
                                        @csrf
                                        <button type="submit" class="text-xs text-red-700 hover:underline whitespace-nowrap"
                                            onclick="return confirm('Revoke this trusted device?')">
                                            Revoke
                                        </button>
                                    </form>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="text-xs text-gray-500">No passkey-bound trusted devices on file.</p>
                    @endif
                @endif
            </div>

            <div class="bg-white rounded-lg border border-gray-200 p-5 shadow-sm">
                <h3 class="font-semibold text-gray-900 mb-2">Login OTP lockout</h3>
                <p class="text-sm text-gray-600 mb-3">
                    Clear users stuck on <span class="font-medium">“Too many unused login codes”</span> or
                    <span class="font-medium">“Too many wrong codes”</span> in the Xtrapay app or WhatsApp email-link flow.
                </p>

                @php
                    $otp = $otpLockout ?? [];
                    $hold = $emailHold ?? [];
                @endphp
                <dl class="text-xs text-gray-600 space-y-1 mb-4">
                    <div class="flex justify-between gap-2">
                        <dt>App OTP blocked</dt>
                        <dd class="{{ !empty($otp['app_otp_blocked']) ? 'text-red-700 font-medium' : 'text-green-700 font-medium' }}">
                            {{ !empty($otp['app_otp_blocked']) ? 'Yes — too many unused codes' : 'No' }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt>Unused OTP sends (24h)</dt>
                        <dd>{{ $otp['unused_otp_sends'] ?? 0 }} / {{ $otp['unused_otp_sends_max'] ?? 3 }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt>Wrong verify attempts</dt>
                        <dd class="{{ !empty($otp['verify_locked']) ? 'text-red-700 font-medium' : '' }}">
                            {{ $otp['verify_attempts'] ?? 0 }} / {{ $otp['verify_attempts_max'] ?? 5 }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt>Pending app OTP in cache</dt>
                        <dd>{{ !empty($otp['has_pending_app_otp']) ? 'Yes' : 'No' }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt>WhatsApp session OTP attempts</dt>
                        <dd class="{{ !empty($otp['whatsapp_otp_locked']) ? 'text-red-700 font-medium' : '' }}">
                            {{ $otp['whatsapp_otp_attempts'] ?? 0 }}
                            @if(!empty($otp['whatsapp_session_state']))
                                <span class="text-gray-500">({{ $otp['whatsapp_session_state'] }})</span>
                            @endif
                        </dd>
                    </div>
                    <div class="flex justify-between gap-2 border-t border-gray-100 pt-2 mt-1">
                        <dt>Email OTP hold (5 min dedupe)</dt>
                        <dd class="{{ !empty($hold['held']) ? 'text-amber-800 font-medium' : 'text-green-700 font-medium' }}">
                            @if(!empty($hold['held']))
                                Yes — {{ $hold['seconds_remaining'] ?? 0 }}s left
                            @else
                                No
                            @endif
                        </dd>
                    </div>
                </dl>

                @if(!empty($otp['is_stuck']))
                    <p class="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 mb-3">
                        This user appears locked out of OTP login. Clear below so they can request a fresh code.
                    </p>
                @endif
                @if(!empty($hold['held']))
                    <p class="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 mb-3">
                        Email resend is suppressed until the hold expires. Clear the hold if they say they never got the email.
                    </p>
                @endif

                <form method="POST" action="{{ route('admin.whatsapp-wallet.wallets.otp-lockout.clear', $wallet) }}" class="mb-2">
                    @csrf
                    <button type="submit"
                        class="w-full {{ !empty($otp['is_stuck']) ? 'bg-amber-600 hover:bg-amber-700' : 'border border-gray-300 text-gray-800 hover:bg-gray-50' }} px-4 py-2 rounded-lg text-sm {{ !empty($otp['is_stuck']) ? 'text-white' : '' }}"
                        onclick="return confirm('Clear OTP lockout for {{ $wallet->phone_e164 }}? They can request a new login code immediately.')">
                        <i class="fas fa-key mr-1"></i> Clear OTP lockout
                    </button>
                </form>

                @if(filled($wallet->kyc_email))
                    <form method="POST" action="{{ route('admin.whatsapp-wallet.wallets.email-hold.clear', $wallet) }}">
                        @csrf
                        <button type="submit"
                            class="w-full {{ !empty($hold['held']) ? 'bg-amber-600 hover:bg-amber-700 text-white' : 'border border-gray-300 text-gray-800 hover:bg-gray-50' }} px-4 py-2 rounded-lg text-sm"
                            onclick="return confirm('Clear email OTP hold for {{ $wallet->kyc_email }}? They can receive a new code immediately.')">
                            <i class="fas fa-envelope mr-1"></i> Clear email OTP hold
                        </button>
                    </form>
                @endif
            </div>

            <div class="bg-white rounded-lg border border-gray-200 p-5 shadow-sm">
                <h3 class="font-semibold text-gray-900 mb-2">App push notification</h3>
                <p class="text-sm text-gray-600 mb-3">
                    Send a Firebase (FCM) alert to this user&apos;s Xtrapay app.
                </p>
                <dl class="text-xs text-gray-600 space-y-1 mb-4">
                    <div class="flex justify-between gap-2">
                        <dt>Xtrapay FCM project</dt>
                        <dd class="font-mono text-xs">{{ $pushStatus['fcm_project_id'] ?: '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt>Xtrapay service account</dt>
                        <dd class="font-mono text-xs {{ ($pushStatus['projects_match'] ?? false) ? 'text-green-700' : 'text-red-700' }}">
                            {{ $pushStatus['service_account_project_id'] ?? '—' }}
                            @if(!($pushStatus['projects_match'] ?? true) && ($pushStatus['fcm_project_id'] ?? '') !== '')
                                <span class="block text-red-600 font-normal">Upload service account from checkout-now-a2b2f (CHECKOUTNOW_FCM_SERVICE_ACCOUNT_JSON)</span>
                            @endif
                        </dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt>Xtrapay push configured</dt>
                        <dd class="{{ ($pushStatus['configured'] ?? false) ? 'text-green-700 font-medium' : 'text-red-700 font-medium' }}">
                            {{ ($pushStatus['configured'] ?? false) ? 'Yes' : 'No' }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt>Device token</dt>
                        <dd class="{{ ($pushStatus['has_token'] ?? false) ? 'text-green-700 font-medium' : 'text-amber-700 font-medium' }}">
                            {{ ($pushStatus['has_token'] ?? false) ? 'Registered' : 'None' }}
                        </dd>
                    </div>
                    @if(($pushStatus['has_token'] ?? false))
                        <div class="flex justify-between gap-2">
                            <dt>Platform</dt>
                            <dd class="capitalize">{{ $pushStatus['platform'] ?? '—' }}</dd>
                        </div>
                        @if(!empty($pushStatus['updated_at']))
                            <div class="flex justify-between gap-2">
                                <dt>Token updated</dt>
                                <dd>{{ \Illuminate\Support\Carbon::parse($pushStatus['updated_at'])->diffForHumans() }}</dd>
                            </div>
                        @endif
                    @endif
                </dl>
                <form method="POST" action="{{ route('admin.whatsapp-wallet.wallets.push', $wallet) }}" class="space-y-3">
                    @csrf
                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Title</label>
                        <input type="text" name="title" value="{{ old('title', 'Xtrapay') }}" maxlength="120" required
                            class="w-full rounded-lg border-gray-300 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Message</label>
                        <textarea name="body" rows="3" maxlength="500" required
                            class="w-full rounded-lg border-gray-300 text-sm"
                            placeholder="Short message the user will see on their phone">{{ old('body') }}</textarea>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Open screen (optional)</label>
                        <select name="screen" class="w-full rounded-lg border-gray-300 text-sm">
                            <option value="">Default</option>
                            <option value="home" @selected(old('screen') === 'home')>Home</option>
                            <option value="history" @selected(old('screen') === 'history')>Transaction history</option>
                            <option value="saving" @selected(old('screen') === 'saving')>Savings</option>
                            <option value="card" @selected(old('screen') === 'card')>Virtual card</option>
                            <option value="profile" @selected(old('screen') === 'profile')>Profile</option>
                            <option value="support" @selected(old('screen') === 'support')>Support</option>
                        </select>
                    </div>
                    <button type="submit" class="w-full bg-indigo-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-indigo-700 disabled:opacity-50"
                        @disabled(!($pushStatus['configured'] ?? false) || !($pushStatus['has_token'] ?? false))
                        onclick="return confirm('Send this push notification to {{ $wallet->phone_e164 }}?')">
                        <i class="fas fa-bell mr-1"></i> Send push
                    </button>
                    @if(!($pushStatus['configured'] ?? false))
                        <p class="text-xs text-red-700">Set <code class="bg-red-50 px-1 rounded">CHECKOUTNOW_FCM_PROJECT_ID</code> and <code class="bg-red-50 px-1 rounded">CHECKOUTNOW_FCM_SERVICE_ACCOUNT_JSON</code> in server <code class="bg-red-50 px-1 rounded">.env</code> (service account from Firebase project checkout-now-a2b2f).</p>
                    @elseif(!($pushStatus['has_token'] ?? false))
                        <p class="text-xs text-amber-800">User must sign in on the mobile app and allow notifications.</p>
                    @endif
                </form>
            </div>

            @if(auth('admin')->user()?->canMutateWalletAccounts())
            <div class="bg-white rounded-lg border border-gray-200 p-5 shadow-sm">
                <h3 class="font-semibold text-gray-900 mb-2">Manual chat mode</h3>
                <p class="text-sm text-gray-600 mb-4">
                    Pause automated bot replies so you can message this user directly on WhatsApp.
                    The bot stays silent until the user sends <span class="font-mono font-medium">START BOT</span>
                    or you resume it here.
                </p>
                @if($wallet->isAdminBotPaused())
                    <p class="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 mb-3">
                        Bot is paused for this user. You can chat manually from your WhatsApp inbox.
                    </p>
                @endif
                <form method="POST" action="{{ route('admin.whatsapp-wallet.wallets.bot-pause', $wallet) }}">
                    @csrf
                    @method('PUT')
                    @if($wallet->isAdminBotPaused())
                        <input type="hidden" name="admin_bot_paused" value="0">
                        <button type="submit" class="w-full bg-green-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-green-700">
                            Resume automated bot
                        </button>
                    @else
                        <input type="hidden" name="admin_bot_paused" value="1">
                        <button type="submit" class="w-full bg-amber-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-amber-700"
                            onclick="return confirm('Pause bot auto-replies for this user? You can chat manually until they send START BOT or you resume here.')">
                            Pause bot (manual chat)
                        </button>
                    @endif
                </form>
            </div>
            @elseif($wallet->isAdminBotPaused())
            <div class="bg-white rounded-lg border border-gray-200 p-5 shadow-sm">
                <h3 class="font-semibold text-gray-900 mb-2">Manual chat mode</h3>
                <p class="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
                    Bot is paused for this user (manual chat). Ask an admin to resume if needed.
                </p>
            </div>
            @endif
        </div>
    </div>

    @if(($businessNameRegistrations ?? collect())->isNotEmpty())
        <div class="bg-white rounded-lg border border-gray-200 shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">Business name registrations</h3>
                    @if(($businessNamePendingCount ?? 0) > 0)
                        <p class="text-sm text-amber-800 mt-1">{{ $businessNamePendingCount }} pending review</p>
                    @endif
                </div>
                <a href="{{ route('admin.business-name-registrations.index', ['wallet_id' => $wallet->id]) }}"
                   class="text-sm text-primary hover:underline">View all</a>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-2 text-left text-gray-600">Reference</th>
                            <th class="px-4 py-2 text-left text-gray-600">Proposed name</th>
                            <th class="px-4 py-2 text-left text-gray-600">Status</th>
                            <th class="px-4 py-2 text-left text-gray-600">Progress</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($businessNameRegistrations as $bnr)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-2 font-mono text-xs">{{ $bnr->reference }}</td>
                                <td class="px-4 py-2">{{ $bnr->proposed_name }}</td>
                                <td class="px-4 py-2">{{ $bnr->statusDisplayLabel() }}</td>
                                <td class="px-4 py-2">{{ (int) $bnr->progress_percent }}%</td>
                                <td class="px-4 py-2 text-right">
                                    <a href="{{ route('admin.business-name-registrations.show', $bnr) }}" class="text-primary hover:underline">Review</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <div class="bg-white rounded-lg border border-gray-200 shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-900">Recent transactions</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-gray-600">ID</th>
                        <th class="px-4 py-2 text-left text-gray-600">Date</th>
                        <th class="px-4 py-2 text-left text-gray-600">Type</th>
                        <th class="px-4 py-2 text-right text-gray-600">Amount</th>
                        <th class="px-4 py-2 text-left text-gray-600">Counterparty</th>
                        <th class="px-4 py-2 text-left text-gray-600">Payout</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($recentTx as $txn)
                        @php $bucket = $txn->payoutBucketLabel(); @endphp
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2">#{{ $txn->id }}</td>
                            <td class="px-4 py-2 whitespace-nowrap text-gray-600">{{ $txn->created_at?->format('M j, H:i') }}</td>
                            <td class="px-4 py-2">{{ str_replace('_', ' ', $txn->type) }}</td>
                            <td class="px-4 py-2 text-right font-medium">₦{{ number_format((float) $txn->amount, 2) }}</td>
                            <td class="px-4 py-2 font-mono text-xs">{{ $txn->counterparty_phone_e164 ?? $txn->counterparty_account_name ?? $txn->counterparty_account_number ?? '—' }}</td>
                            <td class="px-4 py-2">
                                @if($txn->type === \App\Models\WalletTransaction::TYPE_BANK_TRANSFER_OUT)
                                    <span class="inline-flex px-2 py-0.5 rounded text-xs {{ $bucketBadge($bucket) }}">{{ ucfirst($bucket) }}</span>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-2 text-right">
                                <a href="{{ route('admin.whatsapp-wallet.transactions.show', $txn) }}" class="text-primary hover:underline">View</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-8 text-center text-gray-500">No transactions yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
