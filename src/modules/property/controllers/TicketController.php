<?php

namespace App\modules\property\controllers;

use App\modules\property\models\Tenant;
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
	 *     path="/bookingfrontend/buildings",
	 *     summary="Get a list of all buildings",
	 *     tags={"Buildings"},
	 *     @OA\Response(
	 *         response=200,
	 *         description="A list of buildings",
	 *         @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/Building"))
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
		$ssn = '';Sanitizer::get_var('ssn', 'string', 'GET');

		$sql = "SELECT id, subject, status, entry_date, modified_date FROM fm_tts_tickets WHERE external_owner_ssn = :ssn ORDER BY id";

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
			$results = $stmt->fetchAll(\PDO::FETCH_ASSOC);


			$data = array(
				'total_records' => $total_records,
				'start' => $start,
				'sort' => $sort,
				'dir' => $dir,
				'results' => $results,
				'perPage' => $perPage,
			);

			// $tickets = array_map(function ($data)
			// {
			// 	$ticket = new Tenant($data);
			// 	return $ticket->serialize($this->getUserRoles());
			// }, $results);

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

			$response->getBody()->write(json_encode($result));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
		}
		catch (Exception $e)
		{
			$error = "Error fetching case: " . $e->getMessage();
			$response->getBody()->write(json_encode(['error' => $error]));
			return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
		}
	}


}
