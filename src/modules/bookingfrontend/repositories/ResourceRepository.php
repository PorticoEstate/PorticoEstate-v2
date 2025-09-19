<?php

namespace App\modules\bookingfrontend\repositories;

use PDO;
use App\Database\Db;
use App\modules\bookingfrontend\models\Resource;
use App\modules\bookingfrontend\models\Participant;

class ResourceRepository
{
    private $db;

    public function __construct()
    {
        $this->db = Db::getInstance();
    }

    /**
     * Create a Resource instance from data array
     */
    public function createResource(array $data): Resource
    {
        return new Resource($data);
    }

    /**
     * Get resource by ID
     */
    public function getById(int $id): ?Resource
    {
        $sql = "SELECT * FROM bb_resource WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        return $data ? $this->createResource($data) : null;
    }

    /**
     * Get multiple resources by IDs
     */
    public function getByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $placeholders = str_repeat('?,', count($ids) - 1) . '?';
        $sql = "SELECT * FROM bb_resource WHERE id IN ($placeholders)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($ids);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map([$this, 'createResource'], $results);
    }

    /**
     * Get resources for an application
     */
    public function getByApplicationId(int $applicationId): array
    {
        $sql = "SELECT r.*
                FROM bb_resource r
                INNER JOIN bb_application_resource ar ON r.id = ar.resource_id
                WHERE ar.application_id = :application_id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':application_id' => $applicationId]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map([$this, 'createResource'], $results);
    }

    /**
     * Get resources with participant limits
     */
    public function getWithParticipantLimits(array $resourceIds = []): array
    {
        $whereClause = '';
        $params = [];

        if (!empty($resourceIds)) {
            $placeholders = str_repeat('?,', count($resourceIds) - 1) . '?';
            $whereClause = "WHERE r.id IN ($placeholders)";
            $params = $resourceIds;
        }

        $sql = "SELECT r.*,
                       COALESCE(pl.quantity, 0) as participant_limit
                FROM bb_resource r
                LEFT JOIN bb_participant_limit pl ON r.id = pl.resource_id
                $whereClause
                ORDER BY r.sort, r.name";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $resources = [];
        foreach ($results as $row) {
            $resource = $this->createResource($row);
            if ($row['participant_limit'] > 0) {
                $resource->participant_limit = (int)$row['participant_limit'];
            }
            $resources[] = $resource;
        }

        return $resources;
    }

    /**
     * Get all active resources
     */
    public function getActive(): array
    {
        $sql = "SELECT * FROM bb_resource WHERE active = 1 ORDER BY sort, name";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map([$this, 'createResource'], $results);
    }

    /**
     * Get resources with filtering and pagination
     */
    public function getFiltered(array $filters = [], int $offset = 0, int $limit = null): array
    {
        $whereConditions = [];
        $params = [];

        // Add common filters
        if (isset($filters['active'])) {
            $whereConditions[] = "active = :active";
            $params[':active'] = $filters['active'];
        }

        if (isset($filters['activity_id'])) {
            $whereConditions[] = "activity_id = :activity_id";
            $params[':activity_id'] = $filters['activity_id'];
        }

        if (isset($filters['building_id'])) {
            $whereConditions[] = "building_id = :building_id";
            $params[':building_id'] = $filters['building_id'];
        }

        if (isset($filters['rescategory_id'])) {
            $whereConditions[] = "rescategory_id = :rescategory_id";
            $params[':rescategory_id'] = $filters['rescategory_id'];
        }

        if (isset($filters['search'])) {
            $whereConditions[] = "LOWER(name) LIKE LOWER(:search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

        $sql = "SELECT * FROM bb_resource $whereClause ORDER BY sort, name";

        if ($limit !== null) {
            $sql .= " LIMIT :limit OFFSET :offset";
            $params[':limit'] = $limit;
            $params[':offset'] = $offset;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map([$this, 'createResource'], $results);
    }

    /**
     * Create resource instances from JSON resource data (for events)
     */
    public function createFromResourcesJson(string $resourcesJson, array $additionalData = []): array
    {
        $resources = [];
        $resourceData = json_decode($resourcesJson, true);

        if ($resourceData) {
            foreach ($resourceData as $id => $name) {
                $data = array_merge([
                    'id' => $id,
                    'name' => $name
                ], $additionalData);

                $resources[] = $this->createResource($data);
            }
        }

        return $resources;
    }

    /**
     * Create and serialize resources from data array
     */
    public function createAndSerialize(array $dataArray, array $userRoles = [], bool $short = false): array
    {
        return array_map(function ($data) use ($userRoles, $short) {
            $resource = $this->createResource($data);
            return $resource->serialize($userRoles, $short);
        }, $dataArray);
    }

    /**
     * Save a resource (update or insert)
     */
    public function save(Resource $resource): Resource
    {
        if ($resource->id) {
            return $this->update($resource);
        } else {
            return $this->insert($resource);
        }
    }

    /**
     * Insert a new resource
     */
    private function insert(Resource $resource): Resource
    {
        $data = $resource->toArray();
        unset($data['id']); // Remove ID for insert

        $columns = array_keys($data);
        $placeholders = ':' . implode(', :', $columns);
        $columnsList = implode(', ', $columns);

        $sql = "INSERT INTO bb_resource ($columnsList) VALUES ($placeholders) RETURNING id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_combine(array_map(fn($col) => ":$col", $columns), array_values($data)));

        $resource->id = $stmt->fetchColumn();
        return $resource;
    }

    /**
     * Update an existing resource
     */
    private function update(Resource $resource): Resource
    {
        $data = $resource->toArray();
        $id = $data['id'];
        unset($data['id']);

        $setClause = implode(', ', array_map(fn($col) => "$col = :$col", array_keys($data)));
        $sql = "UPDATE bb_resource SET $setClause WHERE id = :id";

        $params = array_combine(array_map(fn($col) => ":$col", array_keys($data)), array_values($data));
        $params[':id'] = $id;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $resource;
    }
}