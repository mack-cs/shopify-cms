<?php

namespace App\Enums;

enum PermissionEnum: string
{
    // ── Users / Roles ─────────────────────────────────
    case RoleManage          = 'role.manage';
    case PermissionManage    = 'permission.manage';
    case UserManage          = 'user.manage';

    // ── Shopify Integration / System ──────────────────
    case ShopifyManage       = 'shopify.manage';        // api keys/scopes/webhooks/sync settings
    case ImportCsv           = 'import.csv';
    case ExportCsv           = 'export.csv';

    // ── Products / Data ───────────────────────────────
    case ProductView         = 'product.view';
    case ProductCreate       = 'product.create';
    case ProductEdit         = 'product.edit';
    case ProductSubmitSeo    = 'product.submit_seo';    // submit for SEO review
    case ProductRollback     = 'product.rollback';      // rollback to previous revision (optional)

    // ── SEO Review ────────────────────────────────────
    case SeoReview           = 'seo.review';            // approve/reject SEO compliance

    // ── Audit / Transparency ──────────────────────────
    case AuditViewLogs       = 'audit.view_logs';       // ChangeLog view

 
    // Imports
    case ImportViewCurrent = 'import.view_current';
    case ImportViewAll     = 'import.view_all';
    case ImportCreate      = 'import.create';
    case ImportDelete      = 'import.delete';

    // Products / Data
    case ShopifyPushProducts = 'shopify.push_products';
}
