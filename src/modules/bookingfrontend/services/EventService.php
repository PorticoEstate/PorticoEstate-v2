<?php

namespace App\modules\bookingfrontend\services;

use App\Database\Db;
use App\modules\bookingfrontend\models\Event;
use App\modules\bookingfrontend\models\Resource;
use App\modules\bookingfrontend\helpers\UserHelper;
use Exception;
use PDO;

class EventService
{
    private $db;
    private $bouser;

    public function __construct()
    {
        $this->db = Db::getInstance();
        $this->bouser = new UserHelper();
    }

    private function patchEventMainData(array $data, array $existingEvent)
    {
        $allowedFields = [
            'name',
            'organizer',
            'from_',
            'to_',
            'participant_limit'
        ];
        //Check if this a diff between existing record and new data
        $shouldUpdate = false;
        foreach ($data as $field => $value) {
            $existingField = $existingEvent[$field];
            if ($existingField != $value && in_array($field, $allowedFields)) {
                $shouldUpdate = true;
            }
        }
        if (!$shouldUpdate) {
            return null;
        }

        //Create set-pairs for sql update
        $params = [':id' => $existingEvent['id']];
        $updateFields = [];

        foreach ($data as $field => $value) {
            if (in_array($field, $allowedFields)) {
                $updateFields[] = "$field = :$field";
                $params[":$field"] = $value;
            }
        }

        $sql = "UPDATE bb_event SET " . implode(', ', $updateFields) .
            " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    private function saveNewResourcesList(array $data, array $existingEvent)
    {
        if (!$data['resource_ids']) return null;
        //Check if this a diff between existing resource array and new res array
        $sql =
            "SELECT array_to_json(ARRAY_AGG(resource_id)) as event_resources from bb_event_resource
        WHERE event_id = :event_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':event_id' => $existingEvent['id']]);
        $resources_ids = json_decode($stmt->fetch()['event_resources']);

        //Delete removed resources
        $to_delete = [];
        foreach ($resources_ids as $resource_id) {
            if (!in_array($resource_id, $data['resource_ids'])) {
                array_push($to_delete, $resource_id);
            }
        }
        if (count($to_delete) > 0) {
            $deleteSql = 
            "DELETE FROM bb_event_resource 
            WHERE resource_id IN (" . implode(', ', $to_delete) . ")";
            $insertStmt = $this->db->prepare($deleteSql);
            $insertStmt->execute();
        }

        //Set new resources
        $insertSql = "INSERT INTO bb_event_resource (event_id, resource_id) VALUES ";
        $should_insert = false;
        foreach ($data['resource_ids'] as $newResource) {
            if (!in_array($newResource, $resources_ids)) {
                $should_insert = true;
                $insertSql .= '(' . $existingEvent['id'] . ', ' . $newResource . '),';
            }
        }
        if ($should_insert) {
            $insertStmt = $this->db->prepare(rtrim($insertSql, ','));
            $insertStmt->execute();
        }
    }

    private function saveNewDates(array $data, array $existingEvent)
    {
        $params = ['event_id' => $existingEvent['id']];
        $sql = "UPDATE bb_event_date SET ";
        if ($data['from_'] && $data['from_'] !== $existingEvent['from_']) {
            $sql .= 'from_ = :from, ';
            $params[':from'] = $data['from_'];
        }
        if ($data['to_'] && $data['to_'] !== $existingEvent['to_']) {
            $sql .= 'to_ = :to ';
            $params[':to'] = $data['to_'];
        }

        if (!$params[':from'] && !$params[':to']) return;

        $sql .= "WHERE event_id = :event_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    public function getPartialEventObjectById(int $id)
    {
        $fields = ['id', 'customer_ssn', 'customer_organization_number'];
        $sql = "SELECT " . implode(', ', $fields) . " FROM bb_event WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    public function checkEventOwnerShip(array $existingEvent)
    {
        $ownerSsn = $existingEvent['customer_ssn'];
        $ownerOrgNum = $existingEvent['customer_organization_number'];
        $ssn = $this->bouser->ssn;
        $userOrgs = $this->bouser->organizations 
            ? array_column($this->bouser->organizations, 'orgnr') 
            : [];
        return 
            $ssn === $ownerSsn || 
            in_array($ownerOrgNum, $userOrgs);
        
    }
    public function updateEvent(array $data, array $existingEvent)
    {
        try {
            $this->db->beginTransaction();

            $this->patchEventMainData($data, $existingEvent);
            $this->saveNewResourcesList($data, $existingEvent);
            $this->saveNewDates($data, $existingEvent);

            $this->db->commit();
            return $existingEvent['id'];
        } catch (Exception $e) {
            $this->db->rollBack();
            var_dump($e);
            throw $e;
        }
    }

    public function getEventById($id)
    {
        $sql = "SELECT ev.*, act.name as activity_name, 
        (
            SELECT jsonb_object_agg(res.id, res.name) from bb_event_resource as evres
            JOIN bb_resource as res
            ON res.id = evres.resource_id
            WHERE evres.event_id = ev.id
        ) as resources
        FROM public.bb_event ev
        JOIN bb_activity act
        ON ev.activity_id = act.id
        WHERE ev.id = :id
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$data) return false;

        $entity = new Event($data);
        $resources = [];
        foreach (json_decode($data['resources'], true) as $id => $name) {
            $resourceEntity = new Resource(array('id' => $id, 'name' => $name));
            array_push($resources, $resourceEntity);
        }
        $entity->resources = $resources;
    
        $userOrgs = $this->bouser->organizations 
            ? array_column($this->bouser->organizations, 'orgnr') 
            : null;

        return $entity->serialize(
            ['user_ssn' => $this->bouser->ssn, "organization_number" => $userOrgs]
        );
    }
}
