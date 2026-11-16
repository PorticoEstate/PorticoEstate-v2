<?php

namespace Tests\Controllers;

require_once __DIR__ . '/../../vendor/autoload.php';

use App\modules\bookingfrontend\controllers\BuildingController;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

class InformationScreenBuildingSearchTest extends TestCase
{
	private function controllerWithStatement(\PDOStatement $statement): BuildingController
	{
		$db = new class($statement)
		{
			public string $sql = '';

			public function __construct(private \PDOStatement $statement) {}

			public function prepare(string $sql): \PDOStatement
			{
				$this->sql = $sql;
				if (!str_contains($sql, 'WHERE active = 1') || !str_contains($sql, 'LIMIT 20'))
				{
					throw new \LogicException('Search must be active-only and bounded');
				}
				return $this->statement;
			}
		};
		$controller = (new \ReflectionClass(BuildingController::class))->newInstanceWithoutConstructor();
		$property = new \ReflectionProperty(BuildingController::class, 'db');
		$property->setAccessible(true);
		$property->setValue($controller, $db);
		return $controller;
	}

	public function testSearchBindsNumericQueryAndReturnsOnlyIdAndName(): void
	{
		$statement = $this->createMock(\PDOStatement::class);
		$statement->expects($this->once())->method('execute')->with([
			':name' => '%10%', ':street' => '%10%', ':id' => 10,
		]);
		$statement->method('fetchAll')->with(\PDO::FETCH_ASSOC)->willReturn([
			['id' => '10', 'name' => 'Fana kulturhus'],
		]);
		$controller = $this->controllerWithStatement($statement);
		$request = (new ServerRequestFactory())->createServerRequest('GET', '/bookingfrontend/buildings/search')
			->withQueryParams(['query' => ' 10 ']);

		$response = $controller->search($request, new Response());

		$this->assertSame(200, $response->getStatusCode());
		$this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
		$this->assertSame(['results' => [['id' => 10, 'name' => 'Fana kulturhus']]],
			json_decode((string)$response->getBody(), true));
	}

	public function testSearchTreatsWildcardsAsLiteralCharacters(): void
	{
		$statement = $this->createMock(\PDOStatement::class);
		$statement->expects($this->once())->method('execute')->with([
			':name' => '%A\\_\\%%', ':street' => '%A\\_\\%%', ':id' => 0,
		]);
		$statement->method('fetchAll')->willReturn([]);
		$controller = $this->controllerWithStatement($statement);
		$request = (new ServerRequestFactory())->createServerRequest('GET', '/bookingfrontend/buildings/search')
			->withQueryParams(['query' => 'A_%']);

		$response = $controller->search($request, new Response());

		$this->assertSame(200, $response->getStatusCode());
		$this->assertSame(['results' => []], json_decode((string)$response->getBody(), true));
	}

	/** @dataProvider invalidQueries */
	public function testRejectsInvalidInputWithoutQueryingDatabase($query, int $status): void
	{
		$statement = $this->createMock(\PDOStatement::class);
		$statement->expects($this->never())->method('execute');
		$controller = $this->controllerWithStatement($statement);
		$request = (new ServerRequestFactory())->createServerRequest('GET', '/bookingfrontend/buildings/search')
			->withQueryParams(['query' => $query]);

		$response = $controller->search($request, new Response());

		$this->assertSame($status, $response->getStatusCode());
		$this->assertSame($status === 200 ? ['results' => []] : ['error' => 'Invalid search query'],
			json_decode((string)$response->getBody(), true));
	}

	public static function invalidQueries(): array
	{
		return [['', 200], ['a', 200], [' å ', 200], [['10'], 400], [str_repeat('a', 201), 400]];
	}
}
