<?php

namespace App\modules\bookingfrontend\services;

use App\Database\Db;
use App\modules\bookingfrontend\models\CompletedReservation;
use PDO;

class CompletedReservationService
{
    private $db;

    public function __construct()
    {
        $this->db = Db::getInstance();
    }

    public function getReservationsBySsn(string $ssn, array $delegateOrgIds = []): array
    {
        $params = [':ssn' => $ssn];
        $orgFilter = '';

        if (!empty($delegateOrgIds))
        {
            $orgFilter = ' OR organization_id IN (' . implode(',', array_fill(0, count($delegateOrgIds), '?')) . ')';
            $params = array_merge([$ssn], $delegateOrgIds);
        }

        $sql = "SELECT * FROM bb_completed_reservation
                WHERE (customer_ssn = ? {$orgFilter})
                AND cost > 0
                ORDER BY id DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function ($data)
        {
            return (new CompletedReservation($data))->serialize();
        }, $results);
    }
}
