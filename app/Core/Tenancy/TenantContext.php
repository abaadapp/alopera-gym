<?php

namespace App\Core\Tenancy;

use App\Models\Tenant;

/**
 * The club (tenant) the current request, job or command is acting for.
 *
 * Every tenant-owned model reads from here through {@see TenantScope}. When no
 * tenant is set the scope fails closed and returns nothing, so forgetting to
 * resolve a tenant can never leak another club's rows.
 *
 * Registered as a scoped singleton: it is reset between requests and queued jobs.
 */
final class TenantContext
{
    private ?Tenant $tenant = null;

    /** Platform code (super admin, cross-tenant jobs) may lift the scope explicitly. */
    private int $bypassDepth = 0;

    public function set(?Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->id;
    }

    public function check(): bool
    {
        return $this->tenant !== null;
    }

    public function require(): Tenant
    {
        return $this->tenant ?? throw new MissingTenantException('No tenant is set for this operation.');
    }

    /**
     * Run a callback as the given tenant, then restore whatever was set before.
     *
     * @template T
     *
     * @param  callable(Tenant): T  $callback
     * @return T
     */
    public function run(Tenant $tenant, callable $callback): mixed
    {
        $previous = $this->tenant;
        $this->tenant = $tenant;

        try {
            return $callback($tenant);
        } finally {
            $this->tenant = $previous;
        }
    }

    /**
     * Run a callback with the tenant scope lifted. Reserved for platform-level code.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function bypass(callable $callback): mixed
    {
        $this->bypassDepth++;

        try {
            return $callback();
        } finally {
            $this->bypassDepth--;
        }
    }

    public function bypassed(): bool
    {
        return $this->bypassDepth > 0;
    }
}
