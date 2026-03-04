<?php

namespace App\modules\booking\authorization;

class DocumentBuildingAuthConfig implements EntityAuthConfig
{
    public function getObjectType(): string
    {
        return 'document_building';
    }

    public function getObjectPermissions(): array
    {
        return [
            'default' => ['read' => true],
            'global' => [
                'manager' => ['write' => true, 'create' => true, 'delete' => true],
            ],
            'parent_role_permissions' => [
                'owner' => [
                    'manager' => ['write' => true, 'create' => true, 'delete' => true],
                    'case_officer' => ['write' => ['category' => true, 'description' => true]],
                ],
            ],
        ];
    }

    public function getParentChain(): array
    {
        return [
            [
                'key' => 'owner',
                'object_type' => 'building',
                'resolve' => 'field',
                'field' => 'owner_id',
            ],
        ];
    }

    public function getAllFields(): array
    {
        return ['name', 'description', 'category', 'owner_id', 'focal_point_x', 'focal_point_y', 'rotation'];
    }
}
