<?php

namespace App\Core\Auth;

use App\Models\Role;
use App\Models\User;

/**
 * Who may hand which permissions to whom.
 *
 * Nobody gives what they do not hold: a role, a person's own list, or a role assignment may only carry permissions the
 * acting user holds themselves (the owner holds them all). An account that opens more than the acting user is not
 * theirs to edit, rename, reset or switch off — only someone holding at least as much may. The owner role is given
 * and taken only by an owner.
 */
final class Grants
{
    /** @return list<string> */
    public static function grantable(User $actor): array
    {
        return array_values($actor->isOwner() ? PermissionRegistry::all() : $actor->permissions());
    }

    /** @param  list<string>  $permissions */
    public static function covers(User $actor, array $permissions): bool
    {
        return array_diff($permissions, self::grantable($actor)) === [];
    }

    /** May the actor give this role to someone? */
    public static function mayAssign(User $actor, Role $role): bool
    {
        if ($role->key === SystemRoles::OWNER || in_array('*', $role->permissions ?? [], true)) {
            return $actor->isOwner();
        }

        return self::covers($actor, $role->expandedPermissions());
    }

    /**
     * May the actor edit this person's account (details, role, permissions, password, active)? A user tied to a branch
     * manages only that branch's accounts — not another branch's, nor an account that reaches every branch.
     */
    public static function mayManage(User $actor, User $target): bool
    {
        if ($target->isOwner()) {
            return $actor->isOwner();
        }
        if ($actor->branch_id !== null && (int) $target->branch_id !== (int) $actor->branch_id) {
            return false;
        }

        return self::covers($actor, $target->permissions());
    }

    /**
     * May the actor give an account this reach (null: every branch)? A user tied to a branch keeps the accounts they
     * open or edit in their own branch.
     */
    public static function mayPlace(User $actor, ?int $branchId): bool
    {
        return $actor->branch_id === null || ($branchId !== null && $branchId === (int) $actor->branch_id);
    }

    /**
     * The list to store after an edit: the actor decides only the permissions they hold; any other permission already
     * on the list stays exactly as it was (they could not see it to remove it, nor add it).
     *
     * @param  list<string>  $stored
     * @param  list<string>  $requested
     * @return list<string>
     */
    public static function merge(User $actor, array $stored, array $requested): array
    {
        $mine = self::grantable($actor);
        $kept = array_diff($stored, $mine);
        $chosen = array_intersect(PermissionRegistry::sanitize($requested), $mine);

        return array_values(array_unique([...$kept, ...$chosen]));
    }
}
