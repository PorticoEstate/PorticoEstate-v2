<?php

namespace App\modules\phpgwapi\services;

use App\modules\booking\authorization\EntityAuthConfig;
use App\modules\booking\repositories\PermissionRepository;
use App\modules\phpgwapi\security\Acl;
use App\modules\phpgwapi\services\Settings;

class AuthorizationService
{
    private const ROLE_DEFAULT = 'default';

    private PermissionRepository $permissionRepo;
    private ?Acl $acl;
    private ?int $subjectId;

    public function __construct(
        PermissionRepository $permissionRepo,
        ?Acl $acl = null,
        ?int $subjectId = null
    ) {
        $this->permissionRepo = $permissionRepo;
        $this->acl = $acl;
        $this->subjectId = $subjectId;
    }

    private function getAcl(): Acl
    {
        if ($this->acl === null) {
            $this->acl = Acl::getInstance();
        }
        return $this->acl;
    }

    private function getSubjectId(): int
    {
        if ($this->subjectId !== null) {
            return $this->subjectId;
        }
        $settings = Settings::getInstance();
        return (int)$settings->get('account_id');
    }

    /**
     * Check authorization for an operation on an entity.
     *
     * @param EntityAuthConfig $config The auth config for the entity type
     * @param string $operation 'read', 'write', 'create', or 'delete'
     * @param array|null $entity The entity data (must contain 'id' and 'owner_id' for object-level checks)
     * @return bool|array true=full access, array=allowed fields (write only), false=denied
     */
    public function authorize(EntityAuthConfig $config, string $operation, ?array $entity = null): bool|array
    {
        // 1. Admin bypass
        if ($this->isAdmin()) {
            return $this->expandWriteGrant(true, $config, $operation);
        }

        $subjectId = $this->getSubjectId();
        $permissions = $config->getObjectPermissions();

        // 2. Global roles
        $globalRoles = $this->permissionRepo->getGlobalRoles($subjectId);
        if (isset($permissions['global'])) {
            $result = $this->checkRolesAgainstPermissions($globalRoles, $permissions['global'], $operation, $config);
            if ($result !== false) {
                return $result;
            }
        }

        // 3. Default role check (always applies)
        if (isset($permissions[self::ROLE_DEFAULT][$operation])) {
            $grant = $permissions[self::ROLE_DEFAULT][$operation];
            $result = $this->expandWriteGrant($grant, $config, $operation);
            if ($result !== false) {
                return $result;
            }
        }

        // 4. Direct object roles (if entity has an id)
        if ($entity !== null && isset($entity['id'])) {
            $objectRoles = $this->permissionRepo->getObjectRoles(
                $subjectId,
                (int)$entity['id'],
                $config->getObjectType()
            );
            // Check direct roles against top-level permissions (excluding reserved keys)
            $directPermissions = array_diff_key($permissions, array_flip(['default', 'global', 'parent_role_permissions']));
            if (!empty($directPermissions)) {
                $result = $this->checkRolesAgainstPermissions($objectRoles, $directPermissions, $operation, $config);
                if ($result !== false) {
                    return $result;
                }
            }
        }

        // 5. Parent chain
        if ($entity !== null && isset($permissions['parent_role_permissions'])) {
            $result = $this->checkParentChain(
                $config,
                $subjectId,
                $operation,
                $entity,
                $config->getParentChain(),
                $permissions['parent_role_permissions']
            );
            if ($result !== false) {
                return $result;
            }
        }

        return false;
    }

    /**
     * Check if the current user is an admin for the given app.
     */
    public function isAdminForApp(string $appname): bool
    {
        $acl = $this->getAcl();
        return $acl->check('run', Acl::READ, 'admin')
            || $acl->check('admin', Acl::ADD, $appname);
    }

    private function isAdmin(): bool
    {
        return $this->isAdminForApp('booking');
    }

    /**
     * Check a set of roles against a permission block for a specific operation.
     *
     * @param array<array{role: string}> $roles
     * @param array $permissionBlock Role-keyed permission definitions
     * @param string $operation
     * @param EntityAuthConfig $config
     * @return bool|array
     */
    private function checkRolesAgainstPermissions(array $roles, array $permissionBlock, string $operation, EntityAuthConfig $config): bool|array
    {
        foreach ($roles as $roleRow) {
            $role = $roleRow['role'];
            if (isset($permissionBlock[$role][$operation])) {
                $grant = $permissionBlock[$role][$operation];
                $result = $this->expandWriteGrant($grant, $config, $operation);
                if ($result !== false) {
                    return $result;
                }
            }
        }
        return false;
    }

    /**
     * Walk the parent chain, resolving parent IDs and checking roles at each level.
     *
     * @return bool|array
     */
    private function checkParentChain(
        EntityAuthConfig $config,
        int $subjectId,
        string $operation,
        array $entity,
        array $parentDefs,
        array $parentPermissions
    ): bool|array {
        foreach ($parentDefs as $parentDef) {
            $key = $parentDef['key'];
            if (!isset($parentPermissions[$key])) {
                continue;
            }

            $parentId = $this->resolveParentId($entity, $parentDef);
            if ($parentId === null) {
                continue;
            }

            // Get roles on the parent object
            $parentRoles = $this->permissionRepo->getObjectRoles(
                $subjectId,
                $parentId,
                $parentDef['object_type']
            );

            // Check parent roles against parent permissions (exclude nested parent_role_permissions)
            $rolePermissions = array_diff_key(
                $parentPermissions[$key],
                array_flip(['parent_role_permissions'])
            );

            $result = $this->checkRolesAgainstPermissions($parentRoles, $rolePermissions, $operation, $config);
            if ($result !== false) {
                return $result;
            }

            // Recurse into grandparent chain if defined
            if (
                isset($parentDef['children'])
                && isset($parentPermissions[$key]['parent_role_permissions'])
            ) {
                $result = $this->checkParentChain(
                    $config,
                    $subjectId,
                    $operation,
                    ['id' => $parentId, 'owner_id' => $parentId],
                    $parentDef['children'],
                    $parentPermissions[$key]['parent_role_permissions']
                );
                if ($result !== false) {
                    return $result;
                }
            }
        }

        return false;
    }

    /**
     * Resolve the parent entity ID from the entity data.
     */
    private function resolveParentId(array $entity, array $parentDef): ?int
    {
        if ($parentDef['resolve'] === 'field') {
            $field = $parentDef['field'];
            return isset($entity[$field]) ? (int)$entity[$field] : null;
        }

        if ($parentDef['resolve'] === 'junction') {
            // For junction resolution, the entity's 'id' is the child side
            $childId = isset($entity['id']) ? (int)$entity['id'] : null;
            if ($childId === null) {
                return null;
            }
            return $this->permissionRepo->getBuildingIdForResource($childId);
        }

        return null;
    }

    /**
     * Expand a permission grant into the appropriate return value.
     *
     * When operation is 'write' and grant is true, expand to all fields.
     * When grant is an array (field-level), return as-is.
     * For non-write operations, true grants just return true.
     *
     * @param mixed $grant
     * @return bool|array
     */
    private function expandWriteGrant(mixed $grant, EntityAuthConfig $config, string $operation): bool|array
    {
        if ($grant === false) {
            return false;
        }

        if ($operation === 'write') {
            if ($grant === true) {
                return array_fill_keys($config->getAllFields(), true);
            }
            if (is_array($grant)) {
                return $grant;
            }
            return false;
        }

        // For read/create/delete, grant is boolean
        return $grant === true ? true : false;
    }
}
