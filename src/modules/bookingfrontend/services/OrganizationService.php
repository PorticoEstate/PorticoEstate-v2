<?php

namespace App\modules\bookingfrontend\services;

use App\modules\bookingfrontend\repositories\OrganizationRepository;
use App\modules\bookingfrontend\helpers\UserHelper;

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
        $userSsn= $this->bouser->ssn;
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


    public function existOrganization(int $id)
    {
        return $this->repository->partialOrganization($id);
    }
    public function existGroup(int $id)
    {
        return $this->repository->partialGroup($id);
    }

    public function patchDelegate(int $delegateId, array $data) 
    {
        $this->repository->patchDelegate($delegateId, $data);
        return $delegateId;
    }

    public function createDelegate(int $id, array $data)
    {
        $delegateId = $this->repository->insertDelegate($id, $data);
        return $delegateId;
    }

    public function createGroup(int $id, array $data)
    {
        $groupId = $this->repository->insertGroup($id, $data['groupData']);

        foreach($data['groupLeaders'] as $leader) {
            $this->repository->insertGroupContact($groupId, $leader);
        }

        return $groupId;
    }

    public function editGroup(int $groupId, array $data)
    {
        return $this->repository->patchGroup($groupId, $data);
    }

}
