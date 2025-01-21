<?php

namespace App\modules\bookingfrontend\controllers;

use App\modules\bookingfrontend\helpers\ResponseHelper;
use App\modules\bookingfrontend\services\EventService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\modules\phpgwapi\security\Sessions;
use Exception;

class EventController
{

    private EventService $service;

    public function __construct()
    {
        $this->service = new EventService();
    }

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
            if (!$data) {
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
}