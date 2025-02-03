<?php

namespace App\modules\bookingfrontend\controllers;

use App\modules\bookingfrontend\helpers\ResponseHelper;
use App\modules\bookingfrontend\services\EventService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\modules\phpgwapi\security\Sessions;
use Exception;

/**
 * @OA\Tag(
 *     name="Events",
 *     description="API Endpoints for Events"
 * )
 */
class EventController
{

    private EventService $service;

    public function __construct()
    {
        $this->service = new EventService();
    }
    /**
     * @OA\Get(
     *     path="/bookingfrontend/events/{id}",
     *     summary="Get a specific event by ID",
     *     tags={"Events"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID of the event to fetch",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="The requested event",
     *         @OA\JsonContent(ref="#/components/schemas/Event")
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="No active session"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Event not found"
     *     )
     * )
     */
    public function getEventById(Request $request, Response $response, array $args)
    {
        $id = (int)$args['id'];
        $session = Sessions::getInstance();
        $session_id = $session->get_session_id();

        if (empty($session_id)) {
            $response->getBody()->write(json_encode(['error' => 'No active session']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        try {
            $data = $this->service->getEventById($id);
            if (!$data['event']) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Event not found'],
                    404
                );
            }

            $response->getBody()->write(json_encode($data));
            return $response->withStatus(200)
                ->withHeader('Content-Type', 'application/json');
        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => 'Error' . $e->getMessage()],
                500
            );
        }
    }
    /**
     * @OA\Patch(
     *     path="/bookingfrontend/events/{id}",
     *     summary="Partially update an Event",
     *     tags={"Events"},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID of the Event to update",
     *         @OA\Schema(type="integer")
     *     ),
     *      @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="name", type="string"),
     *             @OA\Property(property="from_", type="string", format="date-time"),
     *             @OA\Property(property="to_", type="string", format="date-time")
     *             @OA\Property(property="organizer", type="string"),
     *             @OA\Property(property="participant_limit", type="integer"),
     *             @OA\Property(
     *                 property="resource_ids",
     *                 type="array",
     *                 description="Complete replacement of resources",
     *                 @OA\Items(type="integer")
     *             ),
     *         )
     *      ),
     *      @OA\Response(
     *         response=201,
     *         description="Event updated successfully",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="id", type="integer")
     *             @OA\Property(property="message", type="string")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="No active session"
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid JSON data"
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="Forbidden"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Event not found"
     *     )
     * )
     */
    public function updateEvent(Request $request, Response $response, array $args)
    {
        $id = (int)$args['id'];
        $session = Sessions::getInstance();
        $session_id = $session->get_session_id();

        if (empty($session_id)) {
            $response->getBody()->write(json_encode(['error' => 'No active session']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $newData = json_decode($request->getBody()->getContents(), true);
        if (!$newData) {
            return ResponseHelper::sendErrorResponse(
                ['error' => 'Invalid JSON data'],
                400
            );
        }

        $existingEvent = $this->service->getPartialEventObjectById($id);
        if (!$existingEvent) {
            return ResponseHelper::sendErrorResponse(
                ['error' => 'Event not found'],
                404
            );
        }

        $access = $this->service->checkEventOwnerShip($existingEvent);
        if (!$access) {
            return ResponseHelper::sendErrorResponse(
                ['error' => 'Forbidden'],
                403
            );
        }
    
        try {
            $id = $this->service->updateEvent($newData, $existingEvent);

            $responseData = [
                'id' => $id,
                'message' => 'Event updated successfully'
            ];

            $response->getBody()->write(json_encode($responseData));
            return $response->withStatus(201)
                ->withHeader('Content-Type', 'application/json');
            
        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => 'Error updating the event: ' . $e->getMessage()],
                500
            );
        }
    }

    public function preRegister(Request $request, Response $response, array $args)
    {
        $id = (int)$args['id'];
        $session = Sessions::getInstance();
        $session_id = $session->get_session_id();

        if (empty($session_id)) {
            $response->getBody()->write(json_encode(['error' => 'No active session']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $newData = json_decode($request->getBody()->getContents(), true);
        if (!$newData) {
            return ResponseHelper::sendErrorResponse(
                ['error' => 'Invalid JSON data'],
                400
            );
        }

        $existingEvent = $this->service->getPartialEventObjectById($id);
        if (!$existingEvent) {
            return ResponseHelper::sendErrorResponse(
                ['error' => 'Event not found'],
                404
            );
        }

        try {
            $id = $this->service->preRegister($newData,  $existingEvent);
            if (!$id) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Incorrect data'],
                    400
                );
            }
            $responseData = [
                'id' => $id,
                'message' => 'Pre-registration successfull'
            ];
            $response->getBody()->write(json_encode($responseData));
            return $response->withStatus(201)
                ->withHeader('Content-Type', 'application/json');

        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => 'Error updating the event: ' . $e->getMessage()],
                500
            );
        }
    }

    public function inRegistration(Request $request, Response $response, array $args)
    {
        $id = (int)$args['id'];
        $session = Sessions::getInstance();
        $session_id = $session->get_session_id();

        if (empty($session_id)) {
            $response->getBody()->write(json_encode(['error' => 'No active session']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $data = json_decode($request->getBody()->getContents(), true);
        if (!$data) {
            return ResponseHelper::sendErrorResponse(
                ['error' => 'Invalid JSON data'],
                400
            );
        }

        $existingEvent = $this->service->getPartialEventObjectById($id);
        if (!$existingEvent) {
            return ResponseHelper::sendErrorResponse(
                ['error' => 'Event not found'],
                404
            );
        }

        try {
            $id = $this->service->inRegister($data, $existingEvent);
            if (!$id) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Incorrect data'],
                    400
                );
            }
            $responseData = [
                'id' => $id,
                'message' => 'Pre-registration successfull'
            ];
            $response->getBody()->write(json_encode($responseData));
            return $response->withStatus(201)
                ->withHeader('Content-Type', 'application/json');
        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => 'Error updating the event: ' . $e->getMessage()],
                500
            );
        }
    }

    public function outRegistration(Request $request, Response $response, array $args)
    {
        $id = (int)$args['id'];
        $session = Sessions::getInstance();
        $session_id = $session->get_session_id();

        if (empty($session_id)) {
            $response->getBody()->write(json_encode(['error' => 'No active session']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $data = json_decode($request->getBody()->getContents(), true);
        if (!$data) {
            return ResponseHelper::sendErrorResponse(
                ['error' => 'Invalid JSON data'],
                400
            );
        }

        $existingEvent = $this->service->getPartialEventObjectById($id);
        if (!$existingEvent) {
            return ResponseHelper::sendErrorResponse(
                ['error' => 'Event not found'],
                404
            );
        }

        try {
            $id = $this->service->outRegistration($data['phone'], $existingEvent);
            if (!$id) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Incorrect data'],
                    400
                );
            }
            $responseData = [
                'id' => $id,
                'message' => 'Pre-registration successfull'
            ];
            $response->getBody()->write(json_encode($responseData));
            return $response->withStatus(201)
                ->withHeader('Content-Type', 'application/json');
        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => 'Error updating the event: ' . $e->getMessage()],
                500
            );
        }
    }
}