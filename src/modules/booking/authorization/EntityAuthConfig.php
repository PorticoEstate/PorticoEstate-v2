<?php

namespace App\modules\booking\authorization;

interface EntityAuthConfig
{
    /**
     * The object type identifier (e.g. 'document_building').
     * Used for bb_permission lookups on the entity itself.
     */
    public function getObjectType(): string;

    /**
     * Full permission matrix matching the legacy get_object_role_permissions format.
     *
     * Structure:
     * [
     *     'default' => ['read' => true],
     *     'global' => [
     *         'manager' => ['write' => true, 'create' => true, 'delete' => true],
     *     ],
     *     'parent_role_permissions' => [
     *         'owner' => [
     *             'manager' => ['write' => true, 'create' => true, 'delete' => true],
     *             'case_officer' => ['write' => ['category' => true, 'description' => true]],
     *         ],
     *     ],
     * ]
     */
    public function getObjectPermissions(): array;

    /**
     * Parent chain definitions for resolving inherited permissions.
     *
     * Each entry:
     * [
     *     'key' => 'owner',                   // matches parent_role_permissions key
     *     'object_type' => 'building',         // bb_permission object_type for parent
     *     'resolve' => 'field',                // 'field' or 'junction'
     *     'field' => 'owner_id',               // entity field containing parent ID (when resolve=field)
     *     'children' => [...]                   // optional grandparent chain
     * ]
     */
    public function getParentChain(): array;

    /**
     * All writable field names for this entity type.
     * Used to expand write:true into a full field list.
     */
    public function getAllFields(): array;
}
