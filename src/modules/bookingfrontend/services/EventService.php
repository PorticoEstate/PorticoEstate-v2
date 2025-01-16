<?php

namespace App\modules\bookingfrontend\services;

use App\Database\Db;
use Exception;
use PDO;

class EventService
{
    private $db;

    public function __construct()
    {
        $this->db = Db::getInstance();
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
        if (!$shouldUpdate) { return null; }

        //Create set-pairs for sql update
        $params = [':id' => $data['id']];
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

    private function saveNewResourcesList (array $data) 
    {
        if (!$data['resource_ids']) return null;
        //Check if this a diff between existing resource array and new res array
        $sql = 
            "SELECT ARRAY_AGG(res.event_id) as event_resources from bb_event_resource res
            where res.event_id = :event_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':event_id' => $data['id']]);
        $resources_ids = $stmt->fetch(PDO::FETCH_ASSOC);
        if (count($resources_ids) == count($data['resource_ids'])) {
            return;
        }

        $insertSql = "INSERT INTO bb_event_resource (event_id, resource_id) VALUES ";
        foreach ($data as $newResource) {
            if (!$resources_ids[$newResource]) {
                $insertSql .= '(' . $data['id'] . ', ' . $newResource . '),';
            }
        }
        $insertStmt = $this->db->prepare(rtrim($insertSql, ','));
        $insertStmt->execute();
    }

    private function saveNewDates (array $data, array $existingEvent)
    {
        if ($existingEvent['from_'] && $existingEvent['to_']) {
            return null;
        }
        // Check if this a diff between existing dates and new dates
        if ($data['from_'] == $existingEvent['from_'] && $data['to_'] == $existingEvent['to_']) {
            return null;
        }
        
        // Create set pairs for sql update
        $params = ['event_id' => $existingEvent['id']];
        $sql = "UPDATE bb_event_date SET ";
        if ($data['from_']) {
            $sql .= "from_ = :from";
            $params[':from'] = $data['from_'];
        }
        if ($data['to_']) {
            $sql .= "to_ = :to";
            $params[':to'] = $data['to_'];
        }
        $sql .= " Where id = :event_id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }


    public function getPartialEventObjectById (int $id)
    {
        $fields = ['id', 'name', 'organizer', 'from_', 'to_', 'participant_limit'];
        $sql = "SELECT " . implode(', ', $fields) . " FROM bb_event WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function updateEvent(array $data, array $existingEvent)
    {  
        try {
            $this->db->beginTransaction();
            
            $this->patchEventMainData($data, $existingEvent);
            $this->saveNewResourcesList($data);
            $this->saveNewDates($data, $existingEvent);

            $this->db->commit();
            return $existingEvent['id'];

        } catch (Exception $e) {
            $this->db->rollBack();
            var_dump($e);
            throw $e;
        }
    }
}