<?php

namespace App\modules\bookingfrontend\services;

use App\modules\bookingfrontend\repositories\OrganizationRepository;
use App\modules\bookingfrontend\helpers\UserHelper;
use Exception;

class OrganizationService
{
    private OrganizationRepository $repository;
    private $bouser;

    public function __construct()
    {
        $this->repository = new OrganizationRepository();
        $this->bouser = new UserHelper();
    }

    public function canEdit(int $orgId) 
    {
        $userSsn = $this->bouser->ssn;
        return !!$this->repository->getDelegate($userSsn, $orgId);
    }
    public function delegateExist($id = null)
    {
        return !!$this->repository->getDelegateById($id);
    }

    public function getOrganizationById(int $id)
    {
        $organization = $this->repository->organizationById($id);
        return $organization;
    }

    public function getSubActivityList(int $id) 
    {
        return $this->repository->getSubActivityList($id);
    }

    public function getGroupById(int $id)
    {
        $group = $this->repository->getGroupById($id);
        return $group;
    }

    public function existOrganization(int $id)
    {
        return $this->repository->partialOrganization($id);
    }
    public function existGroup(int $id)
    {
        return $this->repository->partialGroup($id);
    }
    public function existLeader(int $id)
    {
        return $this->repository->partialLeader($id);
    }

    public function getDelegateById(int $delegateId)
    {
        return $this->repository->getDelegateById($delegateId);
    }

    public function patchDelegate(int $delegateId, array $data) 
    {
        return $this->repository->patchDelegate($delegateId, $data);
    }

    public function createDelegate(int $id, array $data)
    {
        $delegateId = $this->repository->insertDelegate($id, $data);
        return $delegateId;
    }

    public function createGroup(int $id, array $data)
    {
        $group = $this->repository->insertGroup($id, (array) $data['groupData']);

        foreach((array) $data['groupLeaders'] as $leader) {
            if ($leader['id']) {
                $leaderData = $this->repository->getGroupLeader($leader['id']);
                if (!$leaderData || $leaderData['group_id'] === $group['id']) {
                    throw new Exception();
                }
                $this->repository->insertGroupContact($group['id'], (array) $leaderData);
            } else {
                $this->repository->insertGroupContact($group['id'], (array) $leader);
            }
        }

        return $group;
    }

    public function patchGroup(int $groupId, array $data)
    {   
        $this->repository->patchGroupLeader($groupId, $data['groupLeaders'][0]);
        $this->repository->patchGroupLeader($groupId, $data['groupLeaders'][1]);
        return $this->repository->patchGroup($groupId, $data['groupData']);
    }

    public function patchGroupLeader(int $groupId, array $data)
    {
        return $this->repository->patchGroupLeader($groupId, $data);
    }

}
