<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('xtrapay_users', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->string('email')->unique();
            $table->string('phone')->unique();
            $table->string('password');
            $table->string('pin_hash')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 16)->nullable();
            $table->string('tier')->default('Tier 1');
            $table->string('id_type', 8)->nullable();
            $table->string('id_number')->nullable();
            $table->string('kyc_status')->default('none');
            $table->string('agent_code')->nullable();
            $table->json('preferences')->nullable();
            $table->decimal('daily_spent', 18, 2)->default(0);
            $table->decimal('daily_limit', 18, 2)->default(5000000);
            $table->decimal('single_txn_cap', 18, 2)->default(1000000);
            $table->decimal('transfer_cap', 18, 2)->default(500000);
            $table->decimal('pos_float_cap', 18, 2)->default(2000000);
            $table->decimal('overdraft_limit', 18, 2)->default(150000);
            $table->decimal('flexible_savings', 18, 2)->default(0);
            $table->decimal('strict_savings', 18, 2)->default(0);
            $table->boolean('strict_auto_save')->default(true);
            $table->boolean('card_frozen')->default(false);
            $table->decimal('xpoints_balance', 18, 2)->default(0);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('xtrapay_otps', function (Blueprint $table) {
            $table->id();
            $table->string('destination');
            $table->string('purpose'); // register|login|reset
            $table->string('code', 12);
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['destination', 'purpose']);
        });

        Schema::create('xtrapay_registrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('full_name');
            $table->string('email');
            $table->string('phone');
            $table->string('password');
            $table->json('kyc')->nullable();
            $table->string('status')->default('basic'); // basic|kyc|verified
            $table->timestamps();
        });

        Schema::create('xtrapay_wallets', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->constrained('xtrapay_users')->cascadeOnDelete();
            $table->string('name');
            $table->string('kind'); // personal|business|sub_personal|sub_business
            $table->string('account_number');
            $table->string('bank_name');
            $table->decimal('balance', 18, 2)->default(0);
            $table->string('subtitle')->nullable();
            $table->string('currency', 8)->default('NGN');
            $table->timestamps();
        });

        Schema::create('xtrapay_transactions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->constrained('xtrapay_users')->cascadeOnDelete();
            $table->string('wallet_id')->nullable();
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->timestamp('occurred_at');
            $table->decimal('amount', 18, 2);
            $table->string('type'); // debit|credit
            $table->string('status')->default('Settled');
            $table->string('category');
            $table->string('reference')->unique();
            $table->string('token')->nullable();
            $table->string('bank')->nullable();
            $table->string('recipient')->nullable();
            $table->string('card_last4')->nullable();
            $table->string('card_usd_amount')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'occurred_at']);
        });

        Schema::create('xtrapay_banks', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->string('code');
            $table->timestamps();
        });

        Schema::create('xtrapay_beneficiaries', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->constrained('xtrapay_users')->cascadeOnDelete();
            $table->string('name');
            $table->string('initials', 8)->nullable();
            $table->string('bank');
            $table->string('account_number');
            $table->string('tier')->nullable();
            $table->string('color_class')->nullable();
            $table->timestamps();
        });

        Schema::create('xtrapay_transfers', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->constrained('xtrapay_users')->cascadeOnDelete();
            $table->string('wallet_id')->nullable();
            $table->unsignedTinyInteger('step')->default(1);
            $table->decimal('amount', 18, 2);
            $table->string('recipient_name');
            $table->string('bank_name');
            $table->string('account_number');
            $table->string('reference')->unique();
            $table->string('narration')->nullable();
            $table->string('init_time')->nullable();
            $table->string('processed_time')->nullable();
            $table->string('settled_time')->nullable();
            $table->boolean('is_complete')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('xtrapay_transfers');
        Schema::dropIfExists('xtrapay_beneficiaries');
        Schema::dropIfExists('xtrapay_banks');
        Schema::dropIfExists('xtrapay_transactions');
        Schema::dropIfExists('xtrapay_wallets');
        Schema::dropIfExists('xtrapay_registrations');
        Schema::dropIfExists('xtrapay_otps');
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('xtrapay_users');
    }
};
