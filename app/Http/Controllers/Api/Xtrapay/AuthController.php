<?php

namespace App\Http\Controllers\Api\Xtrapay;

use App\Http\Controllers\Controller;
use App\Models\Xtrapay\XtrapayOtp;
use App\Models\Xtrapay\XtrapayRegistration;
use App\Models\Xtrapay\XtrapayUser;
use App\Models\Xtrapay\XtrapayWallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fullName' => 'required|string|max:120',
            'phone' => 'required|string|max:20',
            'email' => 'required|email|max:255',
            'password' => 'required|string|min:6',
        ]);

        $id = (string) Str::uuid();
        XtrapayRegistration::create([
            'id' => $id,
            'full_name' => $data['fullName'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'password' => Hash::make($data['password']),
            'status' => 'basic',
        ]);

        return response()->json([
            'success' => true,
            'data' => ['registrationId' => $id],
        ], 201);
    }

    public function kyc(Request $request): JsonResponse
    {
        $data = $request->validate([
            'registrationId' => 'required|uuid',
            'idType' => 'required|in:bvn,nin',
            'idNumber' => 'required|string|size:11',
            'dateOfBirth' => 'required|date',
            'gender' => 'required|in:male,female',
            'address' => 'required|string|max:255',
            'city' => 'required|string|max:120',
            'state' => 'required|string|max:120',
        ]);

        $reg = XtrapayRegistration::findOrFail($data['registrationId']);
        $reg->update([
            'kyc' => collect($data)->except('registrationId')->all(),
            'status' => 'kyc',
        ]);

        return response()->json(['success' => true, 'message' => 'KYC saved']);
    }

    public function sendOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'destination' => 'required|string',
            'purpose' => 'required|in:register,login,reset',
        ]);

        $code = (string) config('xtrapay.demo_otp', env('XTRAPAY_DEMO_OTP', '123456'));

        XtrapayOtp::where('destination', $data['destination'])
            ->where('purpose', $data['purpose'])
            ->delete();

        XtrapayOtp::create([
            'destination' => $data['destination'],
            'purpose' => $data['purpose'],
            'code' => $code,
            'expires_at' => now()->addMinutes(10),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'OTP sent',
            'data' => [
                // Local only — never expose in production
                'demoCode' => app()->environment('local') ? $code : null,
            ],
        ]);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'destination' => 'required|string',
            'purpose' => 'required|in:register,login,reset',
            'code' => 'required|string',
            'registrationId' => 'nullable|uuid',
        ]);

        $otp = XtrapayOtp::where('destination', $data['destination'])
            ->where('purpose', $data['purpose'])
            ->where('code', $data['code'])
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if (! $otp) {
            return response()->json(['success' => false, 'message' => 'Invalid or expired OTP'], 422);
        }

        $otp->delete();

        if ($data['purpose'] === 'register') {
            $reg = XtrapayRegistration::find($data['registrationId'] ?? null);
            if (! $reg) {
                $reg = XtrapayRegistration::where('phone', $data['destination'])
                    ->orWhere('email', $data['destination'])
                    ->latest()
                    ->first();
            }
            if (! $reg) {
                return response()->json(['success' => false, 'message' => 'Registration not found'], 404);
            }

            $user = $this->createUserFromRegistration($reg);
            $token = $user->createToken('xtrapay-app')->plainTextToken;

            return response()->json([
                'success' => true,
                'data' => [
                    'accessToken' => $token,
                    'tokenType' => 'Bearer',
                    'user' => $user->toProfileArray(),
                ],
            ]);
        }

        $user = XtrapayUser::where('phone', $data['destination'])
            ->orWhere('email', $data['destination'])
            ->first();

        if (! $user) {
            return response()->json(['success' => false, 'message' => 'User not found'], 404);
        }

        $token = $user->createToken('xtrapay-app')->plainTextToken;

        return response()->json([
            'success' => true,
            'data' => [
                'accessToken' => $token,
                'tokenType' => 'Bearer',
                'user' => $user->toProfileArray(),
            ],
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identifier' => 'required|string',
            'password' => 'required|string',
        ]);

        $user = XtrapayUser::where('phone', $data['identifier'])
            ->orWhere('email', $data['identifier'])
            ->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['success' => false, 'message' => 'Invalid credentials'], 401);
        }

        $token = $user->createToken('xtrapay-app')->plainTextToken;

        return response()->json([
            'success' => true,
            'data' => [
                'accessToken' => $token,
                'tokenType' => 'Bearer',
                'user' => $user->toProfileArray(),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['success' => true, 'message' => 'Signed out']);
    }

    public function session(Request $request): JsonResponse
    {
        /** @var XtrapayUser $user */
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'user' => $user->toProfileArray(),
                'wallets' => $user->wallets()->get()->map->toApiArray()->values(),
            ],
        ]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['identifier' => 'required|string']);
        $request->merge([
            'destination' => $data['identifier'],
            'purpose' => 'reset',
        ]);

        return $this->sendOtp($request);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identifier' => 'required|string',
            'otp' => 'required|string',
            'newPassword' => 'required|string|min:6',
        ]);

        $otp = XtrapayOtp::where('destination', $data['identifier'])
            ->where('purpose', 'reset')
            ->where('code', $data['otp'])
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if (! $otp) {
            return response()->json(['success' => false, 'message' => 'Invalid or expired OTP'], 422);
        }

        $user = XtrapayUser::where('phone', $data['identifier'])
            ->orWhere('email', $data['identifier'])
            ->firstOrFail();

        $user->password = $data['newPassword'];
        $user->save();
        $otp->delete();

        $token = $user->createToken('xtrapay-app')->plainTextToken;

        return response()->json([
            'success' => true,
            'data' => [
                'accessToken' => $token,
                'tokenType' => 'Bearer',
                'user' => $user->toProfileArray(),
            ],
        ]);
    }

    public function verifyPin(Request $request): JsonResponse
    {
        $data = $request->validate(['pin' => 'required|string|size:4']);
        /** @var XtrapayUser $user */
        $user = $request->user();
        $demo = (string) env('XTRAPAY_DEMO_PIN', '1234');

        $ok = $user->pin_hash
            ? Hash::check($data['pin'], $user->pin_hash)
            : hash_equals($demo, $data['pin']);

        if (! $ok) {
            return response()->json(['success' => false, 'message' => 'Invalid PIN'], 422);
        }

        return response()->json([
            'success' => true,
            'data' => ['challengeId' => (string) Str::uuid()],
        ]);
    }

    private function createUserFromRegistration(XtrapayRegistration $reg): XtrapayUser
    {
        $kyc = $reg->kyc ?? [];

        $user = new XtrapayUser([
            'full_name' => $reg->full_name,
            'email' => $reg->email,
            'phone' => $reg->phone,
            'address' => $kyc['address'] ?? null,
            'city' => $kyc['city'] ?? null,
            'state' => $kyc['state'] ?? null,
            'date_of_birth' => $kyc['dateOfBirth'] ?? null,
            'gender' => $kyc['gender'] ?? null,
            'id_type' => $kyc['idType'] ?? null,
            'id_number' => $kyc['idNumber'] ?? null,
            'kyc_status' => isset($kyc['idNumber']) ? 'verified' : 'pending',
            'tier' => 'Tier 3',
            'agent_code' => 'AG-1003925',
            'flexible_savings' => 214558.04,
            'strict_savings' => 2250,
            'daily_spent' => 2450000,
            'pin_hash' => Hash::make(env('XTRAPAY_DEMO_PIN', '1234')),
        ]);
        $user->password = $reg->password; // already hashed
        $user->save();

        $this->seedDefaultWallets($user);
        $reg->update(['status' => 'verified']);

        return $user->fresh('wallets');
    }

    private function seedDefaultWallets(XtrapayUser $user): void
    {
        $seeds = [
            ['id' => 'personal', 'name' => 'Personal account', 'kind' => 'personal', 'account_number' => '0124892019', 'bank_name' => 'Zenith Bank', 'balance' => 4850240, 'subtitle' => 'Main wallet'],
            ['id' => 'business', 'name' => 'Business account', 'kind' => 'business', 'account_number' => '2048991204', 'bank_name' => 'Providus Bank', 'balance' => 14250000, 'subtitle' => 'Main business'],
            ['id' => 'sub-1', 'name' => 'Rent wallet', 'kind' => 'sub_personal', 'account_number' => '0124892201', 'bank_name' => 'Zenith Bank', 'balance' => 125000, 'subtitle' => 'Sub-account · Personal'],
            ['id' => 'sub-2', 'name' => 'Market stall', 'kind' => 'sub_business', 'account_number' => '2048991308', 'bank_name' => 'Providus Bank', 'balance' => 482450.5, 'subtitle' => 'Sub-account · Mini business'],
        ];

        foreach ($seeds as $w) {
            XtrapayWallet::create(array_merge($w, [
                'id' => $w['id'].'-'.$user->id,
                'user_id' => $user->id,
                'currency' => 'NGN',
            ]));
        }
    }
}
