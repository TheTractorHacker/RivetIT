<?php

namespace ITFlow\Training\Upstream;

use ITFlow\Training\Core\Ctx;
use ITFlow\Training\People\Scope;

/**
 * P2's fail-closed people scope for Phase 5 (spec §3.1): a thin wrapper over People\Scope, so there
 * is exactly one definition of who may see whom. Admins and Training level 3 see every department
 * (including "No department"); everyone else only their user_client_permissions departments; no rows
 * means nobody. Without P2 the answer is ScopeView::none().
 */
final class PeopleScope
{
    public static function forCtx(Ctx $c): ScopeView
    {
        if (!class_exists(Scope::class)) {
            return ScopeView::none();
        }
        try {
            return new ScopeView(Scope::forCtx($c));
        } catch (\Throwable $e) {
            error_log('Training Upstream\PeopleScope: ' . get_class($e) . ': ' . $e->getMessage());
            return ScopeView::none();
        }
    }

    public static function all(): ScopeView
    {
        if (!class_exists(Scope::class)) {
            return ScopeView::none();
        }
        return new ScopeView(Scope::all());
    }
}
