<?php

namespace App\Core\Support;

use DomainException;

/**
 * A request that is well-formed but breaks a business rule (session full,
 * membership expired, overpayment…). Rendered as a validation-style error on
 * web requests and as 422 JSON for the API and devices.
 */
class BusinessRuleException extends DomainException
{
    public function __construct(public readonly string $rule, string $message, public readonly string $field = 'error')
    {
        parent::__construct($message);
    }
}
