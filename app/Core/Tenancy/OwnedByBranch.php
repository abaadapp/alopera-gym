<?php

namespace App\Core\Tenancy;

/** BranchOwned for a model with its own branch_id column. */
trait OwnedByBranch
{
    public function owningBranchId(): ?int
    {
        return $this->branch_id ? (int) $this->branch_id : null;
    }
}
