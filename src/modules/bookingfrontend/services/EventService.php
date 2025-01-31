<?php

namespace App\modules\bookingfrontend\services;

use App\Database\Db;
use App\modules\bookingfrontend\helpers\UserHelper;
use App\modules\bookingfrontend\repositories\EventRepository;
use Exception;
use PDO;

class EventService
{
    private $db;
    private $bouser;
    private $repository;

    public function __construct()
    {
        $this->db = Db::getInstance();
        $this->bouser = new UserHelper();
        $this->repository = new EventRepository();
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

        $this->repository->patchMainData($existingEvent['id'], $data, $allowedFields);
    }

    private function saveNewResourcesList(array $data, array $existingEvent)
    {
        if (!$data['resource_ids']) return null;
        $resourceIds = $this->repository->resourceIds($existingEvent['id']); 

        //Delete removed resources
        $toDelete = [];
        foreach ($resourceIds as $resourceId) {
            if (!in_array($resourceId, $data['resource_ids'])) {
                array_push($toDelete, $resourceId);
            }
        }
        if (count($toDelete) > 0) {
            $this->repository->deleteResources($toDelete);
        }

        //Set new resources
        $shouldInsert = false;
        $toInsert = [];
        foreach ($data['resource_ids'] as $newResource) {
            if (!in_array($newResource, $resourceIds)) {
                $shouldInsert = true;
                array_push($toInsert, 
                    [
                        'id' => $existingEvent['id'], 
                        'resourceId' => $newResource
                    ]
                );
            }
        }
        if ($shouldInsert) {
            $this->repository->insertResources($existingEvent['id'], $toInsert);
        }
    }

    private function saveNewDates($id, array $data)
    {
        if (!$data['from_'] && !$data['to_']) return null;
       
        $this->repository->updateDates($id, $data);
    }

    public function getPartialEventObjectById(int $id)
    {
        $fields = ['id', 'customer_ssn', 'customer_organization_number', 'participant_limit'];
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
            $this->saveNewDates($existingEvent['id'], $data);

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
        $entity = $this->repository->getEventById($id);
    
        $userOrgs = $this->bouser->organizations 
            ? array_column($this->bouser->organizations, 'orgnr') 
            : null;
        $participants = $this->repository->currentParticipants($id);
        return [
            'event' => $entity->serialize(
                ['user_ssn' => $this->bouser->ssn, "organization_number" => $userOrgs]
            ),
            'numberOfParticipants' => $participants  
        ];
    }

    public function preRegister(array $data, array $event)
    {  
        $previousPreRegistration = $this->repository->getPreviousRegistration($event['id'], $data['phone']);

        if ($previousPreRegistration['id']) {
            return null;
        }

        $numberOfParticipants = $this->repository->currentParticipants($event['id']);
        $newAllPeoplesQuantity = $numberOfParticipants + $data['quantity'];
        if ($newAllPeoplesQuantity > (int) $event['participant_limit']) {
            return null;
        }
       
        $this->repository->addPreregistration($event['id'], $data);
        return $event['id'];
    }
}
