<?php

namespace App\modules\property\controllers;

use App\modules\property\models\Ticket;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Exception;
use App\modules\phpgwapi\services\Settings;
use App\Database\Db;
use PDO;
use OpenApi\Annotations as OA;
use Slim\Psr7\Stream;
use App\traits\SerializableTrait;
use Sanitizer;

/**
 * @OA\Tag(
 *     name="Buildings",
 *     description="API Endpoints for Buildings"
 * )
 */
class TicketController
{
	private $db;
	private $userSettings;
	public function __construct(ContainerInterface $container)
	{

		$this->db = Db::getInstance();
		$this->userSettings = Settings::getInstance()->get('user');
	}

	private function getUserRoles()
	{
		return $this->userSettings['groups'] ?? [];
	}

	/**
	 * @OA\Get(
	 *     path="/property/usercase/",
	 *     summary="Get a list of user cases",
	 *     tags={"User Cases"},
	 *     @OA\Response(
	 *         response=200,
	 *         description="A list of user cases",
	 *         @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/UserCase"))
	 *     )
	 * )
	 */
	public function getUserCases(Request $request, Response $response): Response
	{
		$maxMatches = isset($this->userSettings['preferences']['common']['maxmatchs']) ? (int)$this->userSettings['preferences']['common']['maxmatchs'] : 15;
		$queryParams = $request->getQueryParams();
		$start = isset($queryParams['start']) ? (int)$queryParams['start'] : 0;
		$perPage = isset($queryParams['results']) ? (int)$queryParams['results'] : $maxMatches;
		$sort = isset($queryParams['sort']) ? $queryParams['sort'] : 'id';
		$dir = isset($queryParams['dir']) ? $queryParams['dir'] : 'ASC';
		$ssn = Sanitizer::get_var('ssn', 'string', 'GET');

		$sql = "SELECT id, subject, status, entry_date, modified_date FROM fm_tts_tickets WHERE external_owner_ssn = :ssn";

		if ($sort && in_array($sort, ['id', 'entry_date', 'modified_date']))
		{
			$sql .= " ORDER BY $sort $dir";
		}
		else
		{
			$sql .= " ORDER BY id";
		}

		try
		{
			$stmt = $this->db->prepare($sql);
			$stmt->bindParam(':ssn', $ssn, \PDO::PARAM_STR);

			$total_records = 0;
			$stmt->execute();
			$total_records = $stmt->rowCount();

			if ($perPage > 0)
			{
				if ($perPage > 0)
				{
					$sql .= " LIMIT :limit OFFSET :start";
				}
				$stmt = $this->db->prepare($sql);
				$stmt->bindParam(':ssn', $ssn, \PDO::PARAM_STR);
				$stmt->bindParam(':limit', $perPage, \PDO::PARAM_INT);
				$stmt->bindParam(':start', $start, \PDO::PARAM_INT);
			}
			$stmt->execute();
			$results = (array)$stmt->fetchAll(\PDO::FETCH_ASSOC);

			$ticket = new Ticket();

			$tickets = [];
			foreach ($results as $entry)
			{
				$ticket->populate($entry);
				$tickets[] = $ticket->serialize($this->getUserRoles());
			}

			$data = array(
				'total_records' => $total_records,
				'start' => $start,
				'sort' => $sort,
				'dir' => $dir,
				'results' => $tickets,
				'perPage' => $perPage,
			);

			$response->getBody()->write(json_encode($data));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
		}
		catch (Exception $e)
		{
			$error = "Error fetching tickets: " . $e->getMessage();
			$response->getBody()->write(json_encode(['error' => $error]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}


	/**
	 * @OA\Get(
	 *     path="/property/usercase/{id}/",
	 *     summary="Get a user case",
	 *     tags={"User Cases"},
	 *     @OA\Parameter(
	 *         name="id",
	 *         in="path",
	 *         description="ID of the user case",
	 *         required=true,
	 *         @OA\Schema(type="integer")
	 *     ),
	 *     @OA\Response(
	 *         response=200,
	 *         description="A user case",
	 *         @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/UserCase"))
	 *     ),
	 *     @OA\Response(
	 *         response=404,
	 *         description="Case not found",
	 *         @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/Error"))
	 *     )
	 * )
	 */
	public function getUserCase(Request $request, Response $response, array $args): Response
	{
		$ssn = Sanitizer::get_var('ssn', 'string', 'GET');

		//get id from url
		$id = $args['id'];
		try
		{
			$sql = "SELECT * FROM fm_tts_tickets"
				. " WHERE external_owner_ssn = :ssn AND id = :id";
			$stmt = $this->db->prepare($sql);
			$stmt->bindParam(':ssn', $ssn, \PDO::PARAM_STR);
			$stmt->bindParam(':id', $id, \PDO::PARAM_INT);
			$stmt->execute();

			$result = $stmt->fetch(\PDO::FETCH_ASSOC);

			if (!$result)
			{
				$response->getBody()->write(json_encode(['error' => 'Case not found']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
			}


			$ticket = new Ticket($result);
			$_additional_notes = $ticket->get_additional_notes();

			$additional_notes = [];
			foreach ($_additional_notes as $note)
			{
				if ($note['value_publish'])
				{
					$additional_notes[] = $note;
				}
			}

			$record_history	 = $ticket->get_record_history();

			$history = array_merge($additional_notes, $record_history);

			usort($history, function ($a, $b)
			{
				return intval($a['value_id']) <=> intval($b['value_id']);
			});

			$response->getBody()->write(json_encode(['ticket' => $ticket->serialize($this->getUserRoles()), 'history' => $history]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
		}
		catch (Exception $e)
		{
			$error = "Error fetching case: " . $e->getMessage();
			$response->getBody()->write(json_encode(['error' => $error]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}


	/**
	 * @OA\Post(
	 *     path="/property/usercase/{caseId}/response/",
	 *     summary="Add a response to a user case",
	 *     tags={"User Cases"},
	 *     @OA\Parameter(
	 *         name="caseId",
	 *         in="path",
	 *         description="ID of the user case",
	 *         required=true,
	 *         @OA\Schema(type="integer")
	 *     ),
	 *     @OA\RequestBody(
	 *         required=true,
	 *         description="Response content",
	 *         @OA\JsonContent(
	 *             @OA\Property(property="content", type="string", description="Response text"),
	 *             @OA\Property(property="user_name", type="string", description="User name"),
	 *             @OA\Property(property="attachment", type="object", description="Optional attachment")
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=200,
	 *         description="Response added successfully",
	 *         @OA\JsonContent(type="object", 
	 *             @OA\Property(property="ticket", ref="#/components/schemas/UserCase"),
	 *             @OA\Property(property="message", type="string")
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=404,
	 *         description="Case not found",
	 *         @OA\JsonContent(ref="#/components/schemas/Error")
	 *     ),
	 *     @OA\Response(
	 *         response=500,
	 *         description="Server error",
	 *         @OA\JsonContent(ref="#/components/schemas/Error")
	 *     )
	 * )
	 */
	public function addUserCaseResponse(Request $request, Response $response, array $args): Response
	{
		$ssn = Sanitizer::get_var('ssn', 'string', 'POST');
		$caseId = $args['caseId'];

		// Get form data
		$data = $request->getParsedBody();
		$content = $data['content'] ?? '';
		$user_name = $data['user_name'] ?? 'Kunde';
		$attachment = null;

		// Process attachment if present
		$attachment = null;

		$uploadedFiles = $request->getUploadedFiles();
		if (!empty($uploadedFiles['files']))
		{
			$uploadedFile = $uploadedFiles['files'];

			if ($uploadedFile->getError() === UPLOAD_ERR_OK)
			{
				$stream = $uploadedFile->getStream();
				$contents = (string)$stream;
				$attachment = [
					'name' => $uploadedFile->getClientFilename(),
					'type' => $uploadedFile->getClientMediaType(),
					'size' => $uploadedFile->getSize(),
					'content' => $contents
				];
			}
		}

		try
		{
			// Verify the ticket exists and belongs to the user
			$sql = "SELECT * FROM fm_tts_tickets WHERE id = :id AND external_owner_ssn = :ssn";
			$stmt = $this->db->prepare($sql);
			$stmt->bindParam(':id', $caseId, \PDO::PARAM_INT);
			$stmt->bindParam(':ssn', $ssn, \PDO::PARAM_STR);
			$stmt->execute();

			$result = $stmt->fetch(\PDO::FETCH_ASSOC);

			if (!$result)
			{
				$response->getBody()->write(json_encode(['error' => 'Case not found or access denied']));
				return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
			}

			$ticket = new Ticket($result);
			$this->db->transaction_begin();
			// Add the response
			$ticket->add_comment($content, $user_name, true); // true for publish

			// Add attachment if present
			if ($attachment)
			{
				$ticket->add_attachment($attachment);
			}

			$this->db->transaction_commit();

			$response->getBody()->write(json_encode([
				'ticket_id' => $caseId,
				'status' => 'success',
				'message' => 'Response added successfully'
			]));

			return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
		}
		catch (Exception $e)
		{
			$error = "Error adding response: " . $e->getMessage();
			$response->getBody()->write(json_encode(['error' => $error]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}
}
