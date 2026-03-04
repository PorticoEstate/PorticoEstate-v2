<?php

namespace App\modules\booking\authorization;

class DocumentResourceAuthConfig implements EntityAuthConfig
{
    public function getObjectType(): string
    {
        return 'document_resource';
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
                    'parent_role_permissions' => [
                        'building' => [
                            'manager' => ['write' => true, 'create' => true, 'delete' => true],
                            'case_officer' => ['write' => ['category' => true, 'description' => true]],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function getParentChain(): array
    {
        return [
            [
                'key' => 'owner',
                'object_type' => 'resource',
                'resolve' => 'field',
                'field' => 'owner_id',
                'children' => [
                    [
                        'key' => 'building',
                        'object_type' => 'building',
                        'resolve' => 'junction',
                        'junction_table' => 'bb_building_resource',
                        'junction_child_col' => 'resource_id',
                        'junction_parent_col' => 'building_id',
                    ],
                ],
            ],
        ];
    }

    public function getAllFields(): array
    {
        return ['name', 'description', 'category', 'focal_point_x', 'focal_point_y', 'rotation'];
    }
}
