<?php

use App\Http\Controllers\Admin\AdminAnnouncementController;
use App\Http\Controllers\Admin\AdminSidebarMenuController;
use App\Http\Controllers\Admin\AuditsController;
use App\Http\Controllers\Admin\MevonPayAuditController;
use App\Http\Controllers\Admin\MevonPayBalanceMonitorController;
use App\Http\Controllers\Admin\AccountNumberController;
use App\Http\Controllers\Admin\BankAccountPrefixController;
use App\Http\Controllers\Admin\BankEmailTemplateController;
use App\Http\Controllers\Admin\BankLogoController;
use App\Http\Controllers\Admin\BusinessNameRegistrationAdminController;
use App\Http\Controllers\Admin\BusinessAccountApplicationAdminController;
use App\Http\Controllers\Admin\BusinessController;
use App\Http\Controllers\Admin\BusinessKycController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DeveloperProgramController;
use App\Http\Controllers\Admin\EmailAccountController;
use App\Http\Controllers\Admin\ExternalApiController;
use App\Http\Controllers\Admin\GmailAuthController;
use App\Http\Controllers\Admin\PaymentController;
use App\Http\Controllers\Admin\PaymentImportController;
use App\Http\Controllers\Admin\ProcessedEmailController;
use App\Http\Controllers\Admin\RenterController;
use App\Http\Controllers\Admin\RenterKycController;
use App\Http\Controllers\Admin\StatsController;
use App\Http\Controllers\Admin\VirtualCardAdminController;
use App\Http\Controllers\Admin\ConsumerAppSessionAdminController;
use App\Http\Controllers\Admin\RentalsAdminAppSessionAdminController;
use App\Http\Controllers\Admin\WhatsappWalletAdminController;
use App\Http\Controllers\Admin\WhatsappWalletTransactionAdminController;
use App\Http\Controllers\Admin\WhatsappWalletMoneyRequestAdminController;
use App\Http\Controllers\Admin\WhatsappSaveTogetherAdminController;
use App\Http\Controllers\Admin\HoneypotAdminController;
use App\Http\Controllers\Admin\WithdrawalController;
use Illuminate\Support\Facades\Route;

Route::prefix(\App\Support\AdminPath::prefix())->name('admin.')->group(function () {
    // Admin authentication routes
    Route::get('/login', [\App\Http\Controllers\Admin\Auth\LoginController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [\App\Http\Controllers\Admin\Auth\LoginController::class, 'login'])
        ->middleware('throttle:admin-login');
    Route::post('/logout', [\App\Http\Controllers\Admin\Auth\LoginController::class, 'logout'])->name('logout');

    // Protected admin routes (tax-role admins use NigTax /admin only)
    Route::middleware(['auth:admin', 'tax_admin_redirect', 'restrict_wallet_support'])->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/dashboard/mevon-balances', [DashboardController::class, 'mevonBalances'])->name('dashboard.mevon-balances');
        Route::put('sidebar-menu-order', [AdminSidebarMenuController::class, 'update'])->name('sidebar-menu-order.update');
        Route::delete('sidebar-menu-order', [AdminSidebarMenuController::class, 'reset'])->name('sidebar-menu-order.reset');
        Route::post('/extract-missing-names', [DashboardController::class, 'extractMissingNames'])->name('extract-missing-names');
        Route::post('/test-sender-extraction', [DashboardController::class, 'testSenderExtraction'])->name('test-sender-extraction');

        // Statistics
        Route::get('/stats', [StatsController::class, 'index'])->name('stats.index');

        // Processed Emails (Inbox)
        Route::get('processed-emails', [ProcessedEmailController::class, 'index'])->name('processed-emails.index');
        Route::get('processed-emails/{processedEmail}', [ProcessedEmailController::class, 'show'])->name('processed-emails.show');
        Route::post('processed-emails/{processedEmail}/check-match', [ProcessedEmailController::class, 'checkMatch'])->name('processed-emails.check-match');
        Route::post('processed-emails/{processedEmail}/update-name', [ProcessedEmailController::class, 'updateName'])->name('processed-emails.update-name');
        Route::post('processed-emails/{processedEmail}/update-and-rematch', [ProcessedEmailController::class, 'updateAndRematch'])->name('processed-emails.update-and-rematch');
        Route::post('processed-emails/{processedEmail}/update-amount', [ProcessedEmailController::class, 'updateAmount'])->name('processed-emails.update-amount');
        Route::post('processed-emails/{processedEmail}/update-amount-and-rematch', [ProcessedEmailController::class, 'updateAmountAndRematch'])->name('processed-emails.update-amount-and-rematch');
        Route::get('processed-emails/{processedEmail}/pending-payments', [ProcessedEmailController::class, 'getPendingPayments'])->name('processed-emails.pending-payments');
        Route::post('processed-emails/{processedEmail}/match-to-payment', [ProcessedEmailController::class, 'matchToPayment'])->name('processed-emails.match-to-payment');

        // Email Accounts (Admin/Super Admin only)
        Route::middleware('admin_or_super')->group(function () {
            Route::resource('email-accounts', EmailAccountController::class);
            Route::post('email-accounts/{emailAccount}/test-connection', [EmailAccountController::class, 'testConnection'])
                ->name('email-accounts.test-connection');

            // Gmail API Authorization
            Route::get('email-accounts/{emailAccount}/gmail/authorize', [GmailAuthController::class, 'authorize'])
                ->name('email-accounts.gmail.authorize');
            Route::get('email-accounts/{emailAccount}/gmail/callback', [GmailAuthController::class, 'callback'])
                ->name('email-accounts.gmail.callback');

            // Account Numbers
            Route::resource('account-numbers', AccountNumberController::class);
            Route::patch('account-numbers/{accountNumber}/flags', [AccountNumberController::class, 'updateFlags'])->name('account-numbers.update-flags');
            Route::post('account-numbers/validate-account', [AccountNumberController::class, 'validateAccount'])
                ->name('account-numbers.validate-account');

            // External APIs
            Route::get('external-apis', [ExternalApiController::class, 'index'])->name('external-apis.index');
            Route::post('external-apis', [ExternalApiController::class, 'store'])->name('external-apis.store');
            Route::put('external-apis/{externalApi}/businesses', [ExternalApiController::class, 'updateBusinesses'])->name('external-apis.update-businesses');
            Route::get('external-apis/mevonpay/webhook-sources', [ExternalApiController::class, 'mevonpayWebhookSources'])
                ->middleware('super_admin')
                ->name('external-apis.mevonpay-webhook-sources');
        });

        // Businesses
        Route::resource('businesses', BusinessController::class);
        Route::post('businesses/{business}/regenerate-api-key', [BusinessController::class, 'regenerateApiKey'])
            ->name('businesses.regenerate-api-key');
        Route::post('businesses/{business}/approve-website', [BusinessController::class, 'approveWebsite'])
            ->name('businesses.approve-website');
        Route::post('businesses/{business}/reject-website', [BusinessController::class, 'rejectWebsite'])
            ->name('businesses.reject-website');
        Route::post('businesses/{business}/add-website', [BusinessController::class, 'addWebsite'])
            ->name('businesses.add-website');
        Route::put('businesses/{business}/websites/{website}', [BusinessController::class, 'updateWebsite'])
            ->name('businesses.update-website');
        Route::delete('businesses/{business}/websites/{website}', [BusinessController::class, 'deleteWebsite'])
            ->name('businesses.delete-website');
        Route::get('businesses/{business}/websites/{website}/transactions/preview', [BusinessController::class, 'previewTransactions'])
            ->middleware('super_admin')
            ->name('businesses.websites.preview-transactions');
        Route::post('businesses/{business}/websites/{website}/transfer-transactions', [BusinessController::class, 'transferTransactions'])
            ->middleware('super_admin')
            ->name('businesses.websites.transfer-transactions');
        Route::post('businesses/{business}/transfer-transactions', [BusinessController::class, 'transferTransactions'])
            ->middleware('super_admin')
            ->name('businesses.transfer-transactions');
        Route::post('businesses/{business}/websites/{website}/toggle-charges', [BusinessController::class, 'toggleWebsiteCharges'])
            ->middleware('super_admin')
            ->name('businesses.websites.toggle-charges');
        Route::post('businesses/{business}/toggle-status', [BusinessController::class, 'toggleStatus'])
            ->name('businesses.toggle-status');
        Route::post('businesses/{business}/toggle-whatsapp-wallet-api', [BusinessController::class, 'toggleWhatsappWalletApi'])
            ->name('businesses.toggle-whatsapp-wallet-api');
        Route::post('businesses/{business}/toggle-own-cac-for-temp-va', [BusinessController::class, 'toggleOwnCacForTempVa'])
            ->name('businesses.toggle-own-cac-for-temp-va');
        Route::post('businesses/{business}/toggle-payout-api', [BusinessController::class, 'togglePayoutApi'])
            ->name('businesses.toggle-payout-api');
        Route::post('businesses/{business}/toggle-card-payments', [BusinessController::class, 'toggleCardPayments'])
            ->name('businesses.toggle-card-payments');
        Route::post('businesses/{business}/toggle-broadcast-pay-at-shop', [BusinessController::class, 'toggleBroadcastPayAtShop'])
            ->name('businesses.toggle-broadcast-pay-at-shop');
        Route::post('businesses/{business}/update-balance', [BusinessController::class, 'updateBalance'])
            ->middleware('super_admin')
            ->name('businesses.update-balance');
        Route::post('businesses/{business}/transfer-balance', [BusinessController::class, 'transferBalance'])
            ->middleware('super_admin')
            ->name('businesses.transfer-balance');
        Route::post('businesses/{business}/overdraft-approve', [BusinessController::class, 'approveOverdraft'])
            ->middleware('super_admin')
            ->name('businesses.overdraft-approve');
        Route::post('businesses/{business}/overdraft-reject', [BusinessController::class, 'rejectOverdraft'])
            ->middleware('super_admin')
            ->name('businesses.overdraft-reject');
        Route::post('businesses/{business}/credit-eligibility', [BusinessController::class, 'updateCreditEligibility'])
            ->middleware('super_admin')
            ->name('businesses.credit-eligibility');

        Route::get('overdraft-applications', [\App\Http\Controllers\Admin\OverdraftApplicationsController::class, 'index'])
            ->name('overdraft-applications.index');
        Route::get('credit-facility-applications', [\App\Http\Controllers\Admin\CreditFacilityApplicationsController::class, 'index'])
            ->name('credit-facility-applications.index');
        Route::post('credit-facility-applications/{creditFacilityRequest}/approve', [\App\Http\Controllers\Admin\CreditFacilityApplicationsController::class, 'approve'])
            ->middleware('super_admin')
            ->name('credit-facility-applications.approve');
        Route::post('credit-facility-applications/{creditFacilityRequest}/reject', [\App\Http\Controllers\Admin\CreditFacilityApplicationsController::class, 'reject'])
            ->middleware('super_admin')
            ->name('credit-facility-applications.reject');
        Route::get('business-payroll', [\App\Http\Controllers\Admin\BusinessPayrollAdminController::class, 'index'])
            ->name('business-payroll.index');

        Route::get('developer-program', [DeveloperProgramController::class, 'index'])->name('developer-program.index');
        Route::put('developer-program/settings', [DeveloperProgramController::class, 'updateSettings'])
            ->middleware('admin_or_super')
            ->name('developer-program.settings.update');
        Route::patch('developer-program/applications/{application}', [DeveloperProgramController::class, 'updateApplication'])->name('developer-program.applications.update');

        Route::middleware('super_admin')->prefix('investor-pitch-access')->name('investor-pitch-access.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\InvestorPitchAccessController::class, 'index'])->name('index');
            Route::post('/', [\App\Http\Controllers\Admin\InvestorPitchAccessController::class, 'store'])->name('store');
            Route::put('{investor_pitch_access}', [\App\Http\Controllers\Admin\InvestorPitchAccessController::class, 'update'])->name('update');
            Route::post('{investor_pitch_access}/regenerate-link', [\App\Http\Controllers\Admin\InvestorPitchAccessController::class, 'regenerateLink'])->name('regenerate');
            Route::delete('{investor_pitch_access}', [\App\Http\Controllers\Admin\InvestorPitchAccessController::class, 'destroy'])->name('destroy');
        });

        Route::middleware('super_admin')->prefix('peer-lending')->name('peer-lending.')->group(function () {
            Route::get('offers', [\App\Http\Controllers\Admin\PeerLendingAdminController::class, 'offersIndex'])->name('offers.index');
            Route::post('offers/{business_lending_offer}/approve', [\App\Http\Controllers\Admin\PeerLendingAdminController::class, 'approveOffer'])->name('offers.approve');
            Route::post('offers/{business_lending_offer}/reject', [\App\Http\Controllers\Admin\PeerLendingAdminController::class, 'rejectOffer'])->name('offers.reject');
            Route::get('loans', [\App\Http\Controllers\Admin\PeerLendingAdminController::class, 'loansIndex'])->name('loans.index');
            Route::get('loans/{loan}/edit', [\App\Http\Controllers\Admin\PeerLendingAdminController::class, 'editLoanRepayment'])->name('loans.edit');
            Route::put('loans/{loan}/repayment', [\App\Http\Controllers\Admin\PeerLendingAdminController::class, 'updateLoanRepayment'])->name('loans.repayment.update');
            Route::post('loans/{loan}/approve', [\App\Http\Controllers\Admin\PeerLendingAdminController::class, 'approveLoan'])->name('loans.approve');
            Route::post('loans/{loan}/reject', [\App\Http\Controllers\Admin\PeerLendingAdminController::class, 'rejectLoan'])->name('loans.reject');
            Route::post('collect-installments', [DashboardController::class, 'collectPeerLoanInstallments'])->name('collect-installments');
        });

        Route::middleware('super_admin')->prefix('desktop-telemetry')->name('desktop-telemetry.')->group(function () {
            Route::get('events', [\App\Http\Controllers\Admin\DesktopTelemetryController::class, 'eventsIndex'])->name('events.index');
            Route::get('events/{event}', [\App\Http\Controllers\Admin\DesktopTelemetryController::class, 'eventShow'])->name('events.show');

            Route::get('policies', [\App\Http\Controllers\Admin\DesktopTelemetryController::class, 'policiesIndex'])->name('policies.index');
            Route::get('policies/create', [\App\Http\Controllers\Admin\DesktopTelemetryController::class, 'policyEdit'])->name('policies.create');
            Route::post('policies', [\App\Http\Controllers\Admin\DesktopTelemetryController::class, 'policyStore'])->name('policies.store');
            Route::get('policies/{policy}/edit', [\App\Http\Controllers\Admin\DesktopTelemetryController::class, 'policyEdit'])->name('policies.edit');
            Route::put('policies/{policy}', [\App\Http\Controllers\Admin\DesktopTelemetryController::class, 'policyUpdate'])->name('policies.update');
            Route::delete('policies/{policy}', [\App\Http\Controllers\Admin\DesktopTelemetryController::class, 'policyDestroy'])->name('policies.destroy');

            Route::get('tokens', [\App\Http\Controllers\Admin\DesktopTelemetryController::class, 'tokensIndex'])->name('tokens.index');
            Route::post('tokens', [\App\Http\Controllers\Admin\DesktopTelemetryController::class, 'tokenStore'])->name('tokens.store');
            Route::post('tokens/{token}/rotate', [\App\Http\Controllers\Admin\DesktopTelemetryController::class, 'tokenRotate'])->name('tokens.rotate');
            Route::post('tokens/{token}/toggle', [\App\Http\Controllers\Admin\DesktopTelemetryController::class, 'tokenToggle'])->name('tokens.toggle');
            Route::delete('tokens/{token}', [\App\Http\Controllers\Admin\DesktopTelemetryController::class, 'tokenDestroy'])->name('tokens.destroy');
        });
        Route::post('businesses/{business}/update-charges', [BusinessController::class, 'updateCharges'])
            ->middleware('super_admin')
            ->name('businesses.update-charges');
        Route::post('businesses/{business}/login-as', [BusinessController::class, 'loginAsBusiness'])
            ->name('businesses.login-as');
        Route::post('businesses/exit-impersonation', [BusinessController::class, 'exitImpersonation'])
            ->name('businesses.exit-impersonation');

        // Business KYC Management
        Route::post('businesses/{business}/verifications/{verification}/approve', [BusinessController::class, 'approveVerification'])
            ->name('businesses.verification.approve');
        Route::post('businesses/{business}/verifications/{verification}/reject', [BusinessController::class, 'rejectVerification'])
            ->name('businesses.verification.reject');
        Route::post('businesses/{business}/retry-pay-in-account', [BusinessController::class, 'retryPayInAccount'])
            ->name('businesses.retry-pay-in-account');
        Route::post('businesses/{business}/clear-pay-in-account', [BusinessController::class, 'clearPayInAccount'])
            ->name('businesses.clear-pay-in-account');
        Route::get('businesses/{business}/verifications/{verification}/download', [BusinessController::class, 'downloadVerificationDocument'])
            ->name('businesses.verification.download');

        // Payments
        Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
        Route::get('payments/needs-review', [PaymentController::class, 'needsReview'])->name('payments.needs-review');
        Route::get('payments/expired', [PaymentController::class, 'expired'])->name('payments.expired');
        Route::get('payments/import', [PaymentImportController::class, 'create'])->name('payments.import');
        Route::get('payments/import/sample.csv', [PaymentImportController::class, 'downloadSample'])->name('payments.import.sample');
        Route::post('payments/import', [PaymentImportController::class, 'store'])->name('payments.import.store');
        Route::post('payments/resend-webhooks-bulk', [PaymentController::class, 'resendWebhooksBulk'])->name('payments.resend-webhooks-bulk');
        Route::post('payments/resend-failed-webhooks', [PaymentController::class, 'resendFailedWebhooksWindow'])->name('payments.resend-failed-webhooks');
        Route::get('payments/{payment}', [PaymentController::class, 'show'])->name('payments.show');
        Route::post('payments/{payment}/check-match', [PaymentController::class, 'checkMatch'])->name('payments.check-match');
        Route::post('payments/{payment}/manual-verify', [PaymentController::class, 'manualVerify'])->name('payments.manual-verify');
        Route::post('payments/{payment}/manual-approve', [PaymentController::class, 'manualApprove'])->name('payments.manual-approve');
        Route::get('payments/{payment}/unmatched-emails', [PaymentController::class, 'getUnmatchedEmails'])->name('payments.unmatched-emails');
        Route::post('payments/{payment}/mark-expired', [PaymentController::class, 'markAsExpired'])->name('payments.mark-expired');
        Route::post('payments/{payment}/resend-webhook', [PaymentController::class, 'resendWebhook'])->name('payments.resend-webhook');
        Route::delete('payments/{payment}', [PaymentController::class, 'destroy'])->name('payments.destroy');

        // Invoices
        Route::get('payment-links', [\App\Http\Controllers\Admin\PaymentLinkController::class, 'index'])->name('payment-links.index');
        Route::get('payment-links/{payment_link}', [\App\Http\Controllers\Admin\PaymentLinkController::class, 'show'])->name('payment-links.show');

        Route::resource('invoices', \App\Http\Controllers\Admin\InvoiceController::class);
        Route::get('invoices/{invoice}/pdf', [\App\Http\Controllers\Admin\InvoiceController::class, 'downloadPdf'])->name('invoices.pdf');
        Route::get('invoices/{invoice}/view-pdf', [\App\Http\Controllers\Admin\InvoiceController::class, 'viewPdf'])->name('invoices.view-pdf');
        Route::post('invoices/{invoice}/send', [\App\Http\Controllers\Admin\InvoiceController::class, 'send'])->name('invoices.send');
        Route::post('invoices/{invoice}/mark-paid', [\App\Http\Controllers\Admin\InvoiceController::class, 'markPaid'])->name('invoices.mark-paid');

        // Charity / GoFund campaigns
        Route::resource('charity', \App\Http\Controllers\Admin\CharityController::class)->parameters(['charity' => 'campaign'])->names('charity');

        // Withdrawals
        Route::get('withdrawals', [WithdrawalController::class, 'index'])->name('withdrawals.index');
        Route::get('withdrawals/create', [WithdrawalController::class, 'create'])->name('withdrawals.create');
        Route::post('withdrawals', [WithdrawalController::class, 'store'])->name('withdrawals.store');
        Route::get('withdrawals/{withdrawal}', [WithdrawalController::class, 'show'])->name('withdrawals.show');
        Route::post('withdrawals/{withdrawal}/approve', [WithdrawalController::class, 'approve'])->name('withdrawals.approve');
        Route::post('withdrawals/{withdrawal}/reject', [WithdrawalController::class, 'reject'])->name('withdrawals.reject');
        Route::post('withdrawals/{withdrawal}/mark-processed', [WithdrawalController::class, 'markProcessed'])->name('withdrawals.mark-processed');

        // Rental users (renters)
        Route::get('renters', [RenterController::class, 'index'])->name('renters.index');
        Route::get('renters/{renter}', [RenterController::class, 'show'])->name('renters.show');
        Route::put('renters/{renter}', [RenterController::class, 'update'])->name('renters.update');
        Route::put('renters/{renter}/balance', [RenterController::class, 'updateBalance'])->name('renters.update-balance');

        // Renters KYC (ID review)
        Route::get('renters-kyc', [RenterKycController::class, 'index'])->name('renters-kyc.index');
        Route::get('renters-kyc/{renter}/document/{type}', [RenterKycController::class, 'document'])->name('renters-kyc.document');
        Route::post('renters-kyc/{renter}/approve', [RenterKycController::class, 'approve'])->name('renters-kyc.approve');
        Route::post('renters-kyc/{renter}/reject', [RenterKycController::class, 'reject'])->name('renters-kyc.reject');
        Route::post('renters-kyc/{renter}/toggle-active', [RenterKycController::class, 'toggleActive'])->name('renters-kyc.toggle-active');

        // Business KYC queue (document review)
        Route::get('businesses-kyc', [BusinessKycController::class, 'index'])->name('businesses-kyc.index');
        Route::get('businesses-kyc/{verification}/document', [BusinessKycController::class, 'document'])->name('businesses-kyc.document');
        Route::post('businesses-kyc/{verification}/verify-identity', [BusinessKycController::class, 'verifyIdentity'])->name('businesses-kyc.verify-identity');

        // Transaction Logs
        Route::get('transaction-logs', [\App\Http\Controllers\Admin\TransactionLogController::class, 'index'])->name('transaction-logs.index');
        Route::get('transaction-logs/{transactionId}', [\App\Http\Controllers\Admin\TransactionLogController::class, 'show'])->name('transaction-logs.show');

        Route::get('api-hits', [\App\Http\Controllers\Admin\ApiHitLogController::class, 'index'])->name('api-hits.index');
        Route::get('api-hits/{apiHit}', [\App\Http\Controllers\Admin\ApiHitLogController::class, 'show'])->name('api-hits.show');

        // Payment provider audits (Admin/Super Admin only)
        Route::middleware('admin_or_super')->group(function () {
            Route::get('audits', [AuditsController::class, 'index'])->name('audits.index');
            Route::get('audits/mevonpay', [MevonPayAuditController::class, 'index'])->name('audits.mevonpay.index');
            Route::get('audits/mevonpay/export', [MevonPayAuditController::class, 'exportCsv'])->name('audits.mevonpay.export');
            Route::get('audits/mevonpay/monitor', [MevonPayBalanceMonitorController::class, 'index'])->name('audits.mevonpay.monitor');
            Route::post('audits/mevonpay/monitor/baseline', [MevonPayBalanceMonitorController::class, 'initializeBaseline'])->name('audits.mevonpay.monitor.baseline');
            Route::post('audits/mevonpay/monitor/baseline/reset', [MevonPayBalanceMonitorController::class, 'resetBaseline'])->name('audits.mevonpay.monitor.baseline.reset');
            Route::post('audits/mevonpay/monitor/check', [MevonPayBalanceMonitorController::class, 'checkNow'])->name('audits.mevonpay.monitor.check');
            Route::redirect('mevonpay-audit', '/'.\App\Support\AdminPath::prefix().'/audits/mevonpay')->name('mevonpay-audit.index');
            Route::get('mevonpay-audit/export', fn () => redirect()->route('admin.audits.mevonpay.export', request()->query()));

            Route::get('honeypot', [HoneypotAdminController::class, 'index'])->name('honeypot.index');
            Route::post('honeypot/ban', [HoneypotAdminController::class, 'ban'])->name('honeypot.ban');
            Route::post('honeypot/unban', [HoneypotAdminController::class, 'unban'])->name('honeypot.unban');
        });

        // Test Transaction (Live Testing)
        Route::get('test-transaction', [\App\Http\Controllers\Admin\TestTransactionController::class, 'index'])->name('test-transaction.index');
        Route::post('test-transaction/create', [\App\Http\Controllers\Admin\TestTransactionController::class, 'createPayment'])->name('test-transaction.create');
        Route::post('test-transaction/create-card', [\App\Http\Controllers\Admin\TestTransactionController::class, 'createCardPayment'])
            ->name('test-transaction.create-card');
        Route::get('test-transaction/status/{transactionId}', [\App\Http\Controllers\Admin\TestTransactionController::class, 'getStatus'])->name('test-transaction.status');
        Route::post('test-transaction/check-email', [\App\Http\Controllers\Admin\TestTransactionController::class, 'checkEmail'])->name('test-transaction.check-email');
        Route::post('test-transaction/mevonpay-temp-va', [\App\Http\Controllers\Admin\TestTransactionController::class, 'createMevonpayTempVa'])
            ->name('test-transaction.mevonpay-temp-va');
        Route::post('test-transaction/mevonpay-dynamic-va', [\App\Http\Controllers\Admin\TestTransactionController::class, 'createMevonpayDynamicVa'])
            ->name('test-transaction.mevonpay-dynamic-va');

        // Settings (Admin/Super Admin only)
        Route::middleware('admin_or_super')->group(function () {
            Route::get('announcements', [AdminAnnouncementController::class, 'index'])->name('announcements.index');
            Route::get('announcements/create', [AdminAnnouncementController::class, 'create'])->name('announcements.create');
            Route::post('announcements', [AdminAnnouncementController::class, 'store'])->name('announcements.store');
            Route::get('announcements/{announcement}', [AdminAnnouncementController::class, 'show'])->name('announcements.show');
            Route::post('announcements/{announcement}/process', [AdminAnnouncementController::class, 'processNow'])->name('announcements.process');

            Route::get('settings', [\App\Http\Controllers\Admin\SettingsController::class, 'index'])->name('settings.index');
            Route::put('settings', [\App\Http\Controllers\Admin\SettingsController::class, 'update'])->name('settings.update');
            Route::post('settings/general', [\App\Http\Controllers\Admin\SettingsController::class, 'updateGeneral'])->name('settings.update-general');
            Route::post('settings/marketing-downloads', [\App\Http\Controllers\Admin\SettingsController::class, 'updateMarketingDownloads'])->name('settings.update-marketing-downloads');
            Route::post('settings/whitelisted-emails', [\App\Http\Controllers\Admin\SettingsController::class, 'addWhitelistedEmail'])->name('settings.add-whitelisted-email');
            Route::delete('settings/whitelisted-emails/{whitelistedEmail}', [\App\Http\Controllers\Admin\SettingsController::class, 'removeWhitelistedEmail'])->name('settings.remove-whitelisted-email');

            // Wallet settings / account mutations (not wallet_support)
            Route::get('whatsapp-wallet/settings', [WhatsappWalletAdminController::class, 'settings'])->name('whatsapp-wallet.settings');
            Route::put('whatsapp-wallet/referrals/settings', [\App\Http\Controllers\Admin\WhatsappWalletReferralAdminController::class, 'updateSettings'])
                ->name('whatsapp-wallet.referrals.settings');
            Route::post('whatsapp-wallet/referrals/notify-launch', [\App\Http\Controllers\Admin\WhatsappWalletReferralAdminController::class, 'notifyLaunch'])
                ->name('whatsapp-wallet.referrals.notify-launch');
            Route::post('whatsapp-wallet/referrals/launch-reach', [\App\Http\Controllers\Admin\WhatsappWalletReferralAdminController::class, 'launchReach'])
                ->name('whatsapp-wallet.referrals.launch-reach');
            Route::put('whatsapp-wallet', [WhatsappWalletAdminController::class, 'update'])->name('whatsapp-wallet.update');
            Route::put('whatsapp-wallet/fx-rates', [WhatsappWalletAdminController::class, 'updateFxRates'])->name('whatsapp-wallet.fx-rates.update');
            Route::put('whatsapp-wallet/wallets/{wallet}/status', [WhatsappWalletAdminController::class, 'updateWalletStatus'])->name('whatsapp-wallet.wallets.status');
            Route::put('whatsapp-wallet/wallets/{wallet}/balance-audit-exempt', [WhatsappWalletAdminController::class, 'updateBalanceAuditExempt'])->name('whatsapp-wallet.wallets.balance-audit-exempt');
            Route::put('whatsapp-wallet/wallets/{wallet}/link-business', [WhatsappWalletAdminController::class, 'linkBusiness'])->name('whatsapp-wallet.wallets.link-business');
            Route::put('whatsapp-wallet/wallets/{wallet}/bot-pause', [WhatsappWalletAdminController::class, 'updateWalletBotPause'])->name('whatsapp-wallet.wallets.bot-pause');
            Route::post('whatsapp-wallet/wallets/{wallet}/transfer-lock/clear', [WhatsappWalletAdminController::class, 'clearTransferLock'])->name('whatsapp-wallet.wallets.transfer-lock.clear');
            Route::post('whatsapp-wallet/wallets/{wallet}/otp-lockout/clear', [WhatsappWalletAdminController::class, 'clearOtpLockout'])->name('whatsapp-wallet.wallets.otp-lockout.clear');
            Route::post('whatsapp-wallet/wallets/{wallet}/queue-pay-in-account', [WhatsappWalletAdminController::class, 'queueWalletPayInAccount'])->name('whatsapp-wallet.wallets.queue-pay-in-account');
            Route::post('whatsapp-wallet/wallets/{wallet}/retry-pay-in-account', [WhatsappWalletAdminController::class, 'retryWalletPayInAccount'])->name('whatsapp-wallet.wallets.retry-pay-in-account');
            Route::put('whatsapp-wallet/wallets/{wallet}/kyc-pay-in', [WhatsappWalletAdminController::class, 'updateWalletKycPayIn'])->name('whatsapp-wallet.wallets.kyc-pay-in');
            // Accept PUT too: the test button lives inside the KYC form that spoofs PUT via _method.
            Route::match(['post', 'put'], 'whatsapp-wallet/wallets/{wallet}/test-mevon-identity', [WhatsappWalletAdminController::class, 'testWalletMevonIdentity'])->name('whatsapp-wallet.wallets.test-mevon-identity');

            Route::put('business-name-registrations/{registration}/status', [BusinessNameRegistrationAdminController::class, 'updateStatus'])
                ->name('business-name-registrations.status');
            Route::put('business-account-applications/{application}/status', [BusinessAccountApplicationAdminController::class, 'updateStatus'])
                ->name('business-account-applications.status');

            Route::post('whatsapp-wallet/transactions/{transaction}/manual-refund', [WhatsappWalletTransactionAdminController::class, 'manualRefund'])->name('whatsapp-wallet.transactions.manual-refund');
            Route::post('whatsapp-wallet/transactions/{transaction}/clawback-false-refund', [WhatsappWalletTransactionAdminController::class, 'clawbackFalseRefund'])->name('whatsapp-wallet.transactions.clawback-false-refund');

            // Virtual card mutations
            Route::post('virtual-cards/refresh-rates', [VirtualCardAdminController::class, 'refreshRates'])->name('virtual-cards.refresh-rates');
            Route::post('virtual-cards/rate-tracker/buy-usd', [VirtualCardAdminController::class, 'buyUsdOnRateTracker'])->name('virtual-cards.rate-tracker.buy-usd');
            Route::post('virtual-cards/rate-tracker/sell-usd', [VirtualCardAdminController::class, 'sellUsdOnRateTracker'])->name('virtual-cards.rate-tracker.sell-usd');
            Route::post('virtual-cards/rate-tracker/refresh', [VirtualCardAdminController::class, 'refreshRateTracker'])->name('virtual-cards.rate-tracker.refresh');
            Route::post('virtual-cards/{virtualCardRequest}/notes', [VirtualCardAdminController::class, 'updateNotes'])->name('virtual-cards.update-notes');
            Route::post('virtual-cards/{virtualCardRequest}/mark-active', [VirtualCardAdminController::class, 'markActive'])->name('virtual-cards.mark-active');
            Route::post('virtual-cards/{virtualCardRequest}/mark-failed', [VirtualCardAdminController::class, 'markFailed'])->name('virtual-cards.mark-failed');
            Route::post('virtual-cards/{virtualCardRequest}/retry', [VirtualCardAdminController::class, 'retry'])->name('virtual-cards.retry');
            Route::post('virtual-cards/{virtualCardRequest}/retry-webhook-sync', [VirtualCardAdminController::class, 'retryWebhookSync'])->name('virtual-cards.retry-webhook-sync');
            Route::post('virtual-cards/{virtualCardRequest}/refund-fee', [VirtualCardAdminController::class, 'refundFee'])->name('virtual-cards.refund-fee');

            // Email Templates
            Route::get('email-templates', [\App\Http\Controllers\Admin\EmailTemplateController::class, 'index'])->name('email-templates.index');
            Route::get('email-templates/{template}/edit', [\App\Http\Controllers\Admin\EmailTemplateController::class, 'edit'])->name('email-templates.edit');
            Route::put('email-templates/{template}', [\App\Http\Controllers\Admin\EmailTemplateController::class, 'update'])->name('email-templates.update');
            Route::post('email-templates/{template}/reset', [\App\Http\Controllers\Admin\EmailTemplateController::class, 'reset'])->name('email-templates.reset');
        });

        // Wallet ops: admin/super + wallet_support (view + check-status + push + passkey reset)
        Route::middleware('wallet_ops')->group(function () {
            Route::get('whatsapp-wallet', [WhatsappWalletAdminController::class, 'index'])->name('whatsapp-wallet.index');
            Route::get('whatsapp-wallet/wallets', [WhatsappWalletAdminController::class, 'wallets'])->name('whatsapp-wallet.wallets.index');
            Route::get('whatsapp-wallet/wallets/{wallet}', [WhatsappWalletAdminController::class, 'showWallet'])->name('whatsapp-wallet.wallets.show');
            Route::post('whatsapp-wallet/wallets/{wallet}/push', [WhatsappWalletAdminController::class, 'sendPushNotification'])->name('whatsapp-wallet.wallets.push');
            Route::post('whatsapp-wallet/wallets/{wallet}/devices/{device}/revoke', [WhatsappWalletAdminController::class, 'revokeTrustedDevice'])->name('whatsapp-wallet.wallets.devices.revoke');
            Route::post('whatsapp-wallet/wallets/{wallet}/devices/reset', [WhatsappWalletAdminController::class, 'resetDeviceRequirement'])->name('whatsapp-wallet.wallets.devices.reset');
            Route::post('whatsapp-wallet/wallets/{wallet}/step-up/clear', [WhatsappWalletAdminController::class, 'clearStepUpSessions'])->name('whatsapp-wallet.wallets.step-up.clear');
            Route::get('whatsapp-wallet/signup-attempts', [WhatsappWalletAdminController::class, 'signupAttempts'])->name('whatsapp-wallet.signup-attempts.index');
            Route::post('whatsapp-wallet/wallets/{wallet}/email-hold/clear', [WhatsappWalletAdminController::class, 'clearWalletEmailHold'])->name('whatsapp-wallet.wallets.email-hold.clear');
            Route::post('whatsapp-wallet/signup-attempts/email-hold/clear', [WhatsappWalletAdminController::class, 'clearSignupEmailHold'])->name('whatsapp-wallet.signup-attempts.email-hold.clear');

            Route::get('business-name-registrations', [BusinessNameRegistrationAdminController::class, 'index'])->name('business-name-registrations.index');
            Route::get('business-name-registrations/{registration}', [BusinessNameRegistrationAdminController::class, 'show'])->name('business-name-registrations.show');
            Route::get('business-name-registrations/{registration}/id-document', [BusinessNameRegistrationAdminController::class, 'idDocument'])->name('business-name-registrations.id-document');

            Route::get('business-account-applications', [BusinessAccountApplicationAdminController::class, 'index'])->name('business-account-applications.index');
            Route::get('business-account-applications/{application}', [BusinessAccountApplicationAdminController::class, 'show'])->name('business-account-applications.show');
            Route::get('business-account-applications/{application}/cac-document', [BusinessAccountApplicationAdminController::class, 'cacDocument'])->name('business-account-applications.cac-document');

            Route::get('app-sessions', [ConsumerAppSessionAdminController::class, 'index'])->name('app-sessions.index');
            Route::get('app-sessions/events', [ConsumerAppSessionAdminController::class, 'events'])->name('app-sessions.events');
            Route::get('app-sessions/{appSession}', [ConsumerAppSessionAdminController::class, 'show'])->name('app-sessions.show');

            Route::get('whatsapp-wallet/transactions', [WhatsappWalletTransactionAdminController::class, 'index'])->name('whatsapp-wallet.transactions.index');
            Route::get('whatsapp-wallet/transactions/p2p', [WhatsappWalletTransactionAdminController::class, 'p2p'])->name('whatsapp-wallet.transactions.p2p');
            Route::get('whatsapp-wallet/money-requests', [WhatsappWalletMoneyRequestAdminController::class, 'index'])->name('whatsapp-wallet.money-requests.index');
            Route::get('whatsapp-wallet/save-together', [WhatsappSaveTogetherAdminController::class, 'index'])->name('whatsapp-wallet.save-together.index');
            Route::get('whatsapp-wallet/referrals', [\App\Http\Controllers\Admin\WhatsappWalletReferralAdminController::class, 'index'])
                ->name('whatsapp-wallet.referrals.index');
            Route::get('whatsapp-wallet/transactions/failed', [WhatsappWalletTransactionAdminController::class, 'failed'])->name('whatsapp-wallet.transactions.failed');
            Route::get('whatsapp-wallet/transactions/pending', [WhatsappWalletTransactionAdminController::class, 'pending'])->name('whatsapp-wallet.transactions.pending');
            Route::get('whatsapp-wallet/transactions/{transaction}', [WhatsappWalletTransactionAdminController::class, 'show'])->name('whatsapp-wallet.transactions.show');
            Route::post('whatsapp-wallet/transactions/{transaction}/check-status', [WhatsappWalletTransactionAdminController::class, 'checkStatus'])->name('whatsapp-wallet.transactions.check-status');
            Route::post('whatsapp-wallet/transactions/{transaction}/check-electricity-status', [WhatsappWalletTransactionAdminController::class, 'checkElectricityStatus'])->name('whatsapp-wallet.transactions.check-electricity-status');

            Route::get('virtual-cards', [VirtualCardAdminController::class, 'index'])->name('virtual-cards.index');
            Route::get('virtual-cards/stats', [VirtualCardAdminController::class, 'stats'])->name('virtual-cards.stats');
            Route::get('virtual-cards/rate-tracker', [VirtualCardAdminController::class, 'rateTracker'])->name('virtual-cards.rate-tracker');
            Route::get('virtual-cards/rate-tracker/data', [VirtualCardAdminController::class, 'rateTrackerData'])->name('virtual-cards.rate-tracker.data');
            Route::get('virtual-cards/logs/events', [VirtualCardAdminController::class, 'logs'])->name('virtual-cards.logs');
            Route::get('virtual-cards/users', [VirtualCardAdminController::class, 'users'])->name('virtual-cards.users');
            Route::get('virtual-cards/{virtualCardRequest}', [VirtualCardAdminController::class, 'show'])->name('virtual-cards.show');
        });

        // Pages Management
        Route::resource('pages', \App\Http\Controllers\Admin\PageController::class);

        // Support Tickets (Live Chat Style)
        Route::get('support', [\App\Http\Controllers\Admin\SupportController::class, 'index'])->name('support.index');
        Route::get('support/inbox', [\App\Http\Controllers\Admin\SupportController::class, 'inbox'])->name('support.inbox');
        Route::get('support/{ticket}', [\App\Http\Controllers\Admin\SupportController::class, 'show'])->name('support.show');
        Route::get('support/{ticket}/messages', [\App\Http\Controllers\Admin\SupportController::class, 'messages'])->name('support.messages');
        Route::post('support/{ticket}/reply', [\App\Http\Controllers\Admin\SupportController::class, 'reply'])->name('support.reply');
        Route::post('support/{ticket}/update-status', [\App\Http\Controllers\Admin\SupportController::class, 'updateStatus'])->name('support.update-status');
        Route::post('support/{ticket}/assign', [\App\Http\Controllers\Admin\SupportController::class, 'assign'])->name('support.assign');

        // Bank Email Templates
        Route::resource('bank-email-templates', BankEmailTemplateController::class);

        // Bank logos (map / upload; does not alter bank codes)
        Route::middleware('admin_or_super')->group(function () {
            Route::get('bank-logos', [BankLogoController::class, 'index'])->name('bank-logos.index');
            Route::post('bank-logos/auto-map', [BankLogoController::class, 'autoMap'])->name('bank-logos.auto-map');
            Route::post('bank-logos/{bank}/upload', [BankLogoController::class, 'upload'])->name('bank-logos.upload');
            Route::post('bank-logos/{bank}/assign', [BankLogoController::class, 'assign'])->name('bank-logos.assign');
            Route::delete('bank-logos/{bank}', [BankLogoController::class, 'clear'])->name('bank-logos.clear');
        });

        // Bank account prefix suggestions (CheckoutNow app fallback API)
        Route::get('bank-account-prefixes', [BankAccountPrefixController::class, 'index'])->name('bank-account-prefixes.index');
        Route::post('bank-account-prefixes', [BankAccountPrefixController::class, 'store'])->name('bank-account-prefixes.store');
        Route::put('bank-account-prefixes/{bankAccountPrefix}', [BankAccountPrefixController::class, 'update'])->name('bank-account-prefixes.update');
        Route::delete('bank-account-prefixes/{bankAccountPrefix}', [BankAccountPrefixController::class, 'destroy'])->name('bank-account-prefixes.destroy');

        // Email Monitoring
        Route::post('email-monitor/fetch', [\App\Http\Controllers\Admin\EmailMonitorController::class, 'fetchEmails'])->name('email-monitor.fetch');
        Route::post('email-monitor/fetch-direct', [\App\Http\Controllers\Admin\EmailMonitorController::class, 'fetchEmailsDirect'])->name('email-monitor.fetch-direct');
        Route::post('email-monitor/check-updates', [\App\Http\Controllers\Admin\EmailMonitorController::class, 'checkTransactionUpdates'])->name('email-monitor.check-updates');

        // Whitelisted Email Addresses
        Route::resource('whitelisted-emails', \App\Http\Controllers\Admin\WhitelistedEmailController::class);

        // Match Attempts (Match Logs)
        Route::get('match-attempts', [\App\Http\Controllers\Admin\MatchAttemptController::class, 'index'])->name('match-attempts.index');
        Route::get('match-attempts/{matchAttempt}', [\App\Http\Controllers\Admin\MatchAttemptController::class, 'show'])->name('match-attempts.show');
        Route::post('match-attempts/{matchAttempt}/retry', [\App\Http\Controllers\Admin\MatchAttemptController::class, 'retry'])->name('match-attempts.retry');
        Route::delete('match-attempts/clear', [\App\Http\Controllers\Admin\MatchAttemptController::class, 'clear'])->name('match-attempts.clear');
        Route::post('processed-emails/{processedEmail}/retry-match', [\App\Http\Controllers\Admin\MatchAttemptController::class, 'retryEmail'])->name('processed-emails.retry-match');
        Route::post('processed-emails/{processedEmail}/re-extract-match', [\App\Http\Controllers\Admin\MatchAttemptController::class, 'reExtractAndMatch'])->name('processed-emails.re-extract-match');

        // Global Match Trigger
        Route::post('match/trigger-global', [\App\Http\Controllers\Admin\MatchController::class, 'triggerGlobalMatch'])->name('match.trigger-global');

        // Staff Management (Super Admin only)
        Route::middleware('super_admin')->group(function () {
            Route::resource('staff', \App\Http\Controllers\Admin\StaffController::class);
            Route::post('staff/{staff}/toggle-status', [\App\Http\Controllers\Admin\StaffController::class, 'toggleStatus'])
                ->name('staff.toggle-status');
        });

        // Profile Management (Super Admin only)
        Route::middleware('super_admin')->group(function () {
            Route::get('profile', [\App\Http\Controllers\Admin\ProfileController::class, 'index'])->name('profile.index');
            Route::put('profile', [\App\Http\Controllers\Admin\ProfileController::class, 'update'])->name('profile.update');
            Route::put('profile/password', [\App\Http\Controllers\Admin\ProfileController::class, 'updatePassword'])->name('profile.update-password');
        });

        // Tickets Management
        Route::prefix('tickets')->name('tickets.')->group(function () {
            Route::get('events', [\App\Http\Controllers\Admin\TicketController::class, 'events'])->name('events.index');
            Route::get('orders', [\App\Http\Controllers\Admin\TicketController::class, 'orders'])->name('orders.index');
            Route::get('orders/{order}', [\App\Http\Controllers\Admin\TicketController::class, 'showOrder'])->name('orders.show');
            Route::post('orders/{order}/refund', [\App\Http\Controllers\Admin\TicketController::class, 'refund'])->name('orders.refund');
            Route::put('events/{event}/max-tickets', [\App\Http\Controllers\Admin\TicketController::class, 'updateMaxTickets'])->name('events.update-max-tickets');

            // QR Scanner
            Route::get('scanner', [\App\Http\Controllers\Admin\TicketScannerController::class, 'index'])->name('scanner');
            Route::post('scanner/verify', [\App\Http\Controllers\Admin\TicketScannerController::class, 'verify'])->name('scanner.verify');
            Route::post('scanner/check-in', [\App\Http\Controllers\Admin\TicketScannerController::class, 'checkIn'])->name('scanner.check-in');
            Route::post('scanner/manual-check-in', [\App\Http\Controllers\Admin\TicketScannerController::class, 'manualCheckIn'])->name('scanner.manual-check-in');
        });

        // Memberships Management
        Route::prefix('memberships')->name('memberships.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\MembershipController::class, 'index'])->name('index');
            Route::get('{membership}', [\App\Http\Controllers\Admin\MembershipController::class, 'show'])->name('show');
            Route::post('{membership}/status', [\App\Http\Controllers\Admin\MembershipController::class, 'updateStatus'])->name('update-status');
            Route::delete('{membership}', [\App\Http\Controllers\Admin\MembershipController::class, 'destroy'])->name('destroy');
        });
        Route::resource('membership-categories', \App\Http\Controllers\Admin\MembershipCategoryController::class);

        // Rentals admin app sessions (mobile / ops app)
        Route::prefix('rentals-app-sessions')->name('rentals-app-sessions.')->group(function () {
            Route::get('/', [RentalsAdminAppSessionAdminController::class, 'index'])->name('index');
            Route::get('/events', [RentalsAdminAppSessionAdminController::class, 'events'])->name('events');
            Route::get('/{appSession}', [RentalsAdminAppSessionAdminController::class, 'show'])->name('show');
        });

        // Rentals Management
        Route::prefix('rentals')->name('rentals.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\RentalController::class, 'index'])->name('index');
            Route::get('/{rental}', [\App\Http\Controllers\Admin\RentalController::class, 'show'])->name('show');
            Route::post('/{rental}/update-status', [\App\Http\Controllers\Admin\RentalController::class, 'updateStatus'])->name('update-status');
            Route::delete('/{rental}', [\App\Http\Controllers\Admin\RentalController::class, 'destroy'])->name('destroy');
        });

        // Rental Categories Management
        Route::resource('rental-categories', \App\Http\Controllers\Admin\RentalCategoryController::class);

        // Rental Items Management
        Route::post('rental-items/clone-catalog', [\App\Http\Controllers\Admin\RentalItemController::class, 'cloneCatalog'])
            ->name('rental-items.clone-catalog');
        Route::post('rental-items/bulk-how-to-videos', [\App\Http\Controllers\Admin\RentalItemController::class, 'bulkHowToVideos'])
            ->name('rental-items.bulk-how-to-videos');
        Route::resource('rental-items', \App\Http\Controllers\Admin\RentalItemController::class);

        Route::resource('rental-featured-banners', \App\Http\Controllers\Admin\RentalFeaturedBannerController::class)
            ->except(['show']);
    });
});
