<?php
namespace App\modules\bookingfrontend\repositories;

use PDO;
use App\Database\Db;
use App\modules\bookingfrontend\models\Event;
use App\modules\bookingfrontend\models\Resource;

class EventRepository
{
    private $db;

    public function __construct()
    {
        $this->db = Db::getInstance();
    }

    public function getEventById(int $id): Event
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

        if (!$data) return null;

        $entity = new Event($data);
        $resources = [];
        foreach (json_decode($data['resources'], true) as $id => $name) {
            $resourceEntity = new Resource(array('id' => $id, 'name' => $name));
            array_push($resources, $resourceEntity);
        }
        $entity->resources = $resources;
        return $entity;
    }

    public function patchMainData(int $id, array $data, array $lookupFields)
    {
        $params = [':id' => $id];
        $updateFields = [];

        foreach ($data as $field => $value) {
            if (in_array($field, $lookupFields)) {
                $updateFields[] = "$field = :$field";
                $params[":$field"] = $value;
            }
        }

        $sql = "UPDATE bb_event SET " . implode(', ', $updateFields) .
            " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    public function resourceIds(int $id)
    {
        $sql = 
            "SELECT array_to_json(ARRAY_AGG(resource_id)) as event_resources from bb_event_resource
            WHERE event_id = :event_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':event_id' => $id]);
        return json_decode($stmt->fetch()['event_resources']);
    }

    public function deleteResources(array $resourceIds)
    {
        $deleteSql = 
        "DELETE FROM bb_event_resource 
        WHERE resource_id IN (" . implode(', ', $resourceIds) . ")";
        $insertStmt = $this->db->prepare($deleteSql);
        $insertStmt->execute();
    }

    public function insertResources(int $id, array $resourceIds)
    {
        $insertSql = "INSERT INTO bb_event_resource (event_id, resource_id) VALUES ";
        $insertSql .= implode(', ', array_map(function($value) {
            return "($value[id], $value[resourceId])";
        }, $resourceIds));
        
        $insertStmt = $this->db->prepare($insertSql);
        $insertStmt->execute();
    }

    public function currentParticipants(int $id, $registeredIn = false): int
    {
        $filtermethod = '';
        if ($registeredIn) {
            $filtermethod .= 'AND from_ IS NOT NULL AND to_ IS NULL';
        } else {
            $filtermethod .= 'AND to_ IS NULL';
        }

        $sql = "SELECT sum(quantity) as cnt"
        . " FROM bb_participant"
            . " WHERE reservation_type='event'"
            . " AND reservation_id=" . (int) $id
            . " {$filtermethod}";
        $this->db->query($sql, __LINE__, __FILE__);
        $this->db->next_record();
        return (int)$this->db->f('cnt');
    }

    public function findPreRegistration(int $id, string $phone)
    {
        $sql = "SELECT id, email, from_, to_, quantity"
        . " FROM bb_participant"
        . " WHERE reservation_type='event'"
        . " AND reservation_id=" . (int) $id
        . " AND phone LIKE '%{$phone}'"
        . " ORDER BY id DESC";

        $this->db->query($sql, __LINE__, __FILE__);
        return $this->db->next_record();
    }
    public function findInRegistration(int $id, string $phone)
    {
        $sql = "SELECT id, email, from_, to_, quantity"
        . " FROM bb_participant"
        . " WHERE reservation_type='event'"
        . " AND reservation_id=" . (int) $id
        . " AND phone LIKE '%{$phone}'"
        . " AND from_ IS NOT NULL AND to_ IS NULL"
        . " ORDER BY id DESC";

        $this->db->query($sql, __LINE__, __FILE__);
        return $this->db->next_record();
    }

    public function updateDates($id, $data)
    {
        $sql = "UPDATE bb_event_date SET " ;
        ['from_' => $from, 'to_' => $to] = $data;
        $params = ['eventId' => $id];
        if ($from) {
            $sql .= "from_ = :from, ";
            $params['from'] = $from;
        }
        if ($to) {
            $sql .= "to_ = :to ";
            $params['to'] = $to;
        }
        $sql .= "WHERE event_id = :eventId";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    public function addPreregistration($id, $data)
    {
        $fields = [
            'reservation_type',
            'reservation_id',
            'phone',
            'quantity'
        ];
        $sql = 
        "INSERT INTO bb_participant(" . implode(', ', $fields) . 
        ") VALUES('event', :eventId, :phone, :quantity)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'eventId' => $id,
            'phone' => $data['phone'],
            'quantity' => $data['quantity']
        ]);
    }

    public function insertInRegistration($id, $data) 
    {

    }
    public function updateInRegistration($id, $data)
    {

    }

}