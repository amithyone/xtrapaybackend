<?php

namespace Database\Seeders;

use App\Models\Xtrapay\XtrapayBeneficiary;
use App\Models\Xtrapay\XtrapayTransaction;
use App\Models\Xtrapay\XtrapayUser;
use App\Models\Xtrapay\XtrapayWallet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class XtrapayDemoSeeder extends Seeder
{
    public function run(): void
    {
        $user = XtrapayUser::updateOrCreate(
            ['phone' => '08034129981'],
            [
                'full_name' => 'Innocent Solomon',
                'email' => 'innocent.solomon@xtrapay.ng',
                'password' => Hash::make('password'),
                'pin_hash' => Hash::make('1234'),
                'address' => '12 Admiralty Way, Lekki Phase 1, Lagos',
                'city' => 'Lagos',
                'state' => 'Lagos',
                'date_of_birth' => '1992-03-14',
                'gender' => 'male',
                'tier' => 'Tier 3',
                'id_type' => 'bvn',
                'id_number' => '22188418841',
                'kyc_status' => 'verified',
                'agent_code' => 'AG-1003925',
                'daily_spent' => 2450000,
                'daily_limit' => 5000000,
                'flexible_savings' => 214558.04,
                'strict_savings' => 2250,
                'overdraft_limit' => 150000,
                'xpoints_balance' => 1280,
            ]
        );

        $wallets = [
            ['id' => 'personal-'.$user->id, 'name' => 'Personal account', 'kind' => 'personal', 'account_number' => '0124892019', 'bank_name' => 'Zenith Bank', 'balance' => 7777777.77, 'subtitle' => 'Main wallet'],
            ['id' => 'business-'.$user->id, 'name' => 'Business account', 'kind' => 'business', 'account_number' => '2048991204', 'bank_name' => 'Providus Bank', 'balance' => 14250000, 'subtitle' => 'Main business'],
            ['id' => 'sub-1-'.$user->id, 'name' => 'Rent wallet', 'kind' => 'sub_personal', 'account_number' => '0124892201', 'bank_name' => 'Zenith Bank', 'balance' => 125000, 'subtitle' => 'Sub-account · Personal'],
            ['id' => 'sub-2-'.$user->id, 'name' => 'Market stall', 'kind' => 'sub_business', 'account_number' => '2048991308', 'bank_name' => 'Providus Bank', 'balance' => 482450.5, 'subtitle' => 'Sub-account · Mini business'],
        ];

        foreach ($wallets as $w) {
            XtrapayWallet::updateOrCreate(
                ['id' => $w['id']],
                array_merge($w, ['user_id' => $user->id, 'currency' => 'NGN'])
            );
        }

        $this->call(XtrapayBanksSeeder::class);

        $bens = [
            ['id' => 'ben-1-'.$user->id, 'name' => 'Solomon', 'initials' => 'SO', 'bank' => 'GTBank', 'account_number' => '0123984521', 'tier' => 'Tier 3', 'color_class' => 'text-[#c0c1ff]'],
            ['id' => 'ben-2-'.$user->id, 'name' => "Ama's Hub", 'initials' => 'AP', 'bank' => 'Zenith Bank', 'account_number' => '2048991204', 'tier' => 'Tier 3', 'color_class' => 'text-[#4edea3]'],
        ];
        foreach ($bens as $b) {
            XtrapayBeneficiary::updateOrCreate(
                ['id' => $b['id']],
                array_merge($b, ['user_id' => $user->id])
            );
        }

        if ($user->transactions()->count() === 0) {
            $personalId = 'personal-'.$user->id;
            $rows = [
                ['title' => 'Transfer to Adekunle Olumide', 'subtitle' => 'Access Bank • 11:15', 'amount' => 50000, 'type' => 'debit', 'status' => 'Successful', 'category' => 'transfer', 'bank' => 'Access Bank Plc', 'recipient' => 'Adekunle Olumide', 'note' => 'Project Milestone 2 Settlement', 'hours' => 3],
                ['title' => 'Received from Sarah O.', 'subtitle' => 'Xtrapay Direct Inflow • 09:40', 'amount' => 120000, 'type' => 'credit', 'status' => 'Settled', 'category' => 'p2p', 'recipient' => 'Innocent Solomon', 'note' => 'Instant P2P Cleared', 'hours' => 5],
                ['title' => 'Ikeja Electric Prepaid', 'subtitle' => 'ELECTRICITY', 'amount' => 15000, 'type' => 'debit', 'status' => 'Successful', 'category' => 'bill', 'token' => '4590-2391-4920-1182', 'note' => 'Prepaid token units: 198.4 kWh', 'hours' => 28],
            ];
            foreach ($rows as $r) {
                XtrapayTransaction::create([
                    'id' => 'tx-'.Str::uuid(),
                    'user_id' => $user->id,
                    'wallet_id' => $personalId,
                    'title' => $r['title'],
                    'subtitle' => $r['subtitle'],
                    'occurred_at' => now()->subHours($r['hours']),
                    'amount' => $r['amount'],
                    'type' => $r['type'],
                    'status' => $r['status'],
                    'category' => $r['category'],
                    'reference' => 'XTR-'.random_int(10000000, 99999999),
                    'token' => $r['token'] ?? null,
                    'bank' => $r['bank'] ?? null,
                    'recipient' => $r['recipient'] ?? null,
                    'note' => $r['note'] ?? null,
                ]);
            }
        }

        $this->command?->info('Xtrapay demo user ready: phone 08034129981 / password password / PIN 1234 / OTP 123456');
    }
}
