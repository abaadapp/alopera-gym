<?php

namespace App\Core\Backup;

/**
 * Every table a club owns, in insert order (parents before children). Restore
 * deletes in reverse order. A guard test fails when a table with a tenant_id
 * column is added without being listed here or in EXCLUDED.
 */
final class TenantTables
{
    public const ORDER = [
        'roles', 'branches', 'users', 'number_sequences', 'tenant_integrations',
        'site_settings', 'site_posts', 'site_media', 'contact_messages',
        'expense_categories', 'product_categories', 'suppliers', 'products',
        'product_addons', 'product_addon_product', 'product_addon_category',
        'gym_programs', 'gym_membership_plans', 'gym_plan_program', 'gym_coupons',
        'gym_trainers', 'gym_trainer_availabilities',
        'gym_members',
        'invoices', 'invoice_items', 'credit_notes', 'payments',
        'gym_memberships', 'gym_coupon_redemptions', 'gym_membership_freezes', 'gym_membership_events',
        'gym_member_documents', 'gym_member_notes',
        'gym_class_schedules', 'gym_class_sessions', 'gym_bookings',
        'gym_pt_packages', 'gym_pt_subscriptions', 'gym_pt_sessions',
        'gym_access_devices', 'gym_access_credentials', 'gym_check_ins', 'gym_staff_attendances',
        'expenses', 'purchase_orders', 'purchase_order_items', 'sales', 'sale_items', 'sale_item_addons', 'stock_movements',
        'message_templates', 'conversations', 'campaigns', 'messages', 'auto_replies',
        'payment_intents',
        'gym_accounts', 'gym_journal_entries', 'gym_journal_lines',
        'gym_job_titles', 'gym_employees', 'gym_payroll_runs', 'gym_payroll_payouts', 'gym_payroll_items',
        'gym_document_types', 'gym_documents', 'gym_document_renewals', 'gym_document_alerts',
    ];

    /** Tenant-related tables deliberately left out, with the reason. */
    public const EXCLUDED = [
        'audit_logs' => 'append-only trail; a restore must never rewrite history',
        'tenant_subscriptions' => 'owned by the platform, not by the club',
        'platform_invoices' => 'owned by the platform, not by the club',
        'payment_webhook_events' => 'platform-level gateway log',
        'staff_notifications' => 'the bell: transient, rebuilt from open items by notifications:sync',
    ];

    /** Child tables without their own tenant_id: [table => [fk column, parent table]]. */
    public const PIVOTS = [
        'gym_plan_program' => ['membership_plan_id', 'gym_membership_plans'],
        'product_addon_product' => ['product_addon_id', 'product_addons'],
        'product_addon_category' => ['product_addon_id', 'product_addons'],
    ];

    /** Tables whose rows reference earlier rows of the same table (that column): inserted in id order, the column cleared before deleting. */
    public const SELF_REFERENCING = [
        'payments' => 'refund_of_id',
        'gym_memberships' => 'previous_membership_id',
        'gym_accounts' => 'parent_id',
        'gym_journal_entries' => 'reverses_id',
    ];
}
