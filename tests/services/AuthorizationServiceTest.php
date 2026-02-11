<?php

namespace Tests\Services;

require_once __DIR__ . '/../../vendor/autoload.php';

use PHPUnit\Framework\TestCase;
use App\modules\booking\authorization\DocumentBuildingAuthConfig;
use App\modules\booking\authorization\DocumentOrganizationAuthConfig;
use App\modules\booking\authorization\DocumentResourceAuthConfig;
use App\modules\booking\repositories\PermissionRepository;
use App\modules\booking\services\AuthorizationService;
use App\modules\phpgwapi\security\Acl;

class AuthorizationServiceTest extends TestCase
{
    private PermissionRepository $permissionRepo;
    private Acl $acl;

    private const SUBJECT_ID = 100;
    private const DOCUMENT_ID = 10;
    private const BUILDING_ID = 20;
    private const RESOURCE_ID = 30;
    private const ORG_ID = 40;
    private const GRANDPARENT_BUILDING_ID = 42;

    protected function setUp(): void
    {
        $this->permissionRepo = $this->createMock(PermissionRepository::class);
        $this->acl = $this->createMock(Acl::class);
    }

    private function createService(): AuthorizationService
    {
        return new AuthorizationService(
            $this->permissionRepo,
            $this->acl,
            self::SUBJECT_ID
        );
    }

    private function allBuildingFields(): array
    {
        return array_fill_keys(
            ['name', 'description', 'category', 'owner_id', 'focal_point_x', 'focal_point_y', 'rotation'],
            true
        );
    }

    private function allNonBuildingFields(): array
    {
        return array_fill_keys(
            ['name', 'description', 'category', 'focal_point_x', 'focal_point_y', 'rotation'],
            true
        );
    }

    private function setAdminBypass(bool $isAdmin): void
    {
        $this->acl->method('check')->willReturnCallback(
            function (string $location, int $required, string $appname) use ($isAdmin) {
                if ($isAdmin) {
                    if ($location === 'run' && $appname === 'admin') {
                        return true;
                    }
                }
                return false;
            }
        );
    }

    private function setNoRoles(): void
    {
        $this->permissionRepo->method('getGlobalRoles')->willReturn([]);
        $this->permissionRepo->method('getObjectRoles')->willReturn([]);
        $this->permissionRepo->method('getBuildingIdForResource')->willReturn(null);
    }

    // Test 1: Admin bypass
    public function testAdminBypassGrantsFullWrite(): void
    {
        $this->setAdminBypass(true);
        $this->setNoRoles();

        $service = $this->createService();
        $config = new DocumentBuildingAuthConfig();

        $result = $service->authorize($config, 'write', [
            'id' => self::DOCUMENT_ID,
            'owner_id' => self::BUILDING_ID,
        ]);

        $this->assertEquals($this->allBuildingFields(), $result);
    }

    // Test 2: Default read — always allowed
    public function testDefaultReadAllowed(): void
    {
        $this->setAdminBypass(false);
        $this->setNoRoles();

        $service = $this->createService();
        $config = new DocumentBuildingAuthConfig();

        $result = $service->authorize($config, 'read');

        $this->assertTrue($result);
    }

    // Test 3: Default write denied (no roles)
    public function testDefaultWriteDenied(): void
    {
        $this->setAdminBypass(false);
        $this->setNoRoles();

        $service = $this->createService();
        $config = new DocumentBuildingAuthConfig();

        $result = $service->authorize($config, 'write', [
            'id' => self::DOCUMENT_ID,
            'owner_id' => self::BUILDING_ID,
        ]);

        $this->assertFalse($result);
    }

    // Test 4: Default delete denied (no roles)
    public function testDefaultDeleteDenied(): void
    {
        $this->setAdminBypass(false);
        $this->setNoRoles();

        $service = $this->createService();
        $config = new DocumentBuildingAuthConfig();

        $result = $service->authorize($config, 'delete', [
            'id' => self::DOCUMENT_ID,
            'owner_id' => self::BUILDING_ID,
        ]);

        $this->assertFalse($result);
    }

    // Test 5: Global manager gets full write
    public function testGlobalManagerWrite(): void
    {
        $this->setAdminBypass(false);
        $this->permissionRepo->method('getGlobalRoles')
            ->willReturn([['role' => 'manager']]);
        $this->permissionRepo->method('getObjectRoles')->willReturn([]);

        $service = $this->createService();
        $config = new DocumentBuildingAuthConfig();

        $result = $service->authorize($config, 'write', [
            'id' => self::DOCUMENT_ID,
            'owner_id' => self::BUILDING_ID,
        ]);

        $this->assertEquals($this->allBuildingFields(), $result);
    }

    // Test 6: Global manager gets delete
    public function testGlobalManagerDelete(): void
    {
        $this->setAdminBypass(false);
        $this->permissionRepo->method('getGlobalRoles')
            ->willReturn([['role' => 'manager']]);
        $this->permissionRepo->method('getObjectRoles')->willReturn([]);

        $service = $this->createService();
        $config = new DocumentBuildingAuthConfig();

        $result = $service->authorize($config, 'delete', [
            'id' => self::DOCUMENT_ID,
            'owner_id' => self::BUILDING_ID,
        ]);

        $this->assertTrue($result);
    }

    // Test 7: Building manager on building doc gets full write
    public function testBuildingManagerOnBuildingDocWrite(): void
    {
        $this->setAdminBypass(false);
        $this->permissionRepo->method('getGlobalRoles')->willReturn([]);
        $this->permissionRepo->method('getObjectRoles')
            ->willReturnCallback(function (int $subjectId, int $objectId, string $objectType) {
                if ($objectId === self::BUILDING_ID && $objectType === 'building') {
                    return [['role' => 'manager']];
                }
                return [];
            });

        $service = $this->createService();
        $config = new DocumentBuildingAuthConfig();

        $result = $service->authorize($config, 'write', [
            'id' => self::DOCUMENT_ID,
            'owner_id' => self::BUILDING_ID,
        ]);

        $this->assertEquals($this->allBuildingFields(), $result);
    }

    // Test 8: Building case officer gets partial write
    public function testBuildingCaseOfficerPartialWrite(): void
    {
        $this->setAdminBypass(false);
        $this->permissionRepo->method('getGlobalRoles')->willReturn([]);
        $this->permissionRepo->method('getObjectRoles')
            ->willReturnCallback(function (int $subjectId, int $objectId, string $objectType) {
                if ($objectId === self::BUILDING_ID && $objectType === 'building') {
                    return [['role' => 'case_officer']];
                }
                return [];
            });

        $service = $this->createService();
        $config = new DocumentBuildingAuthConfig();

        $result = $service->authorize($config, 'write', [
            'id' => self::DOCUMENT_ID,
            'owner_id' => self::BUILDING_ID,
        ]);

        $this->assertEquals(['category' => true, 'description' => true], $result);
    }

    // Test 9: Resource manager on resource doc gets full write
    public function testResourceManagerOnResourceDocWrite(): void
    {
        $this->setAdminBypass(false);
        $this->permissionRepo->method('getGlobalRoles')->willReturn([]);
        $this->permissionRepo->method('getObjectRoles')
            ->willReturnCallback(function (int $subjectId, int $objectId, string $objectType) {
                if ($objectId === self::RESOURCE_ID && $objectType === 'resource') {
                    return [['role' => 'manager']];
                }
                return [];
            });

        $service = $this->createService();
        $config = new DocumentResourceAuthConfig();

        $result = $service->authorize($config, 'write', [
            'id' => self::DOCUMENT_ID,
            'owner_id' => self::RESOURCE_ID,
        ]);

        $this->assertEquals($this->allNonBuildingFields(), $result);
    }

    // Test 10: Building manager on resource doc via grandparent chain
    public function testBuildingManagerOnResourceDocViaGrandparent(): void
    {
        $this->setAdminBypass(false);
        $this->permissionRepo->method('getGlobalRoles')->willReturn([]);
        $this->permissionRepo->method('getObjectRoles')
            ->willReturnCallback(function (int $subjectId, int $objectId, string $objectType) {
                if ($objectId === self::GRANDPARENT_BUILDING_ID && $objectType === 'building') {
                    return [['role' => 'manager']];
                }
                return [];
            });
        $this->permissionRepo->method('getBuildingIdForResource')
            ->with(self::RESOURCE_ID)
            ->willReturn(self::GRANDPARENT_BUILDING_ID);

        $service = $this->createService();
        $config = new DocumentResourceAuthConfig();

        $result = $service->authorize($config, 'write', [
            'id' => self::DOCUMENT_ID,
            'owner_id' => self::RESOURCE_ID,
        ]);

        $this->assertEquals($this->allNonBuildingFields(), $result);
    }

    // Test 11: Building case officer on resource doc via grandparent chain
    public function testBuildingCaseOfficerOnResourceDocViaGrandparent(): void
    {
        $this->setAdminBypass(false);
        $this->permissionRepo->method('getGlobalRoles')->willReturn([]);
        $this->permissionRepo->method('getObjectRoles')
            ->willReturnCallback(function (int $subjectId, int $objectId, string $objectType) {
                if ($objectId === self::GRANDPARENT_BUILDING_ID && $objectType === 'building') {
                    return [['role' => 'case_officer']];
                }
                return [];
            });
        $this->permissionRepo->method('getBuildingIdForResource')
            ->with(self::RESOURCE_ID)
            ->willReturn(self::GRANDPARENT_BUILDING_ID);

        $service = $this->createService();
        $config = new DocumentResourceAuthConfig();

        $result = $service->authorize($config, 'write', [
            'id' => self::DOCUMENT_ID,
            'owner_id' => self::RESOURCE_ID,
        ]);

        $this->assertEquals(['category' => true, 'description' => true], $result);
    }

    // Test 12: Org manager on org doc gets full write
    public function testOrgManagerOnOrgDocWrite(): void
    {
        $this->setAdminBypass(false);
        $this->permissionRepo->method('getGlobalRoles')->willReturn([]);
        $this->permissionRepo->method('getObjectRoles')
            ->willReturnCallback(function (int $subjectId, int $objectId, string $objectType) {
                if ($objectId === self::ORG_ID && $objectType === 'organization') {
                    return [['role' => 'manager']];
                }
                return [];
            });

        $service = $this->createService();
        $config = new DocumentOrganizationAuthConfig();

        $result = $service->authorize($config, 'write', [
            'id' => self::DOCUMENT_ID,
            'owner_id' => self::ORG_ID,
        ]);

        $this->assertEquals($this->allNonBuildingFields(), $result);
    }

    // Test 13: No roles anywhere — write and delete denied
    public function testNoRolesAnywhereDenied(): void
    {
        $this->setAdminBypass(false);
        $this->setNoRoles();

        $service = $this->createService();
        $config = new DocumentBuildingAuthConfig();
        $entity = ['id' => self::DOCUMENT_ID, 'owner_id' => self::BUILDING_ID];

        $this->assertFalse($service->authorize($config, 'write', $entity));
        $this->assertFalse($service->authorize($config, 'delete', $entity));
    }
}
