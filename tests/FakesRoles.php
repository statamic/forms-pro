<?php

namespace Tests;

use Illuminate\Support\Collection;
use Statamic\Auth\File\RoleRepository;
use Statamic\Contracts\Auth\RoleRepository as RepositoryContract;
use Statamic\Facades\Role;

// TODO: Remove this once https://github.com/statamic/cms/pull/15115 has been merged
trait FakesRoles
{
    protected function setTestRoles($roles)
    {
        $roles = collect($roles)->map(fn ($permissions, $handle) => Role::make()->handle($handle)->addPermission($permissions));

        $fake = new class($roles) extends RoleRepository
        {
            public function __construct(protected $roles) {}

            public function all(): Collection
            {
                return $this->roles;
            }
        };

        app()->instance(RepositoryContract::class, $fake);
        Role::swap($fake);
    }
}
