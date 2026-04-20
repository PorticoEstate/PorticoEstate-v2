<?php

namespace App\modules\property\inc;

use App\modules\phpgwapi\security\Acl;

/**
 * Guard for ACL checks in the entity business layer.
 *
 * Encapsulates the two ACL check patterns used by boentity,
 * mirroring the approach in GenericRegistryController.
 */
class EntityAclGuard
{
	private Acl $acl;
	private string $app;

	public function __construct(Acl $acl, string $app = 'property')
	{
		$this->acl = $acl;
		$this->app = $app;
	}

	/**
	 * Check whether the current user has edit permission on the given ACL location.
	 */
	public function canEdit(string $location): bool
	{
		return (bool)$this->acl->check($location, ACL_EDIT, $this->app);
	}

	/**
	 * Check whether the current user has admin-level edit permission (.admin location).
	 */
	public function isAdmin(): bool
	{
		return (bool)$this->acl->check('.admin', ACL_EDIT, $this->app);
	}
}
