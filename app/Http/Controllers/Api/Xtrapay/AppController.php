<?php

namespace App\Http\Controllers\Api\Xtrapay;

use App\Http\Controllers\Controller;
use App\Models\Xtrapay\XtrapayBank;
use App\Models\Xtrapay\XtrapayBeneficiary;
use App\Models\Xtrapay\XtrapayTransaction;
use App\Models\Xtrapay\XtrapayTransfer;
use App\Models\Xtrapay\XtrapayUser;
use App\Models\Xtrapay\XtrapayWallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AppController extends Controller
{
    public function bootstrap(Request $request): JsonResponse
    {
        /** @var XtrapayUser $user */
        $user = $request->user();
        $wallets = $user->wallets()->get();
        $personal = $wallets->firstWhere('kind', 'personal') ?? $wallets->first();

        return response()->json([
            'success' => true,
            'data' => [
                'user' => $user->toProfileArray(),
                'wallets' => $wallets->map->toApiArray()->values(),
                'selectedWalletId' => $personal?->id,
                'limits' => [
                    'dailySpendCap' => (float) $user->daily_limit,
                    'dailySpent' => (float) $user->daily_spent,
                    'singleTxnCap' => (float) $user->single_txn_cap,
                    'transferCap' => (float) $user->transfer_cap,
                    'posFloatCap' => (float) $user->pos_float_cap,
                ],
                'savings' => [
                    'flexibleBalance' => (float) $user->flexible_savings,
                    'strictBalance' => (float) $user->strict_savings,
                    'strictAutoSave' => (bool) $user->strict_auto_save,
                ],
                'overdraftLimit' => (float) $user->overdraft_limit,
                'recentTransactions' => $user->transactions()
                    ->orderByDesc('occurred_at')
                    ->limit(20)
                    ->get()
                    ->map->toApiArray()
                    ->values(),
                'cardFrozen' => (bool) $user->card_frozen,
                'xpointsBalance' => (float) $user->xpoints_balance,
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $request->user()->toProfileArray(),
        ]);
    }

    public function updateMe(Request $request): JsonResponse
    {
        /** @var XtrapayUser $user */
        $user = $request->user();
        $data = $request->validate([
            'fullName' => 'sometimes|string|max:120',
            'email' => 'sometimes|email',
            'phone' => 'sometimes|string|max:20',
            'address' => 'sometimes|string|max:255',
            'preferences' => 'sometimes|array',
        ]);

        $user->fill([
            'full_name' => $data['fullName'] ?? $user->full_name,
            'email' => $data['email'] ?? $user->email,
            'phone' => $data['phone'] ?? $user->phone,
            'address' => $data['address'] ?? $user->address,
            'preferences' => $data['preferences'] ?? $user->preferences,
        ])->save();

        return response()->json(['success' => true, 'data' => $user->toProfileArray()]);
    }

    public function wallets(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $request->user()->wallets()->get()->map->toApiArray()->values(),
        ]);
    }

    public function transactions(Request $request): JsonResponse
    {
        $limit = min(100, max(1, (int) $request->query('limit', 40)));
        $rows = $request->user()->transactions()
            ->when($request->query('category'), function ($q) use ($request) {
                $q->where('category', $request->query('category'));
            })
            ->orderByDesc('occurred_at')
            ->limit($limit)
            ->get()
            ->map->toApiArray()
            ->values();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function banks(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => XtrapayBank::orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }

    public function nameEnquiry(
        Request $request,
        \App\Services\WhatsappWalletBankPayoutService $bankPayout
    ): JsonResponse {
        $data = $request->validate([
            'accountNumber' => 'required|string|min:10|max:10',
            'bankCode' => 'nullable|string',
            'bankName' => 'nullable|string',
        ]);

        $accountNumber = preg_replace('/\D/', '', $data['accountNumber']) ?? '';
        if (strlen($accountNumber) !== 10) {
            return response()->json(['success' => false, 'message' => 'Enter a valid 10-digit account number'], 422);
        }

        $bankCode = trim((string) ($data['bankCode'] ?? ''));
        $bankName = trim((string) ($data['bankName'] ?? ''));

        if ($bankCode === '' && $bankName !== '') {
            $bank = XtrapayBank::query()
                ->where('name', $bankName)
                ->orWhere('name', 'like', $bankName.'%')
                ->orderByRaw('CASE WHEN name = ? THEN 0 ELSE 1 END', [$bankName])
                ->orderBy('name')
                ->first();
            $bankCode = (string) ($bank?->code ?? '');
        }

        if ($bankCode === '') {
            return response()->json(['success' => false, 'message' => 'Select a destination bank'], 422);
        }

        if (! $bankPayout->isNameEnquiryAvailable()) {
            return response()->json([
                'success' => false,
                'message' => 'Name enquiry is not configured (MevonPay/NUBAN)',
            ], 503);
        }

        $ne = $bankPayout->nameEnquiry($bankCode, $accountNumber);
        if (! $ne || empty($ne['account_name'])) {
            return response()->json([
                'success' => false,
                'message' => 'Could not resolve account name for this bank account',
            ], 404);
        }

        $resolvedBank = XtrapayBank::query()->where('code', $ne['bank_code'] ?? $bankCode)->first();

        return response()->json([
            'success' => true,
            'data' => [
                'accountNumber' => $accountNumber,
                'accountName' => strtoupper(trim((string) $ne['account_name'])),
                'bankName' => $bankName !== '' ? $bankName : ($resolvedBank?->name ?? null),
                'bankCode' => (string) ($ne['bank_code'] ?? $bankCode),
            ],
        ]);
    }

    public function beneficiaries(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $request->user()->beneficiaries()->get()->map->toApiArray()->values(),
        ]);
    }

    public function limits(Request $request): JsonResponse
    {
        /** @var XtrapayUser $user */
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'dailySpendCap' => (float) $user->daily_limit,
                'dailySpent' => (float) $user->daily_spent,
                'singleTxnCap' => (float) $user->single_txn_cap,
                'transferCap' => (float) $user->transfer_cap,
                'posFloatCap' => (float) $user->pos_float_cap,
            ],
        ]);
    }

    public function updateLimits(Request $request): JsonResponse
    {
        $data = $request->validate([
            'dailySpendCap' => 'sometimes|numeric|min:1000',
            'singleTxnCap' => 'sometimes|numeric|min:1000',
            'transferCap' => 'sometimes|numeric|min:1000',
            'posFloatCap' => 'sometimes|numeric|min:1000',
            'pin' => 'required|string|size:4',
        ]);

        /** @var XtrapayUser $user */
        $user = $request->user();
        $demo = (string) env('XTRAPAY_DEMO_PIN', '1234');
        if ($data['pin'] !== $demo && ! ($user->pin_hash && \Hash::check($data['pin'], $user->pin_hash))) {
            return response()->json(['success' => false, 'message' => 'Invalid PIN'], 422);
        }

        $user->fill([
            'daily_limit' => $data['dailySpendCap'] ?? $user->daily_limit,
            'single_txn_cap' => $data['singleTxnCap'] ?? $user->single_txn_cap,
            'transfer_cap' => $data['transferCap'] ?? $user->transfer_cap,
            'pos_float_cap' => $data['posFloatCap'] ?? $user->pos_float_cap,
        ])->save();

        return $this->limits($request);
    }

    public function createTransfer(
        Request $request,
        \App\Services\WhatsappWalletBankPayoutService $bankPayout,
        \App\Services\MavonPayTransferService $mevonTransfers
    ): JsonResponse {
        $data = $request->validate([
            'walletId' => 'nullable|string',
            'accountNumber' => 'required|string|min:10|max:10',
            'bankName' => 'required|string',
            'bankCode' => 'nullable|string',
            'amount' => 'required|numeric|min:1',
            'recipientName' => 'required|string',
            'narration' => 'nullable|string|max:255',
            'pin' => 'required|string|size:4',
        ]);

        if (! config('xtrapay.live_transfers')) {
            return response()->json([
                'success' => false,
                'message' => 'Live transfers are disabled. Set XTRAPAY_LIVE_TRANSFERS=true in .error',
            ], 503);
        }

        $amount = (float) $data['amount'];
        $max = (float) config('xtrapay.live_transfer_max', 0);
        if ($max > 0 && $amount > $max) {
            return response()->json([
                'success' => false,
                'message' => 'Amount exceeds local live transfer max of ₦'.number_format($max, 2),
            ], 422);
        }

        /** @var XtrapayUser $user */
        $user = $request->user();
        $demo = (string) config('xtrapay.demo_pin', '1234');
        if ($data['pin'] !== $demo && ! ($user->pin_hash && \Hash::check($data['pin'], $user->pin_hash))) {
            return response()->json(['success' => false, 'message' => 'Invalid PIN'], 422);
        }

        if (! $mevonTransfers->isConfigured()) {
            return response()->json([
                'success' => false,
                'message' => 'MevonPay is not configured for live transfers',
            ], 503);
        }

        $accountNumber = preg_replace('/\D/', '', $data['accountNumber']) ?? '';
        if (strlen($accountNumber) !== 10) {
            return response()->json(['success' => false, 'message' => 'Enter a valid 10-digit account number'], 422);
        }

        $bankCode = trim((string) ($data['bankCode'] ?? ''));
        $bankName = trim((string) $data['bankName']);
        if ($bankCode === '') {
            $bank = XtrapayBank::query()
                ->where('name', $bankName)
                ->orWhere('name', 'like', $bankName.'%')
                ->orderByRaw('CASE WHEN name = ? THEN 0 ELSE 1 END', [$bankName])
                ->orderBy('name')
                ->first();
            $bankCode = (string) ($bank?->code ?? '');
        }
        if ($bankCode === '') {
            return response()->json(['success' => false, 'message' => 'Select a destination bank'], 422);
        }

        $wallet = $data['walletId']
            ? $user->wallets()->where('id', $data['walletId'])->first()
            : $user->wallets()->where('kind', 'personal')->first();

        if (! $wallet || (float) $wallet->balance < $amount) {
            return response()->json(['success' => false, 'message' => 'Insufficient funds'], 422);
        }

        if (! $bankPayout->isNameEnquiryAvailable()) {
            return response()->json([
                'success' => false,
                'message' => 'Name enquiry is not configured (MevonPay/NUBAN)',
            ], 503);
        }

        $ne = $bankPayout->nameEnquiry($bankCode, $accountNumber);
        if (! $ne || empty($ne['account_name'])) {
            return response()->json([
                'success' => false,
                'message' => 'Could not verify account name before transfer',
            ], 422);
        }

        $resolvedName = strtoupper(trim((string) $ne['account_name']));
        $providedName = strtoupper(trim((string) $data['recipientName']));
        $norm = static fn (string $s) => preg_replace('/\s+/', ' ', $s) ?? $s;
        if ($norm($resolvedName) !== $norm($providedName)) {
            return response()->json([
                'success' => false,
                'message' => 'Recipient name does not match name enquiry ('.$resolvedName.')',
                'data' => ['accountName' => $resolvedName],
            ], 422);
        }

        $bankCode = (string) ($ne['bank_code'] ?? $bankCode);
        $now = now();
        $ref = 'XTR-'.strtoupper(\Illuminate\Support\Str::random(10));
        $transferId = 'tr-'.Str::uuid();
        $sessionId = 'XTR'.$now->format('YmdHis').strtoupper(\Illuminate\Support\Str::random(4));
        $narration = $data['narration'] ?? ('Xtrapay transfer '.$ref);

        $mevon = $mevonTransfers->createTransfer([
            'amount' => $amount,
            'bankCode' => \App\Services\NigerianBankCodeNormalizer::toNipTransferCode($bankCode),
            'bankName' => $bankName,
            'creditAccountName' => $resolvedName,
            'creditAccountNumber' => $accountNumber,
            'narration' => $narration,
            'reference' => $ref,
            'sessionId' => $sessionId,
        ]);

        $bucket = (string) ($mevon['bucket'] ?? \App\Services\MavonPayTransferService::BUCKET_FAILED);
        $providerMessage = (string) ($mevon['response_message'] ?? 'Transfer failed at provider');
        $providerRef = (string) ($mevon['reference'] ?? $ref);

        if ($bucket !== \App\Services\MavonPayTransferService::BUCKET_SUCCESSFUL) {
            return response()->json([
                'success' => false,
                'message' => $providerMessage !== ''
                    ? $providerMessage
                    : 'MevonPay did not confirm a successful transfer; wallet was not debited',
                'data' => [
                    'bucket' => $bucket,
                    'reference' => $providerRef,
                    'responseCode' => $mevon['response_code'] ?? null,
                    'httpStatus' => $mevon['http_status'] ?? null,
                    'isComplete' => false,
                ],
            ], 502);
        }

        $wallet->balance = (float) $wallet->balance - $amount;
        $wallet->save();

        $user->daily_spent = (float) $user->daily_spent + $amount;
        $user->save();

        $transfer = XtrapayTransfer::create([
            'id' => $transferId,
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'step' => 3,
            'amount' => $amount,
            'recipient_name' => $resolvedName,
            'bank_name' => $bankName,
            'account_number' => $accountNumber,
            'reference' => $providerRef,
            'narration' => $narration,
            'init_time' => $now->format('H:i:s'),
            'processed_time' => $now->copy()->addSecond()->format('H:i:s'),
            'settled_time' => $now->copy()->addSeconds(2)->format('H:i:s'),
            'is_complete' => true,
        ]);

        XtrapayTransaction::create([
            'id' => 'tx-'.Str::uuid(),
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'title' => 'Transfer to '.$resolvedName,
            'subtitle' => $bankName.' • '.$now->format('H:i'),
            'occurred_at' => $now,
            'amount' => $amount,
            'type' => 'debit',
            'status' => 'Settled',
            'category' => 'transfer',
            'reference' => $providerRef,
            'bank' => $bankName,
            'recipient' => $resolvedName,
            'note' => $narration,
        ]);

        return response()->json([
            'success' => true,
            'data' => array_merge($transfer->toApiArray(), [
                'bucket' => $bucket,
                'walletBalance' => (float) $wallet->balance,
                'responseCode' => $mevon['response_code'] ?? null,
                'providerMessage' => $providerMessage,
            ]),
        ], 201);
    }

    public function showTransfer(string $id, Request $request): JsonResponse
    {
        $transfer = XtrapayTransfer::where('user_id', $request->user()->id)->where('id', $id)->firstOrFail();

        return response()->json(['success' => true, 'data' => $transfer->toApiArray()]);
    }

    public function networkRails(): JsonResponse
    {
        $rails = [
            ['id' => 'nip', 'name' => 'NIP Instant', 'backend' => 'NIBSS · interbank', 'status' => 'Active', 'successRate' => 99.2, 'uptime' => 99.95, 'latencyMs' => 820, 'volume24h' => 128],
            ['id' => 'ussd', 'name' => 'USSD gateway', 'backend' => '*737# · telco hubs', 'status' => 'Active', 'successRate' => 97.4, 'uptime' => 99.1, 'latencyMs' => 1400, 'volume24h' => 44],
            ['id' => 'card', 'name' => 'Card acquiring', 'backend' => 'Visa · Mastercard · Verve', 'status' => 'Active', 'successRate' => 98.6, 'uptime' => 99.95, 'latencyMs' => 1100, 'volume24h' => 61],
            ['id' => 'pos', 'name' => 'POS terminals', 'backend' => 'ISO8583 · float rails', 'status' => 'Active', 'successRate' => 98.1, 'uptime' => 99.95, 'latencyMs' => 950, 'volume24h' => 203],
        ];

        return response()->json([
            'success' => true,
            'data' => array_map(fn ($r) => $r + [
                'lastTrafficAt' => now()->subMinutes(random_int(2, 40))->toIso8601String(),
            ], $rails),
        ]);
    }

    public function status(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'service' => 'Xtrapay API',
            'version' => 'v1',
            'poweredBy' => 'CheckoutNow',
            'time' => now()->toIso8601String(),
        ]);
    }
}
