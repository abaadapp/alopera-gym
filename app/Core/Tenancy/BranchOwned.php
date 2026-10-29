<?php

namespace App\Core\Tenancy;

/**
 * A record that belongs to one branch of the club (an invoice, a sale, a check-in, a document). A user tied to
 * another branch gets 404 for it on every panel route that names it (EnforceBranchAccess).
 */
interface BranchOwned
{
    /** The record's branch; null is the club's main branch (see BranchContext). */
    public function owningBranchId(): ?int;
}
