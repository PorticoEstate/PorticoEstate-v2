<?php

namespace App\modules\bookingfrontend\services;

use App\modules\bookingfrontend\models\Application;
use App\modules\bookingfrontend\models\helper\Date;
use App\modules\bookingfrontend\models\Resource;
use App\modules\bookingfrontend\models\Order;
use App\modules\bookingfrontend\models\OrderLine;
use App\Database\Db;
use PDO;

class ApplicationService
{
    private $db;

    public function __construct()
    {
        $this->db = Db::getInstance();
    }

    public function getPartialApplications(string $session_id): array
    {
        $sql = "SELECT * FROM bb_application
                WHERE status = 'NEWPARTIAL1' AND session_id = :session_id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':session_id' => $session_id]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $applications = [];
        foreach ($results as $result) {
            $application = new Application($result);
            $application->dates = $this->fetchDates($application->id);
            $application->resources = $this->fetchResources($application->id);
            $application->orders = $this->fetchOrders($application->id);
            $applications[] = $application->serialize();
        }

        return $applications;
    }

    public function savePartialApplication(Application $application): int
    {
        // Start a transaction
        $this->db->beginTransaction();

        try {
            // Insert or update the main application record
            if ($application->id) {
                $this->updateApplication($application);
            } else {
                $this->insertApplication($application);
            }

            // Save related data
            $this->saveDates($application);
            $this->saveResources($application);
            $this->saveOrders($application);

            // Commit the transaction
            $this->db->commit();

            return $application->id;
        } catch (\Exception $e) {
            // Rollback the transaction on error
            $this->db->rollBack();
            throw $e;
        }
    }

    private function insertApplication(Application $application): void
    {
        $sql = "INSERT INTO bb_application (status, session_id, /* other fields */)
                VALUES (:status, :session_id, /* other placeholders */)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':status' => $application->status,
            ':session_id' => $application->session_id,
            // Bind other fields
        ]);

        $application->id = $this->db->lastInsertId();
    }

    private function updateApplication(Application $application): void
    {
        $sql = "UPDATE bb_application
                SET status = :status, /* other fields */
                WHERE id = :id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':status' => $application->status,
            ':id' => $application->id,
            // Bind other fields
        ]);
    }

    private function fetchDates(int $application_id): array
    {
        $sql = "SELECT * FROM bb_application_date WHERE application_id = :application_id ORDER BY from_";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':application_id' => $application_id]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function ($dateData) {
            return (new Date($dateData))->serialize();
        }, $results);
    }

    private function fetchResources(int $application_id): array
    {
        $sql = "SELECT r.* FROM bb_resource r
                JOIN bb_application_resource ar ON r.id = ar.resource_id
                WHERE ar.application_id = :application_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':application_id' => $application_id]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function ($resourceData) {
            return (new Resource($resourceData))->serialize();
        }, $results);
    }

    private function fetchOrders(int $application_id): array
    {
        $sql = "SELECT po.*, pol.*, am.unit,
                CASE WHEN r.name IS NULL THEN s.name ELSE r.name END AS name
                FROM bb_purchase_order po
                JOIN bb_purchase_order_line pol ON po.id = pol.order_id
                JOIN bb_article_mapping am ON pol.article_mapping_id = am.id
                LEFT JOIN bb_service s ON (am.article_id = s.id AND am.article_cat_id = 2)
                LEFT JOIN bb_resource r ON (am.article_id = r.id AND am.article_cat_id = 1)
                WHERE po.cancelled IS NULL AND po.application_id = :application_id
                ORDER BY pol.id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':application_id' => $application_id]);
        $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $orders = [];
        foreach ($results as $row) {
            $order_id = $row['id'];
            if (!isset($orders[$order_id])) {
                $orders[$order_id] = new Order([
                    'order_id' => $order_id,
                    'sum' => 0,
                    'lines' => []
                ]);
            }

            $line = new OrderLine($row);
            $orders[$order_id]->lines[] = $line;
            $orders[$order_id]->sum += $line->amount + $line->tax;
        }

        return array_map(function ($order) {
            return $order->serialize();
        }, array_values($orders));
    }

    private function saveDates(Application $application): void
    {
        // TODO: Implement date saving logic
        // ...
    }

    private function saveResources(Application $application): void
    {
        // TODO: Implement resource saving logic
        // ...
    }

    private function saveOrders(Application $application): void
    {
        // TODO: Implement order saving logic
        // ...
    }

    public function calculateTotalSum(array $applications): float
    {
        $total_sum = 0;
        foreach ($applications as $application) {
            foreach ($application['orders'] as $order) {
                $total_sum += $order['sum'];
            }
        }
        return round($total_sum, 2);
    }

    public function deletePartial(int $id, string $session_id): bool
    {
        try {
            $this->db->beginTransaction();

            // Verify the application exists and belongs to the current session
            $sql = "SELECT id FROM bb_application WHERE id = :id AND session_id = :session_id AND status = 'NEWPARTIAL1'";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':id' => $id, ':session_id' => $session_id]);

            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                throw new Exception("Application not found or not owned by the current session");
            }

            // Delete associated data
            $this->deleteAssociatedData($id);

            // Delete the application
            $sql = "DELETE FROM bb_application WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':id' => $id]);

            $this->db->commit();
            return true;
        } catch (PDOException $e) {
            $this->db->rollBack();
            // Log the error
            error_log("Database error: " . $e->getMessage());
            throw new Exception("An error occurred while deleting the application");
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }


    private function deleteAssociatedData(int $application_id): void
    {
        // Order matters here due to foreign key constraints
        $tables = [
            'bb_purchase_order_line',
            'bb_purchase_order',
            'bb_application_comment',
            'bb_application_date',
            'bb_application_resource',
            'bb_application_targetaudience',
            'bb_application_agegroup'
        ];

        foreach ($tables as $table) {
            $column = $table === 'bb_purchase_order_line' ? 'order_id' : 'application_id';

            if ($table === 'bb_purchase_order_line') {
                $sql = "DELETE FROM $table WHERE order_id IN (SELECT id FROM bb_purchase_order WHERE application_id = :application_id)";
            } else {
                $sql = "DELETE FROM $table WHERE $column = :application_id";
            }

            $stmt = $this->db->prepare($sql);
            $stmt->execute([':application_id' => $application_id]);
        }
    }
}