<?php
/**
 * Outpost CMS — Roles & Permissions
 */

// Role identifiers
const OUTPOST_ROLE_SUPER_ADMIN = 'super_admin';
const OUTPOST_ROLE_ADMIN       = 'admin';
const OUTPOST_ROLE_DEVELOPER   = 'developer';
const OUTPOST_ROLE_EDITOR      = 'editor';
const OUTPOST_ROLE_FREE_MEMBER = 'free_member';
const OUTPOST_ROLE_PAID_MEMBER = 'paid_member';

// Internal roles (can access admin panel)
const OUTPOST_INTERNAL_ROLES = ['super_admin', 'admin', 'developer', 'editor'];

// All valid roles
const OUTPOST_ALL_ROLES = ['super_admin', 'admin', 'developer', 'editor', 'free_member', 'paid_member'];

// Capability map per role
const OUTPOST_CAPABILITIES = [
    'super_admin' => [
        'content.*',
        'collections.*',
        'media.*',
        'settings.*',
        'users.*',
        'code.*',
        'cache.*',
        'members.*',
        'stats.*',
        'super.*',
    ],
    'admin' => [
        'content.*',
        'collections.*',
        'media.*',
        'settings.*',
        'users.*',
        'code.*',
        'cache.*',
        'members.*',
        'stats.*',
    ],
    'developer' => [
        'content.*',
        'collections.*',
        'media.*',
        'code.*',
        'cache.*',
        'stats.*',
    ],
    'editor' => [
        'content.*',
        'collections.*',
        'media.*',
        'cache.*',
        'stats.*',
    ],
    'free_member'  => [],
    'paid_member'  => [],
];

/**
 * Check if a role is an internal (admin panel) role.
 */
function outpost_is_internal_role(string $role): bool {
    return in_array($role, OUTPOST_INTERNAL_ROLES, true);
}

/**
 * Check if the current session has a given capability.
 */
function outpost_has_cap(string $cap): bool {
    $role = $_SESSION['outpost_role'] ?? '';
    if (!$role || !isset(OUTPOST_CAPABILITIES[$role])) {
        return false;
    }

    $caps = OUTPOST_CAPABILITIES[$role];

    // Check exact match
    if (in_array($cap, $caps, true)) {
        return true;
    }

    // Check wildcard (e.g. 'settings.*' matches 'settings.update')
    $prefix = explode('.', $cap)[0] . '.*';
    return in_array($prefix, $caps, true);
}

/**
 * Require a capability or return 403.
 */
function outpost_require_cap(string $cap): void {
    if (!outpost_has_cap($cap)) {
        http_response_code(403);
        echo json_encode(['error' => 'Permission denied']);
        exit;
    }
}
