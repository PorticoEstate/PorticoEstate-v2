<?php

namespace Tests\Controllers;

require_once __DIR__ . '/../../vendor/autoload.php';

use App\modules\phpgwapi\controllers\ScimController;
use App\modules\phpgwapi\services\ScimProvisioningStore;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

class ScimControllerTest extends TestCase
{
	private array $userRow = [
		'account_id' => 42,
		'account_lid' => 'ola.nordmann',
		'account_firstname' => 'Ola',
		'account_lastname' => 'Nordmann',
		'account_status' => 'A',
		'account_type' => 'u',
		'external_id' => 'external-42',
	];

	/**
	 * @dataProvider metadataProvider
	 */
	public function testMetadataResponsesAreScimDocuments(string $method, int $expectedTotal): void
	{
		$request = (new ServerRequestFactory())->createServerRequest('GET', '/api/scim/v2');
		$response = (new ScimController())->{$method}($request, new Response());
		$payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

		$this->assertSame(200, $response->getStatusCode());
		$this->assertSame('application/scim+json', $response->getHeaderLine('Content-Type'));
		$this->assertNotEmpty($payload['schemas']);
		if ($expectedTotal > 0)
		{
			$this->assertSame($expectedTotal, $payload['totalResults']);
			$this->assertCount($expectedTotal, $payload['Resources']);
		}
	}

	public function metadataProvider(): array
	{
		return [
			'service provider config' => ['serviceProviderConfig', 0],
			'resource types' => ['resourceTypes', 2],
			'schemas' => ['schemas', 2],
		];
	}

	public function testListsUsersWithPaginationAndFilter(): void
	{
		$store = new ScimControllerFakeStore($this->userRow);
		$request = (new ServerRequestFactory())
			->createServerRequest('GET', 'https://example.test/api/scim/v2/Users')
			->withQueryParams([
				'startIndex' => '11',
				'count' => '5',
				'filter' => 'userName eq "ola.nordmann"',
			]);
		$response = (new ScimController($store))->users($request, new Response());
		$payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

		$this->assertSame(200, $response->getStatusCode());
		$this->assertSame(11, $payload['startIndex']);
		$this->assertSame(1, $payload['itemsPerPage']);
		$this->assertSame('42', $payload['Resources'][0]['id']);
		$this->assertSame(['u', 10, 5, ['attribute' => 'userName', 'value' => 'ola.nordmann']], $store->listCall);
	}

	public function testInvalidUserFilterReturnsScimError(): void
	{
		$request = (new ServerRequestFactory())
			->createServerRequest('GET', 'https://example.test/api/scim/v2/Users')
			->withQueryParams(['filter' => 'userName co "ola"']);
		$response = (new ScimController(new ScimControllerFakeStore($this->userRow)))
			->users($request, new Response());
		$payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

		$this->assertSame(400, $response->getStatusCode());
		$this->assertSame('invalidFilter', $payload['scimType']);
	}

	public function testGetsUserByStableAccountId(): void
	{
		$request = (new ServerRequestFactory())
			->createServerRequest('GET', 'https://example.test/api/scim/v2/Users/42');
		$response = (new ScimController(new ScimControllerFakeStore($this->userRow)))
			->user($request, new Response(), ['id' => '42']);
		$payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

		$this->assertSame(200, $response->getStatusCode());
		$this->assertSame('42', $payload['id']);
	}

	public function testConfiguredPublicBaseUrlOverridesInternalRequestHost(): void
	{
		$request = (new ServerRequestFactory())
			->createServerRequest('GET', 'http://slim/api/scim/v2/Users/42');
		$controller = new ScimController(
			new ScimControllerFakeStore($this->userRow),
			null,
			null,
			'https://portico.example/api/scim/v2/'
		);
		$response = $controller->user($request, new Response(), ['id' => '42']);
		$payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

		$this->assertSame('https://portico.example/api/scim/v2/Users/42', $payload['meta']['location']);
	}

	public function testUnknownUserReturnsScim404(): void
	{
		$request = (new ServerRequestFactory())
			->createServerRequest('GET', 'https://example.test/api/scim/v2/Users/99');
		$response = (new ScimController(new ScimControllerFakeStore(null)))
			->user($request, new Response(), ['id' => '99']);
		$payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

		$this->assertSame(404, $response->getStatusCode());
		$this->assertSame(['urn:ietf:params:scim:api:messages:2.0:Error'], $payload['schemas']);
	}

	public function testCreatesUserAndReturnsLocation(): void
	{
		$store = new ScimControllerFakeStore(null);
		$store->createdRow = $this->userRow;
		$request = (new ServerRequestFactory())
			->createServerRequest('POST', 'https://example.test/api/scim/v2/Users')
			->withParsedBody([
				'externalId' => 'external-42',
				'userName' => 'ola.nordmann',
				'name' => ['givenName' => 'Ola', 'familyName' => 'Nordmann'],
				'active' => true,
			]);
		$response = (new ScimController($store))->createUser($request, new Response());

		$this->assertSame(201, $response->getStatusCode());
		$this->assertSame('https://example.test/api/scim/v2/Users/42', $response->getHeaderLine('Location'));
		$this->assertSame('external-42', $store->createData['externalId']);
	}

	public function testCreateIsIdempotentByExternalId(): void
	{
		$store = new ScimControllerFakeStore($this->userRow);
		$request = (new ServerRequestFactory())
			->createServerRequest('POST', 'https://example.test/api/scim/v2/Users')
			->withParsedBody([
				'externalId' => 'external-42',
				'userName' => 'ola.nordmann',
				'name' => ['givenName' => 'Ola', 'familyName' => 'Nordmann'],
			]);
		$response = (new ScimController($store))->createUser($request, new Response());

		$this->assertSame(200, $response->getStatusCode());
		$this->assertSame([], $store->createData);
	}

	public function testPatchDeactivatesUser(): void
	{
		$store = new ScimControllerFakeStore($this->userRow);
		$store->updatedRow = array_merge($this->userRow, ['account_status' => 'I']);
		$request = (new ServerRequestFactory())
			->createServerRequest('PATCH', 'https://example.test/api/scim/v2/Users/42')
			->withParsedBody([
				'Operations' => [['op' => 'Replace', 'path' => 'active', 'value' => false]],
			]);
		$response = (new ScimController($store))->patchUser($request, new Response(), ['id' => '42']);
		$payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

		$this->assertSame(200, $response->getStatusCode());
		$this->assertFalse($store->updateData['active']);
		$this->assertFalse($payload['active']);
	}

	public function testEntraPathlessPatchAndStringBooleanAreAccepted(): void
	{
		$store = new ScimControllerFakeStore($this->userRow);
		$store->updatedRow = array_merge($this->userRow, [
			'account_firstname' => 'Kari',
			'account_status' => 'I',
		]);
		$request = (new ServerRequestFactory())
			->createServerRequest('PATCH', 'https://example.test/api/scim/v2/Users/42')
			->withParsedBody([
				'Operations' => [[
					'op' => 'Replace',
					'value' => ['active' => 'False', 'name.givenName' => 'Kari'],
				]],
			]);
		$response = (new ScimController($store))->patchUser($request, new Response(), ['id' => '42']);

		$this->assertSame(200, $response->getStatusCode());
		$this->assertFalse($store->updateData['active']);
		$this->assertSame('Kari', $store->updateData['givenName']);
	}

	public function testDeleteSoftDeactivatesUser(): void
	{
		$store = new ScimControllerFakeStore($this->userRow);
		$request = (new ServerRequestFactory())
			->createServerRequest('DELETE', 'https://example.test/api/scim/v2/Users/42');
		$response = (new ScimController($store))->deleteUser($request, new Response(), ['id' => '42']);

		$this->assertSame(204, $response->getStatusCode());
		$this->assertSame([42, 'u'], $store->deactivateCall);
	}

	public function testCreatesGroup(): void
	{
		$groupRow = ['account_id' => 7, 'account_lid' => 'SCIM Staff', 'account_type' => 'g', 'external_id' => 'group-7'];
		$store = new ScimControllerFakeStore(null);
		$store->createdGroupRow = $groupRow;
		$request = (new ServerRequestFactory())
			->createServerRequest('POST', 'https://example.test/api/scim/v2/Groups')
			->withParsedBody(['externalId' => 'group-7', 'displayName' => 'SCIM Staff']);
		$response = (new ScimController($store))->createGroup($request, new Response());

		$this->assertSame(201, $response->getStatusCode());
		$this->assertSame('SCIM Staff', $store->createGroupData['displayName']);
	}

	public function testPatchesGroupMembershipIdempotently(): void
	{
		$groupRow = ['account_id' => 7, 'account_lid' => 'SCIM Staff', 'account_type' => 'g', 'external_id' => 'group-7'];
		$store = new ScimControllerFakeStore($groupRow);
		$request = (new ServerRequestFactory())
			->createServerRequest('PATCH', 'https://example.test/api/scim/v2/Groups/7')
			->withParsedBody(['Operations' => [
				['op' => 'Add', 'path' => 'members', 'value' => [['value' => '42']]],
				['op' => 'Remove', 'path' => 'members[value eq "43"]'],
			]]);
		$response = (new ScimController($store))->patchGroup($request, new Response(), ['id' => '7']);

		$this->assertSame(200, $response->getStatusCode());
		$this->assertSame([7, 'SCIM Staff', [42], [43]], $store->patchGroupCall);
	}
}

final class ScimControllerFakeStore implements ScimProvisioningStore
{
	public ?array $row;
	public array $listCall = [];
	public array $createData = [];
	public array $updateData = [];
	public array $deactivateCall = [];
	public ?array $createdRow = null;
	public ?array $updatedRow = null;
	public array $createGroupData = [];
	public ?array $createdGroupRow = null;
	public array $members = [];
	public array $addedMembers = [];
	public array $removedMembers = [];
	public array $patchGroupCall = [];

	public function __construct(?array $row)
	{
		$this->row = $row;
	}

	public function findById(int $accountId, string $accountType): ?array
	{
		return $this->row;
	}

	public function findByExternalId(string $externalId, string $accountType): ?array
	{
		return $this->row;
	}

	public function list(string $accountType, int $offset, int $limit, ?array $filter = null): array
	{
		$this->listCall = [$accountType, $offset, $limit, $filter];

		return [
			'total' => $this->row === null ? 0 : 1,
			'resources' => $this->row === null ? [] : [$this->row],
		];
	}

	public function createUser(array $data): array
	{
		$this->createData = $data;

		return $this->createdRow ?? $this->row ?? [];
	}

	public function updateUser(int $accountId, array $data): ?array
	{
		$this->updateData = $data;

		return $this->updatedRow ?? $this->row;
	}

	public function deactivate(int $accountId, string $accountType): bool
	{
		$this->deactivateCall = [$accountId, $accountType];

		return $this->row !== null;
	}

	public function createGroup(array $data): array
	{
		$this->createGroupData = $data;

		return $this->createdGroupRow ?? [];
	}

	public function updateGroup(int $accountId, string $displayName): ?array
	{
		if ($this->row !== null)
		{
			$this->row['account_lid'] = $displayName;
		}

		return $this->row;
	}

	public function groupMembers(int $groupId): array
	{
		return $this->members;
	}

	public function addGroupMember(int $groupId, int $accountId): bool
	{
		$this->addedMembers[] = [$groupId, $accountId];

		return true;
	}

	public function removeGroupMember(int $groupId, int $accountId): bool
	{
		$this->removedMembers[] = [$groupId, $accountId];

		return true;
	}

	public function patchGroup(int $groupId, string $displayName, array $addMembers, array $removeMembers): ?array
	{
		$this->patchGroupCall = [$groupId, $displayName, $addMembers, $removeMembers];

		return $this->row;
	}
}