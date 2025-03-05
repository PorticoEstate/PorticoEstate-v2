<?php

namespace App\modules\bookingfrontend\controllers;

use App\modules\bookingfrontend\helpers\ResponseHelper;
use App\modules\bookingfrontend\services\OrganizationService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\modules\phpgwapi\security\Sessions;
use Exception;

/**
 * @OA\Tag(
 *     name="Organizations",
 *     description="API Endpoints for Organization"
 * )
 */
class OrganizationController 
{
    private OrganizationService $service;

    public function __construct(OrganizationService $service)
    {
        $this->service = $service;
    }

    public function getSubActivityList(Request $request, Response $response, $args)
    {
        $id = (int)$args['id'];
        $session = Sessions::getInstance();
        $session_id = $session->get_session_id();

        if (empty($session_id)) {
            $response->getBody()->write(json_encode(['error' => 'No active session']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        try {
            $result = $this->service->getSubActivityList($id);
            return ResponseHelper::sendJson($response, $result);
        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => 'Error' . $e->getMessage()],
                500
            );
        }
    }

    public function getOrganizationById(Request $request, Response $response, $args)
    {   
        $id = (int)$args['id'];
        $session = Sessions::getInstance();
        $session_id = $session->get_session_id();

        if (empty($session_id)) {
            $response->getBody()->write(json_encode(['error' => 'No active session']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        try {
            $result = $this->service->getOrganizationById($id);
            return ResponseHelper::sendJson($response, $result);
        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => 'Error' . $e->getMessage()],
                500
            );
        }
    }

    public function getDelegateById(Request $request, Response $response, $args)
    {   
        $id = (int)$args['delegateId'];
        $session = Sessions::getInstance();
        $session_id = $session->get_session_id();
 
        if (empty($session_id)) {
            $response->getBody()->write(json_encode(['error' => 'No active session']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        try {
            $result = $this->service->getDelegateById($id);
            return ResponseHelper::sendJson($response, $result);
        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => 'Error' . $e->getMessage()],
                500
            );
        }
    }

    public function getGroupById(Request $request, Response $response, $args)
    {   
        $id = (int)$args['groupId'];
        $session = Sessions::getInstance();
        $session_id = $session->get_session_id();
 
        if (empty($session_id)) {
            $response->getBody()->write(json_encode(['error' => 'No active session']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        try {
            $result = $this->service->getGroupById($id);
            return ResponseHelper::sendJson($response, $result);
        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => 'Error' . $e->getMessage()],
                500
            );
        }
    }

    public function patchDelegate(Request $request, Response $response, $args)
    {
        $delegateId = (int)$args['delegateId'];
        $session = Sessions::getInstance();
        $session_id = $session->get_session_id();

        if (empty($session_id)) {
            $response->getBody()->write(json_encode(['error' => 'No active session']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        } 

        if (!$this->service->delegateExist($delegateId)) {
            return ResponseHelper::sendErrorResponse(
                ['error' => 'Organization not found'],
                404
            );
        }

        try {
            $result = $this->service->patchDelegate($delegateId, $request->getParsedBody());
            return ResponseHelper::sendJson($response, $result);
        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => 'Error' . $e->getMessage()],
                500
            );
        }
    }

    public function createDelegate(Request $request, Response $response, $args)
    {
        $id = (int)$args['id'];
        $session = Sessions::getInstance();
        $session_id = $session->get_session_id();

        if (empty($session_id)) {
            $response->getBody()->write(json_encode(['error' => 'No active session']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        try {
            $result = $this->service->createDelegate($id, $request->getParsedBody());
            return ResponseHelper::sendJson($response, $result);
        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => 'Error' . $e->getMessage()],
                500
            );
        }
    }
 
    public function createGroup(Request $request, Response $response, $args)
    {
        $id = (int)$args['id'];
        $session = Sessions::getInstance();
        $session_id = $session->get_session_id();

        if (empty($session_id)) {
            $response->getBody()->write(json_encode(['error' => 'No active session']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        try {
            $result = $this->service->createGroup($id, $request->getParsedBody());
            return ResponseHelper::sendJson($response, $result);
        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => 'Error' . $e->getMessage()],
                500
            );
        }
    }

    public function patchGroup(Request $request, Response $response, $args)
    {
        $id = (int)$args['id'];
        $groupId = (int)$args['groupId'];
        $session = Sessions::getInstance();
        $session_id = $session->get_session_id();

        if (empty($session_id)) {
            $response->getBody()->write(json_encode(['error' => 'No active session']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }  

        if (!$this->service->existGroup($groupId)) {
            return ResponseHelper::sendErrorResponse(
                ['error' => 'Group not found'],
                404
            );
        }

        try {
            $result = $this->service->patchGroup($groupId, $request->getParsedBody());
            return ResponseHelper::sendJson($response, $result);
        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => 'Error' . $e->getMessage()],
                500
            );
        }
    }

    public function patchGroupLeader(Request $request, Response $response, $args)
    {
        $id = (int)$args['id'];
        $groupId = (int)$args['groupId'];
        $leaderId = (int)$args['leaderId'];
        $session = Sessions::getInstance();
        $session_id = $session->get_session_id();

        if (empty($session_id)) {
            $response->getBody()->write(json_encode(['error' => 'No active session']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        if (!$this->service->existGroup($groupId)) {
            return ResponseHelper::sendErrorResponse(
                ['error' => 'Group not found'],
                404
            );
        }
        if (!$this->service->existLeader($id)) {
            return ResponseHelper::sendErrorResponse(
                ['error' => 'Leader not found'],
                404
            );
        }

        try {
            $result = $this->service->patchGroupLeader($leaderId, $request->getParsedBody());
            return ResponseHelper::sendJson($response, $result);
        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => 'Error' . $e->getMessage()],
                500
            );
        }
    }
}