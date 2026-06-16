<?php

namespace App\modules\bookingfrontend\controllers;

use App\Database\Db;
use App\helpers\ResponseHelper;
use App\modules\bookingfrontend\models\MultiDomain;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use PDO;
use Exception;

/**
 * @OA\Tag(
 *     name="MultiDomain",
 *     description="API Endpoints for Multi Domain values"
 * )
 */
class MultiDomainController
{
    private $db;

    public function __construct()
    {
        $this->db = Db::getInstance();
    }

    /**
     * @OA\Get(
     *     path="/bookingfrontend/multi-domains",
     *     summary="Get all multi domain values",
     *     tags={"MultiDomain"},
     *     @OA\Response(
     *         response=200,
     *         description="List of multi domain values",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="results",
     *                 type="array",
     *                 @OA\Items(ref="#/components/schemas/MultiDomain")
     *             ),
     *             @OA\Property(property="total_records", type="integer")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error"
     *     )
     * )
     */
    public function getMultiDomains(Request $request, Response $response): Response
    {
        try {
            $sql = "SELECT * FROM bb_multi_domain ORDER BY name ASC";
            $stmt = $this->db->prepare($sql);
            $stmt->execute();

            $results = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $multiDomain = new MultiDomain($row);
                $results[] = $multiDomain->toArray();
            }

            $responseData = [
                'results' => $results,
                'total_records' => count($results)
            ];

            $response->getBody()->write(json_encode($responseData));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => $e->getMessage()],
                500
            );
        }
    }

    /**
     * @OA\Get(
     *     path="/bookingfrontend/multi-domains/{id}",
     *     summary="Get a specific multi domain by ID",
     *     tags={"MultiDomain"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Multi Domain ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Multi domain details",
     *         @OA\JsonContent(ref="#/components/schemas/MultiDomain")
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Multi domain not found"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error"
     *     )
     * )
     */
    public function getMultiDomainById(Request $request, Response $response, array $args): Response
    {
        try {
            $id = (int)$args['id'];

            $sql = "SELECT * FROM bb_multi_domain WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':id' => $id]);

            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Multi domain not found'],
                    404
                );
            }

            $multiDomain = new MultiDomain($row);

            $response->getBody()->write(json_encode($multiDomain->toArray()));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => $e->getMessage()],
                500
            );
        }
    }
//
//    /**
//     * @OA\Post(
//     *     path="/bookingfrontend/multi-domains",
//     *     summary="Create a new multi domain",
//     *     tags={"MultiDomain"},
//     *     @OA\RequestBody(
//     *         required=true,
//     *         @OA\JsonContent(
//     *             type="object",
//     *             required={"name"},
//     *             @OA\Property(property="name", type="string", description="Domain name"),
//     *             @OA\Property(property="webservicehost", type="string", description="Web service host URL")
//     *         )
//     *     ),
//     *     @OA\Response(
//     *         response=201,
//     *         description="Multi domain created successfully",
//     *         @OA\JsonContent(
//     *             type="object",
//     *             @OA\Property(property="id", type="integer"),
//     *             @OA\Property(property="message", type="string")
//     *         )
//     *     ),
//     *     @OA\Response(
//     *         response=400,
//     *         description="Invalid input"
//     *     ),
//     *     @OA\Response(
//     *         response=500,
//     *         description="Internal server error"
//     *     )
//     * )
//     */
//    public function createMultiDomain(Request $request, Response $response): Response
//    {
//        try {
//            $data = json_decode($request->getBody()->getContents(), true);
//
//            if (empty($data['name'])) {
//                return ResponseHelper::sendErrorResponse(
//                    ['error' => 'Name is required'],
//                    400
//                );
//            }
//
//            $sql = "INSERT INTO bb_multi_domain (name, webservicehost, user_id, entry_date, modified_date)
//                    VALUES (:name, :webservicehost, :user_id, :entry_date, :modified_date)";
//
//            $stmt = $this->db->prepare($sql);
//            $currentTime = time();
//
//            $stmt->execute([
//                ':name' => $data['name'],
//                ':webservicehost' => $data['webservicehost'] ?? null,
//                ':user_id' => $data['user_id'] ?? null,
//                ':entry_date' => $currentTime,
//                ':modified_date' => $currentTime
//            ]);
//
//            $id = $this->db->lastInsertId();
//
//            $response->getBody()->write(json_encode([
//                'id' => $id,
//                'message' => 'Multi domain created successfully'
//            ]));
//
//            return $response->withStatus(201)
//                ->withHeader('Content-Type', 'application/json');
//
//        } catch (Exception $e) {
//            return ResponseHelper::sendErrorResponse(
//                ['error' => $e->getMessage()],
//                500
//            );
//        }
//    }
//
//    /**
//     * @OA\Put(
//     *     path="/bookingfrontend/multi-domains/{id}",
//     *     summary="Update a multi domain",
//     *     tags={"MultiDomain"},
//     *     @OA\Parameter(
//     *         name="id",
//     *         in="path",
//     *         required=true,
//     *         description="Multi Domain ID",
//     *         @OA\Schema(type="integer")
//     *     ),
//     *     @OA\RequestBody(
//     *         required=true,
//     *         @OA\JsonContent(
//     *             type="object",
//     *             @OA\Property(property="name", type="string", description="Domain name"),
//     *             @OA\Property(property="webservicehost", type="string", description="Web service host URL")
//     *         )
//     *     ),
//     *     @OA\Response(
//     *         response=200,
//     *         description="Multi domain updated successfully"
//     *     ),
//     *     @OA\Response(
//     *         response=404,
//     *         description="Multi domain not found"
//     *     ),
//     *     @OA\Response(
//     *         response=500,
//     *         description="Internal server error"
//     *     )
//     * )
//     */
//    public function updateMultiDomain(Request $request, Response $response, array $args): Response
//    {
//        try {
//            $id = (int)$args['id'];
//            $data = json_decode($request->getBody()->getContents(), true);
//
//            if (!$data) {
//                return ResponseHelper::sendErrorResponse(
//                    ['error' => 'Invalid JSON data'],
//                    400
//                );
//            }
//
//            // Check if multi domain exists
//            $checkSql = "SELECT id FROM bb_multi_domain WHERE id = :id";
//            $checkStmt = $this->db->prepare($checkSql);
//            $checkStmt->execute([':id' => $id]);
//
//            if (!$checkStmt->fetch()) {
//                return ResponseHelper::sendErrorResponse(
//                    ['error' => 'Multi domain not found'],
//                    404
//                );
//            }
//
//            $updateFields = [];
//            $params = [':id' => $id, ':modified_date' => time()];
//
//            if (isset($data['name'])) {
//                $updateFields[] = "name = :name";
//                $params[':name'] = $data['name'];
//            }
//
//            if (isset($data['webservicehost'])) {
//                $updateFields[] = "webservicehost = :webservicehost";
//                $params[':webservicehost'] = $data['webservicehost'];
//            }
//
//            if (empty($updateFields)) {
//                return ResponseHelper::sendErrorResponse(
//                    ['error' => 'No fields to update'],
//                    400
//                );
//            }
//
//            $updateFields[] = "modified_date = :modified_date";
//
//            $sql = "UPDATE bb_multi_domain SET " . implode(', ', $updateFields) . " WHERE id = :id";
//            $stmt = $this->db->prepare($sql);
//            $stmt->execute($params);
//
//            return $response->withStatus(200)
//                ->withHeader('Content-Type', 'application/json')
//                ->write(json_encode(['message' => 'Multi domain updated successfully']));
//
//        } catch (Exception $e) {
//            return ResponseHelper::sendErrorResponse(
//                ['error' => $e->getMessage()],
//                500
//            );
//        }
//    }
//
//    /**
//     * @OA\Delete(
//     *     path="/bookingfrontend/multi-domains/{id}",
//     *     summary="Delete a multi domain",
//     *     tags={"MultiDomain"},
//     *     @OA\Parameter(
//     *         name="id",
//     *         in="path",
//     *         required=true,
//     *         description="Multi Domain ID",
//     *         @OA\Schema(type="integer")
//     *     ),
//     *     @OA\Response(
//     *         response=204,
//     *         description="Multi domain deleted successfully"
//     *     ),
//     *     @OA\Response(
//     *         response=404,
//     *         description="Multi domain not found"
//     *     ),
//     *     @OA\Response(
//     *         response=500,
//     *         description="Internal server error"
//     *     )
//     * )
//     */
//    public function deleteMultiDomain(Request $request, Response $response, array $args): Response
//    {
//        try {
//            $id = (int)$args['id'];
//
//            // Check if multi domain exists
//            $checkSql = "SELECT id FROM bb_multi_domain WHERE id = :id";
//            $checkStmt = $this->db->prepare($checkSql);
//            $checkStmt->execute([':id' => $id]);
//
//            if (!$checkStmt->fetch()) {
//                return ResponseHelper::sendErrorResponse(
//                    ['error' => 'Multi domain not found'],
//                    404
//                );
//            }
//
//            $sql = "DELETE FROM bb_multi_domain WHERE id = :id";
//            $stmt = $this->db->prepare($sql);
//            $stmt->execute([':id' => $id]);
//
//            return $response->withStatus(204);
//
//        } catch (Exception $e) {
//            return ResponseHelper::sendErrorResponse(
//                ['error' => $e->getMessage()],
//                500
//            );
//        }
//    }
}