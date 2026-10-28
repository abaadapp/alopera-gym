<?php

namespace App\Modules\Finance\Contracts;

/**
 * Anything an invoice can be issued to. Finance knows nothing about gym
 * members; it only needs a name and contact details.
 */
interface Billable
{
    public function billingName(): string;

    public function billingPhone(): ?string;

    public function billingEmail(): ?string;
}
