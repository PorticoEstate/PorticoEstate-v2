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
        //Check if this a diff between existing record and new data
        $shouldUpdate = false;
        foreach ($data as $field => $value) {
            $existingField = $existingEvent[$field];
            if ($existingField != $value) $shouldUpdate = true;
        }
        if (!$shouldUpdate) { return; }

        $params = [':id' -> $data['id']];
        $updateFields = [];
        $allowedFields = [
            'name', 'organizer', 'from_', 'to_', 'participant_limit'
        ];

        foreach ($data as $field => $value) {
            if ($field !== 'id' && in_array($field, $allowedFields)) {
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
        //Check if this a diff between existing resource array and new res array
        $sql = 
            "SELECT ARRAY_AGG(res.id) as event_resources from bb_event_resource res
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
        //Check if this a diff between existing dates and new dates
        if ($data['from_'] == $existingEvent['from'] && $data['to_'] == $existingEvent['to_']) {
            return;
        }

        $stmt = $this->db->prepare(
            "UPDATE bb_event_date SET from_ = :from, to_ = :to_
        Where id = :event_id"
        );

        $stmt->execute([
            ':event_id' => $existingEvent['id'],
            ':from_' => $data['from_'],
            ':to_' => $data['to_'],
        ]);
    }

    public function getPartialEventObjectById (int $id)
    {
        $fields = ['name', 'organizer', 'from_', 'to_', 'participant_limit'];
        $sql = "SELECT " . implode(', ', $fields) . " FROM bb_event WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' -> $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Update an existing event
     */
    public function updateEvent(array $data, array $existingEvent)
    {  
        try {
            $this->db->beginTransaction();
            
            $this->patchEventMainData($data, $existingEvent);
            $this->saveNewResourcesList($data);
            $this->saveNewDates($data, $existingEvent);
            return $data['id'];

        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}