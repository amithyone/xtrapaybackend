<?php

namespace App\Services\Admin;

use App\Models\Admin;
use App\Models\ConsumerAppSession;
use App\Models\RentalsAdminAppSession;
use App\Models\BusinessAccountApplication;
use App\Models\BusinessNameRegistration;
use App\Models\BusinessVerification;
use App\Models\Payment;
use App\Models\WhatsappWalletTransaction;
use App\Models\Renter;
use App\Models\SupportTicket;
use App\Models\VirtualCardRequest;
use Illuminate\Support\Facades\Route;

class AdminSidebarMenu
{
    /** @return list<array<string, mixed>> */
    public function itemsFor(Admin $admin): array
    {
        $registry = $this->registry($admin);
        $orderedKeys = $this->orderedKeys($admin);
        $items = [];

        foreach ($orderedKeys as $key) {
            if ($key === config('admin_sidebar.divider_key')) {
                $items[] = ['key' => $key, 'type' => 'divider'];

                continue;
            }

            $def = $registry[$key] ?? null;
            if ($def === null || ! $this->shouldShowMenuItem($admin, $key, $def)) {
                continue;
            }

            $items[] = array_merge($def, ['key' => $key, 'type' => 'link']);
        }

        return $items;
    }

    /** @return list<string> */
    public function orderedKeys(Admin $admin): array
    {
        $default = config('admin_sidebar.default_order', []);
        $saved = is_array($admin->sidebar_menu_order) ? $admin->sidebar_menu_order : [];

        if ($saved === []) {
            return $default;
        }

        $valid = array_flip($default);
        $merged = [];

        foreach ($saved as $key) {
            if (is_string($key) && isset($valid[$key])) {
                $merged[] = $key;
            }
        }

        foreach ($default as $key) {
            if (! in_array($key, $merged, true)) {
                $merged[] = $key;
            }
        }

        return $merged;
    }

    /** @param list<string> $order */
    public function saveOrder(Admin $admin, array $order): void
    {
        $valid = array_flip(config('admin_sidebar.default_order', []));
        $clean = [];

        foreach ($order as $key) {
            if (is_string($key) && isset($valid[$key])) {
                $clean[] = $key;
            }
        }

        foreach (config('admin_sidebar.default_order', []) as $key) {
            if (! in_array($key, $clean, true)) {
                $clean[] = $key;
            }
        }

        $admin->sidebar_menu_order = $clean;
        $admin->save();
    }

    public function resetOrder(Admin $admin): void
    {
        $admin->sidebar_menu_order = null;
        $admin->save();
    }

    /** @param array<string, mixed> $def */
    private function shouldShowMenuItem(Admin $admin, string $key, array $def): bool
    {
        if (! $admin->canAccessPage($key)) {
            return false;
        }

        if ($this->usesExplicitPagePermissions($admin)) {
            return true;
        }

        return (bool) ($def['visible'] ?? true);
    }

    private function usesExplicitPagePermissions(Admin $admin): bool
    {
        return is_array($admin->admin_page_permissions) && $admin->admin_page_permissions !== [];
    }

    /** @return array<string, array<string, mixed>> */
    private function registry(Admin $admin): array
    {
        $needsReviewCount = Payment::query()
            ->where('status', Payment::STATUS_PENDING)
            ->has('statusChecks', '>=', 3)
            ->count();

        $openTickets = SupportTicket::where('status', SupportTicket::STATUS_OPEN)->count();

        $pendingCardRequests = VirtualCardRequest::whereIn('status', [
            VirtualCardRequest::STATUS_PENDING,
            VirtualCardRequest::STATUS_SUBMITTED,
        ])->count();

        $pendingRenterKycCount = Renter::query()
            ->whereNotNull('kyc_id_card_path')
            ->where(function ($q) {
                $q->whereNull('kyc_id_status')->orWhere('kyc_id_status', 'pending');
            })
            ->count();

        return [
            'dashboard' => $this->link('Dashboard', 'admin.dashboard', 'fas fa-chart-line', ['admin.dashboard']),
            'payments' => $this->link('Payments', 'admin.payments.index', 'fas fa-money-bill-wave', ['admin.payments.*'], excludeRoutes: ['admin.payments.needs-review']),
            'payments_needs_review' => array_merge(
                $this->link('Needs Review', 'admin.payments.needs-review', 'fas fa-exclamation-triangle', ['admin.payments.needs-review']),
                [
                    'variant' => 'danger',
                    'badge_count' => $needsReviewCount,
                    'badge_color' => 'red',
                ]
            ),
            'payments_import' => $this->link('Import payments', 'admin.payments.import', 'fas fa-file-upload', [
                'admin.payments.import',
                'admin.payments.import.store',
                'admin.payments.import.sample',
            ]),
            'businesses' => $this->link('Businesses', 'admin.businesses.index', 'fas fa-building', ['admin.businesses.*']),
            'support' => array_merge(
                $this->link('Support Tickets', 'admin.support.index', 'fas fa-comments', ['admin.support.*']),
                ['visible' => $admin->canManageSupportTickets(), 'badge_count' => $openTickets, 'badge_color' => 'red']
            ),
            'virtual_cards' => array_merge(
                $this->link('Card Management', 'admin.virtual-cards.index', 'fas fa-credit-card text-indigo-600', ['admin.virtual-cards.*']),
                ['visible' => $admin->canViewVirtualCards(), 'badge_count' => $pendingCardRequests, 'badge_color' => 'indigo']
            ),
            'businesses_kyc' => array_merge(
                $this->link('Business KYC', 'admin.businesses-kyc.index', 'fas fa-id-card', ['admin.businesses-kyc.*']),
                [
                    'visible' => true,
                    'badge_count' => BusinessVerification::countPendingReview(),
                    'badge_color' => 'yellow',
                ]
            ),
            'renters_kyc' => array_merge(
                $this->link('Renters KYC', 'admin.renters-kyc.index', 'fas fa-id-badge', ['admin.renters-kyc.*']),
                ['badge_count' => $pendingRenterKycCount, 'badge_color' => 'yellow']
            ),
            'rentals' => $this->link('Rentals', 'admin.rentals.index', 'fas fa-camera', [
                'admin.rentals.*',
                'admin.rental-categories.*',
                'admin.rental-items.*',
                'admin.rental-featured-banners.*',
                'admin.rentals-app-sessions.*',
            ]),
            'rentals_app_sessions' => array_merge(
                $this->link('Rentals app sessions', 'admin.rentals-app-sessions.index', 'fas fa-mobile-alt text-blue-600', ['admin.rentals-app-sessions.*']),
                [
                    'badge_count' => RentalsAdminAppSession::query()->whereNull('ended_at')->count(),
                    'badge_color' => 'blue',
                ]
            ),
            'whatsapp_wallet' => array_merge(
                $this->link('WhatsApp wallet', 'admin.whatsapp-wallet.index', 'fab fa-whatsapp text-green-600', [
                    'admin.whatsapp-wallet.index',
                    'admin.whatsapp-wallet.settings',
                    'admin.whatsapp-wallet.update',
                    'admin.whatsapp-wallet.fx-rates.update',
                ]),
                ['visible' => $admin->canAccessWalletOps()]
            ),
            'whatsapp_wallet_users' => array_merge(
                $this->link('Wallet users', 'admin.whatsapp-wallet.wallets.index', 'fas fa-users text-green-600', ['admin.whatsapp-wallet.wallets.*']),
                ['visible' => $admin->canAccessWalletOps()]
            ),
            'whatsapp_wallet_signup_attempts' => array_merge(
                $this->link('Signup attempts', 'admin.whatsapp-wallet.signup-attempts.index', 'fas fa-user-plus text-green-600', ['admin.whatsapp-wallet.signup-attempts.*']),
                ['visible' => $admin->canAccessWalletOps()]
            ),
            'app_sessions' => array_merge(
                $this->link('App sessions', 'admin.app-sessions.index', 'fas fa-mobile-alt text-green-600', ['admin.app-sessions.*']),
                [
                    'visible' => $admin->canAccessWalletOps(),
                    'badge_count' => ConsumerAppSession::query()->whereNull('ended_at')->count(),
                    'badge_color' => 'green',
                ]
            ),
            'whatsapp_wallet_transactions' => array_merge(
                $this->link('Wallet transactions', 'admin.whatsapp-wallet.transactions.index', 'fas fa-exchange-alt text-green-600', ['admin.whatsapp-wallet.transactions.*']),
                [
                    'visible' => $admin->canAccessWalletOps(),
                    'badge_count' => WhatsappWalletTransaction::countFailedBankPayoutsRecent(),
                    'badge_color' => 'red',
                ]
            ),
            'bank_account_prefixes' => array_merge(
                $this->link('Bank account prefixes', 'admin.bank-account-prefixes.index', 'fas fa-university text-green-600', ['admin.bank-account-prefixes.*']),
                ['visible' => $admin->canAccessWalletOps()]
            ),
            'business_name_registrations' => array_merge(
                $this->link('Business names', 'admin.business-name-registrations.index', 'fas fa-briefcase text-green-600', ['admin.business-name-registrations.*']),
                [
                    'visible' => $admin->canManageSettings(),
                    'badge_count' => BusinessNameRegistration::countPending(),
                    'badge_color' => 'yellow',
                ]
            ),
            'business_account_applications' => array_merge(
                $this->link('Business accounts', 'admin.business-account-applications.index', 'fas fa-store text-green-600', ['admin.business-account-applications.*']),
                [
                    'visible' => $admin->canManageSettings(),
                    'badge_count' => BusinessAccountApplication::countPending(),
                    'badge_color' => 'yellow',
                ]
            ),
            'withdrawals' => $this->link('Withdrawals', 'admin.withdrawals.index', 'fas fa-hand-holding-usd', ['admin.withdrawals.*']),
            'overdraft' => array_merge(
                $this->link('Loan & overdraft queue', 'admin.credit-facility-applications.index', 'fas fa-file-invoice-dollar', ['admin.credit-facility-applications.*', 'admin.overdraft-applications.*']),
                [
                    'badge_count' => \App\Models\CreditFacilityRequest::query()->where('status', 'pending')->count(),
                    'badge_color' => 'yellow',
                ]
            ),
            'business_payroll' => $this->link('Business payroll', 'admin.business-payroll.index', 'fas fa-users-cog text-green-600', ['admin.business-payroll.*']),
            'investor_pitch_access' => array_merge(
                $this->link('Investor pitch access', 'admin.investor-pitch-access.index', 'fas fa-user-lock text-indigo-700', ['admin.investor-pitch-access.*']),
                ['visible' => $admin->isSuperAdmin()]
            ),
            'peer_lending_offers' => array_merge(
                $this->link('Peer lending offers', 'admin.peer-lending.offers.index', 'fas fa-hand-holding-usd', ['admin.peer-lending.offers.*']),
                ['visible' => $admin->isSuperAdmin()]
            ),
            'peer_lending_loans' => array_merge(
                $this->link('Peer loan queue', 'admin.peer-lending.loans.index', 'fas fa-money-check-alt', ['admin.peer-lending.loans.*']),
                ['visible' => $admin->isSuperAdmin()]
            ),
            'external_apis' => array_merge(
                $this->link('External APIs', 'admin.external-apis.index', 'fas fa-plug', ['admin.external-apis.*']),
                ['visible' => $admin->canManageAccountNumbers()]
            ),
            'processed_emails' => $this->link('Inbox', 'admin.processed-emails.index', 'fas fa-inbox', ['admin.processed-emails.*']),
            'transaction_logs' => $this->link('Transaction Logs', 'admin.transaction-logs.index', 'fas fa-history', ['admin.transaction-logs.*']),
            'api_hits' => $this->link('API hits', 'admin.api-hits.index', 'fas fa-network-wired', ['admin.api-hits.*']),
            'audits' => array_merge(
                $this->link('Audits', 'admin.audits.index', 'fas fa-clipboard-check', ['admin.audits.*']),
                ['visible' => $admin->canManageSettings()]
            ),
            'mevon_balance_monitor' => array_merge(
                $this->link('Mevon Balance Monitor', 'admin.audits.mevonpay.monitor', 'fas fa-chart-line', ['admin.audits.mevonpay.monitor*']),
                ['visible' => $admin->canManageSettings()]
            ),
            'honeypot' => array_merge(
                $this->link('Honeypot', 'admin.honeypot.index', 'fas fa-spider text-amber-700', ['admin.honeypot.*']),
                [
                    'visible' => $admin->canManageSettings(),
                    'badge_count' => app(\App\Services\Security\AdminHoneypotService::class)->banCount(),
                    'badge_color' => 'yellow',
                ]
            ),
            'match_attempts' => $this->link('Match Logs', 'admin.match-attempts.index', 'fas fa-search-dollar', ['admin.match-attempts.*']),
            'invoices' => $this->link('Invoices', 'admin.invoices.index', 'fas fa-file-invoice', ['admin.invoices.*']),
            'payment_links' => $this->link('Payment links', 'admin.payment-links.index', 'fas fa-link', ['admin.payment-links.*']),
            'email_accounts' => array_merge(
                $this->link('Email Accounts', 'admin.email-accounts.index', 'fas fa-envelope', ['admin.email-accounts.*']),
                ['visible' => $admin->canManageEmailAccounts()]
            ),
            'account_numbers' => array_merge(
                $this->link('Account Numbers', 'admin.account-numbers.index', 'fas fa-hashtag', ['admin.account-numbers.*']),
                ['visible' => $admin->canManageAccountNumbers()]
            ),
            'bank_email_templates' => $this->link('Bank Templates', 'admin.bank-email-templates.index', 'fas fa-university', ['admin.bank-email-templates.*']),
            'bank_logos' => array_merge(
                $this->link('Bank Logos', 'admin.bank-logos.index', 'fas fa-image', ['admin.bank-logos.*']),
                ['visible' => $admin->canMutateWalletAccounts()]
            ),
            'test_transaction' => $this->link('Test Transaction', 'admin.test-transaction.index', 'fas fa-flask', ['admin.test-transaction.*']),
            'renters' => $this->link('Rental users', 'admin.renters.index', 'fas fa-users', ['admin.renters.*']),
            'tickets' => $this->link('Tickets', 'admin.tickets.events.index', 'fas fa-ticket-alt', ['admin.tickets.*'], excludeRoutes: ['admin.tickets.scanner', 'admin.tickets.scanner*']),
            'tickets_scanner' => $this->link('QR Scanner', 'admin.tickets.scanner', 'fas fa-qrcode', ['admin.tickets.scanner', 'admin.tickets.scanner*']),
            'charity' => $this->link('Go Fund', 'admin.charity.index', 'fas fa-hand-holding-heart', ['admin.charity.*']),
            'memberships' => $this->link('Memberships', 'admin.memberships.index', 'fas fa-address-card', ['admin.memberships.*', 'admin.membership-categories.*']),
            'developer_program' => $this->link('Developer program', 'admin.developer-program.index', 'fas fa-handshake', ['admin.developer-program.*']),
            'desktop_telemetry' => array_merge(
                $this->link('Desktop DRM', 'admin.desktop-telemetry.events.index', 'fas fa-laptop-code', ['admin.desktop-telemetry.*']),
                ['visible' => $admin->isSuperAdmin()]
            ),
            'whitelisted_emails' => array_merge(
                $this->link('Whitelisted Emails', 'admin.whitelisted-emails.index', 'fas fa-shield-alt', ['admin.whitelisted-emails.*']),
                ['visible' => $admin->canManageSettings()]
            ),
            'pages' => array_merge(
                $this->link('Pages', 'admin.pages.index', 'fas fa-file-alt', ['admin.pages.*']),
                ['visible' => $admin->canManageSettings()]
            ),
            'announcements' => array_merge(
                $this->link('Announcements', 'admin.announcements.index', 'fas fa-bullhorn', ['admin.announcements.*']),
                ['visible' => $admin->canMutateWalletAccounts()]
            ),
            'settings' => array_merge(
                $this->link('Settings', 'admin.settings.index', 'fas fa-cog', ['admin.settings.*']),
                ['visible' => $admin->canManageSettings()]
            ),
            'email_templates' => array_merge(
                $this->link('Email Templates', 'admin.email-templates.index', 'fas fa-envelope-open-text', ['admin.email-templates.*']),
                ['visible' => $admin->canManageSettings()]
            ),
            'staff' => array_merge(
                $this->link('Staff Management', 'admin.staff.index', 'fas fa-users-cog', ['admin.staff.*']),
                ['visible' => $admin->canManageAdmins()]
            ),
        ];
    }

    /**
     * @param  list<string>  $activeRoutes
     * @param  list<string>  $excludeRoutes
     * @return array<string, mixed>
     */
    private function link(string $label, string $routeName, string $icon, array $activeRoutes, array $excludeRoutes = []): array
    {
        $isActive = false;
        foreach ($activeRoutes as $pattern) {
            if (request()->routeIs($pattern)) {
                $isActive = true;
                break;
            }
        }
        foreach ($excludeRoutes as $pattern) {
            if (request()->routeIs($pattern)) {
                $isActive = false;
                break;
            }
        }

        return [
            'label' => $label,
            'url' => Route::has($routeName) ? route($routeName) : '#',
            'icon' => $icon,
            'is_active' => $isActive,
            'visible' => true,
            'variant' => 'default',
            'badge_count' => 0,
            'badge_color' => 'red',
        ];
    }
}
