<?php

namespace App\modules\bookingfrontend\controllers;

use App\Database\Db;
use App\modules\bookingfrontend\helpers\ResponseHelper;
use App\modules\bookingfrontend\helpers\UserHelper;
use App\modules\bookingfrontend\models\User;
use App\modules\phpgwapi\security\Sessions;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Exception;

/**
 * @OA\Tag(
 *     name="User",
 *     description="API Endpoints for User"
 * )
 */
class BookingUserController
{

    /**
     * Whitelist of fields that can be updated
     */
    private const ALLOWED_FIELDS = [
        'name' => true,
        'homepage' => true,
        'phone' => true,
        'email' => true,
        'street' => true,
        'zip_code' => true,
        'city' => true
    ];

    private $container;
    private $db;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->db = Db::getInstance();
    }


    /**
     * @OA\Get(
     *     path="/bookingfrontend/user",
     *     summary="Get authenticated user details",
     *     tags={"User"},
     *     @OA\Response(
     *         response=200,
     *         description="User details",
     *         @OA\JsonContent(ref="#/components/schemas/User")
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="User not authenticated"
     *     )
     * )
     */
    public function index(Request $request, Response $response): Response
    {
        try
        {
            $bouser = new UserHelper();


            if (!$bouser->is_logged_in()) {
//                $sessions = Sessions::getInstance();
//                $sessionId = $sessions->get_session_id();
//                $verified = $sessions->verify();

                return ResponseHelper::sendErrorResponse([
                    'error' => 'Not authenticated',
//                    'debug' => [
//                        'session_present' => !!$sessions->get_session_id(),
//                        'session_verified' => $sessions->verify(),
//                        'session_id' => $sessions->get_session_id(),
//                        'orgnr' => $bouser->orgnr,
//                        'ssn' => $bouser->ssn,
//                        'org_id' => $bouser->org_id,
//                        'orgname' => $bouser->orgname
//                    ]
                ], 401);
            }


            // Check if user is logged in first
            if (!$bouser->is_logged_in())
            {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Not authenticated'],
                    401
                );
            }

            // Validate SSN login and get user data
            $external_login_info = $bouser->validate_ssn_login([], true);
            if (empty($external_login_info))
            {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Invalid or expired session'],
                    401
                );
            }


            $userModel = new User($bouser);
//            if (isset($userModel->ssn)) {
//                $userModel->ssn = $this->maskSSN($userModel->ssn);
//            }
            $serialized = $userModel->serialize();

            $response->getBody()->write(json_encode($serialized));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(200);

        } catch (Exception $e)
        {
            // Log the error but don't expose internal details
            error_log("Error in user endpoint: " . $e->getMessage());

            return ResponseHelper::sendErrorResponse(
                ['error' => 'Internal server error'],
                500
            );
        }
    }

    /**
     * @OA\Patch(
     *     path="/bookingfrontend/user",
     *     summary="Update user details",
     *     tags={"User"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="name", type="string"),
     *             @OA\Property(property="homepage", type="string"),
     *             @OA\Property(property="phone", type="string"),
     *             @OA\Property(property="email", type="string"),
     *             @OA\Property(property="street", type="string"),
     *             @OA\Property(property="zip_code", type="string"),
     *             @OA\Property(property="city", type="string")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="User updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="user", ref="#/components/schemas/User")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid input"
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="User not authenticated"
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="Forbidden - Cannot update other users"
     *     )
     * )
     */
    public function update(Request $request, Response $response): Response
    {
        try
        {
            $bouser = new UserHelper();

            if (!$bouser->is_logged_in())
            {
                $response->getBody()->write(json_encode(['error' => 'User not authenticated']));
                return $response->withHeader('Content-Type', 'application/json')
                    ->withStatus(401);
            }

            // Get current user's SSN
            $userSsn = $bouser->ssn;
            if (empty($userSsn))
            {
                $response->getBody()->write(json_encode(['error' => 'No SSN found for user']));
                return $response->withHeader('Content-Type', 'application/json')
                    ->withStatus(400);
            }

            // Get the user ID from SSN
            $userId = $bouser->get_user_id($userSsn);
            if (!$userId)
            {
                $response->getBody()->write(json_encode(['error' => 'User not found']));
                return $response->withHeader('Content-Type', 'application/json')
                    ->withStatus(404);
            }

            // Get update data from request body
            $data = json_decode($request->getBody()->getContents(), true);
            if (json_last_error() !== JSON_ERROR_NONE)
            {
                $response->getBody()->write(json_encode(['error' => 'Invalid JSON data']));
                return $response->withHeader('Content-Type', 'application/json')
                    ->withStatus(400);
            }

            // Only allow whitelisted fields
            $updateData = [];
            foreach ($data as $field => $value)
            {
                if (isset(self::ALLOWED_FIELDS[$field]))
                {
                    $updateData[$field] = $value;
                }
            }

            if (empty($updateData))
            {
                $response->getBody()->write(json_encode(['error' => 'No valid fields to update']));
                return $response->withHeader('Content-Type', 'application/json')
                    ->withStatus(400);
            }

            // Validate fields
            if (isset($updateData['email']))
            {
                if (!filter_var($updateData['email'], FILTER_VALIDATE_EMAIL))
                {
                    $response->getBody()->write(json_encode(['error' => 'Invalid email format']));
                    return $response->withHeader('Content-Type', 'application/json')
                        ->withStatus(400);
                }
            }

            if (isset($updateData['homepage']))
            {
                if (!empty($updateData['homepage']) && !filter_var($updateData['homepage'], FILTER_VALIDATE_URL))
                {
                    $response->getBody()->write(json_encode(['error' => 'Invalid homepage URL format']));
                    return $response->withHeader('Content-Type', 'application/json')
                        ->withStatus(400);
                }
            }

            // Build SQL using only whitelisted fields
            $setClauses = [];
            $params = [':id' => $userId];

            foreach ($updateData as $field => $value)
            {
                if (isset(self::ALLOWED_FIELDS[$field]))
                {
                    $setClauses[] = $field . ' = :' . $field;
                    $params[':' . $field] = $value;
                }
            }

            if (empty($setClauses))
            {
                $response->getBody()->write(json_encode(['error' => 'No valid fields to update']));
                return $response->withHeader('Content-Type', 'application/json')
                    ->withStatus(400);
            }

            $sql = "UPDATE bb_user SET " . implode(', ', $setClauses) . " WHERE id = :id";

            // Execute update
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);

            if ($stmt->rowCount() === 0)
            {
                $response->getBody()->write(json_encode(['error' => 'User not found or no changes made']));
                return $response->withHeader('Content-Type', 'application/json')
                    ->withStatus(404);
            }

            // Get updated user data
            $userModel = new User($bouser);
            $serialized = $userModel->serialize();

            $response->getBody()->write(json_encode([
                'message' => 'User updated successfully',
                'user' => $serialized
            ]));
            return $response->withHeader('Content-Type', 'application/json')
                ->withStatus(200);

        } catch (Exception $e)
        {
            $error = "Error updating user: " . $e->getMessage();
            $response->getBody()->write(json_encode(['error' => $error]));
            return $response->withHeader('Content-Type', 'application/json')
                ->withStatus(500);
        }
    }
    private function maskSSN(string $ssn): string {
        if (empty($ssn)) {
            return '';
        }
        // Keep first digits, replace last 5 with asterisks
        return substr($ssn, 0, -5) . '*****';
    }
}
