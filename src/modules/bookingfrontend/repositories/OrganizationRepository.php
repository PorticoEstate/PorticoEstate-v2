<?php

namespace App\modules\bookingfrontend\repositories;

use PDO;
use App\Database\Db;

class OrganizationRepository
{
    private $db;

    public function __construct()
    {
        $this->db = Db::getInstance();
    }
    
    public function partialOrganization(int $id)
    {
        $sql = "SELECT id FROM bb_organization
        WHERE id=:id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        return !!$stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function partialGroup(int $id)
    {
        return 1;
    }

    public function getDelegate($ssn, $orgId)
    {
        $sql = "SELECT id from bb_delegate
        WHERE organization_id=:orgId && ssn=:ssn";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':orgId' => $orgId, 'ssn' => $ssn]);
        return !!$stmt->fetch(PDO::FETCH_ASSOC);
    }
    public function getDelegateById(int $id)
    {
        $sql = "SELECT id from bb_delegate
        WHERE id=:id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id ]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    public function organizationById(int $id) 
    {
        $activitySql = "SELECT json_build_object('id', act.id) FROM bb_activity AS act
        WHERE act.id = org.activity_id";

        $delegaterSql = "SELECT json_agg(json_build_object(
            'id', del.id,
            'name', del.name,
            'email', del.email,
            'phone', del.phone
        ))
        FROM bb_delegate as del
        WHERE del.organization_id = org.id";

        $groupsSql = "
        SELECT json_agg(json_build_object(
            'id', gr.id,
            'name', gr.name,
            'description', gr.description,
            'active', gr.active,
            'contact', 
                (SELECT json_agg(json_build_object(
                    'id', contact.id,
                    'name', contact.name,
                    'phone', contact.phone,
                    'email', contact.email
                )) 
                FROM bb_group_contact as contact 
                Where contact.group_id = gr.id),
            'activity', json_build_object(
                'id', act.id,
                'name', act.name
            )
        )) as arr 
        FROM public.bb_group as gr
        JOIN bb_activity as act
        ON act.id = gr.activity_id
        WHERE organization_id = :orgId
        ";

        $sql = "SELECT 
        org.*, ($delegaterSql) as delegaters, ($activitySql) as activity, 
        ($groupsSql) as groups
        FROM bb_organization as org
        WHERE id = :orgId
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':orgId' => $id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$data) return null;

        return $data;
    }

    public function patchDelegate(int $delegateId, array $data)
    {
        $params = [':id' => $delegateId];
        $updateFields = [];

        foreach ($data as $field => $value) {
            $updateFields[] = "$field = :$field";
            $params[":$field"] = $value;
        }

        $sql = "UPDATE bb_delegate SET " . implode(', ', $updateFields) .
            " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    public function insertDelegate(int $id, array $data): int
    {
        $sql = "INSERT INTO bb_delegate(active, name, email, phone, ssn, organization_id)
        VALUES (1, :name, :email, :phone, :ssn, :organization_id)
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([ 
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'ssn' => $data['ssn'],
            'organization_id' => $id,
        ]);
        return 1;
    }

    public function insertGroup(int $id, array $data): int {
        return 1;
    }

    public function insertGroupContact(int $groupId, array $data): int {
        $sql = "INSERT INTO 
        bb_group_contact(name, phone, email, group_id)
        VALUES(:name, :phone, :email, :groupId)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'name' => $data['name'],
            'phone' => $data['phone'],
            'email' => $data['email'],
            'groupId' => $groupId
        ]);
        return 1;
    }

    public function patchGroup(int $groupId, array $data): int
    {
        $params = [':id' => $groupId];
        $updateFields = [];

        foreach ($data as $field => $value) {
            $updateFields[] = "$field = :$field";
            $params[":$field"] = $value;
        }

        $sql = "UPDATE bb_group SET " . implode(', ', $updateFields) .
            " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return 1;
    }

}