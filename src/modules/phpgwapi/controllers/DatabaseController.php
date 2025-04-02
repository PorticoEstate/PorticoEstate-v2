<?php

namespace App\modules\phpgwapi\controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Database\Db;

class DatabaseController
{

	// swagger documetation to the function
	/**
	 * Get a list of public tables in the database
	 * 
	 * @OA\Get(
	 *     path="/api/tables",
	 *     tags={"Database"},
	 *     summary="Get a list of public tables in the database",
	 *     description="This endpoint returns a list of public tables in the database, including metadata about their columns.",
	 *     operationId="getTables",
	 *     @OA\Response(
	 *         response=200,
	 *         description="Successful operation",
	 *         @OA\JsonContent(
	 *             type="array",
	 *             @OA\Items(
	 *                 type="object",
	 *                 @OA\Property(
	 *                     property="name",
	 *                     type="string",
	 *                     description="Table name"
	 *                 ),
	 *                 @OA\Property(
	 *                     property="columns",
	 *                     type="array",
	 *                     description="Table columns",
	 *                     @OA\Items(
	 *                         type="object",
	 *                         @OA\Property(
	 *                             property="name",
	 *                             type="string",
	 *                             description="Column name"
	 *                         ),
	 *                         @OA\Property(
	 *                             property="type",
	 *                             type="string",
	 *                             description="Column type"
	 *                         ),
	 *                         @OA\Property(
	 *                             property="length",
	 *                             type="integer",
	 *                             nullable=true,
	 *                             description="Column length"
	 *                         ),
	 *                         @OA\Property(
	 *                             property="nullable",
	 *                             type="boolean",
	 *                             description="Whether the column allows NULL values"
	 *                         ),
	 *                         @OA\Property(
	 *                             property="default",
	 *                             type="string",
	 *                             nullable=true,
	 *                             description="Default value for the column"
	 *                         ),
	 *                         @OA\Property(
	 *                             property="primary_key",
	 *                             type="boolean",
	 *                             description="Whether the column is a primary key"
	 *                         ),
	 *                         @OA\Property(
	 *                             property="unique_key",
	 *                             type="boolean",
	 *                             description="Whether the column has a unique constraint"
	 *                         ),
	 *                         @OA\Property(
	 *                             property="has_default",
	 *                             type="boolean",
	 *                             description="Whether the column has a default value"
	 *                         )
	 *                     )
	 *                 )
	 *             )
	 *         )
	 *     )
	 * )
	 */

	public function getTables(Request $request, Response $response): Response
	{

		$db = Db::getInstance();

		$tables = $db->table_names();

		$_tables = [];
		foreach ($tables as $table)
		{
			if (!preg_match("/^bb_/", $table))
			{
				continue;
			}

			$metadata = $db->metadata($table);
			$_tables[$table] = $metadata;
		}

		$response->getBody()->write(json_encode($_tables));
		return $response->withHeader('Content-Type', 'application/json');
	}

	/**
	 * Get data from a specific table
	 * 
	 * @OA\Get(
	 *     path="/api/tabledata/{table}",
	 *     tags={"Database"},
	 *     summary="Get data from a specific table",
	 *     description="This endpoint returns data from a specific table in the database. The structure of the returned dataset will depend on the chosen table.",
	 *     operationId="getTableData",
	 *     @OA\Parameter(
	 *         name="table",
	 *         in="path",
	 *         description="Table name",
	 *         required=true,
	 *         @OA\Schema(
	 *             type="string"
	 *         )
	 *     ),
	 *     @OA\Parameter(
	 *         name="page",
	 *         in="query",
	 *         description="Page number",
	 *         required=false,
	 *         @OA\Schema(
	 *             type="integer",
	 *             default=1,
	 *             minimum=1
	 *         )
	 *     ),
	 *     @OA\Parameter(
	 *         name="limit",
	 *         in="query",
	 *         description="Number of items per page. If set to a negative value, the entire dataset will be returned in one go.",
	 *         required=false,
	 *         @OA\Schema(
	 *             type="integer",
	 *             default=10
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=200,
	 *         description="Successful operation. The structure of the returned dataset will depend on the chosen table.",
	 *         @OA\JsonContent(
	 *             type="object",
	 *             @OA\Property(
	 *                 property="data",
	 *                 type="array",
	 *                 description="Array of records. The structure of each record will depend on the chosen table.",
	 *                 @OA\Items(
	 *                     type="object",
	 *                     additionalProperties=true
	 *                 )
	 *             ),
	 *             @OA\Property(
	 *                 property="pagination",
	 *                 type="object",
	 *                 description="Pagination details",
	 *                 @OA\Property(
	 *                     property="page",
	 *                     type="integer",
	 *                     description="Current page number"
	 *                 ),
	 *                 @OA\Property(
	 *                     property="limit",
	 *                     type="integer",
	 *                     description="Number of items per page"
	 *                 ),
	 *                 @OA\Property(
	 *                     property="total",
	 *                     type="integer",
	 *                     description="Total number of records"
	 *                 )
	 *             )
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=404,
	 *         description="Table not found or no records found",
	 *         @OA\JsonContent(
	 *             type="object",
	 *             @OA\Property(
	 *                 property="error",
	 *                 type="string",
	 *                 description="Error message"
	 *             )
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=403,
	 *         description="Table not public",
	 *         @OA\JsonContent(
	 *             type="object",
	 *             @OA\Property(
	 *                 property="error",
	 *                 type="string",
	 *                 description="Error message"
	 *             )
	 *         )
	 *     )
	 * )
	 */
	public function getTableData(Request $request, Response $response, $args): Response
	{
		$db = Db::getInstance();

		$table = $args['table'];

		//check if table exists and is public as return by getTables
		$tables = $db->table_names();
		if (!in_array($table, $tables))
		{
			$response->getBody()->write(json_encode(['error' => 'Table not found']));
			return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
		}

		//check that the table is public
		if (!preg_match("/^bb_/", $table))
		{
			$response->getBody()->write(json_encode(['error' => 'Table not public']));
			return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
		}

		// Pagination parameters
		$page = $request->getQueryParams()['page'] ?? 1;
		$limit = $request->getQueryParams()['limit'] ?? 10;

		if ($page < 1)
		{
			$page = 1;
		}
		if ($limit < 1)
		{
			$limit = null; // Fetch all records
		}

		$offset = ($page - 1) * ($limit ?? 0);

		// Fetch data from the table
		$query = "SELECT * FROM {$table}";
		if ($limit !== null)
		{
			$query .= " LIMIT :limit OFFSET :offset";
		}

		$stmt = $db->prepare($query);

		if ($limit !== null)
		{
			$stmt->bindValue(':limit', (int) $limit, \PDO::PARAM_INT);
			$stmt->bindValue(':offset', (int) $offset, \PDO::PARAM_INT);
		}

		$stmt->execute();
		$data = $stmt->fetchAll(\PDO::FETCH_ASSOC);

		if (empty($data))
		{
			$response->getBody()->write(json_encode(['error' => 'No records found']));
			return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
		}

		// Count total records
		$countQuery = "SELECT COUNT(*) as cnt FROM {$table}";
		$db->query($countQuery);
		$db->next_record();
		$total = (int)$db->f('cnt');

		// Return response
		$response->getBody()->write(json_encode([
			'data' => $data,
			'pagination' => [
				'page' => $page,
				'limit' => $limit,
				'total' => (int) $total
			]
		]));
		return $response->withHeader('Content-Type', 'application/json');
	}
}
