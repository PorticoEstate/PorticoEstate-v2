<?php

namespace Tests\Controllers;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/helpers/Sanitizer.php';

use PHPUnit\Framework\TestCase;
use App\controllers\GenericRegistryController;
use App\models\GenericRegistry;
use App\modules\phpgwapi\security\Acl;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;

class GenericRegistryControllerTest extends TestCase
{
    private $request;
    private $response;
    private $acl;
    private $controller;

    protected function setUp(): void
    {
        $this->request = $this->createMock(ServerRequestInterface::class);
        $this->response = $this->createMock(ResponseInterface::class);
        $this->acl = $this->createMock(Acl::class);
        
        // Mock Response Body
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('write')->willReturn(0);
        $this->response->method('getBody')->willReturn($stream);
        $this->response->method('withHeader')->willReturn($this->response);
        $this->response->method('withStatus')->willReturn($this->response);

        // Setup Controller with Mock Registry
        $this->controller = new GenericRegistryController(MockGenericRegistry::class);
        
        // Inject Mock ACL using Reflection
        $reflection = new \ReflectionClass($this->controller);
        $aclProperty = $reflection->getProperty('acl');
        $aclProperty->setAccessible(true);
        $aclProperty->setValue($this->controller, $this->acl);
    }

    public function testIndexReturnsList()
    {
        // Arrange
        $this->request->method('getQueryParams')->willReturn(['start' => 0, 'limit' => 10]);
        $args = ['type' => 'test_type'];

        // Mock ACL check
        $this->acl->method('check')->willReturn(true);

        // Act
        $response = $this->controller->index($this->request, $this->response, $args);

        // Assert
        $this->assertInstanceOf(ResponseInterface::class, $response);
    }

    public function testIndexThrowsExceptionForMissingType()
    {
        $this->expectException(HttpBadRequestException::class);
        $this->controller->index($this->request, $this->response, []);
    }

    public function testIndexThrowsExceptionForInvalidType()
    {
        $this->expectException(HttpNotFoundException::class);
        $this->controller->index($this->request, $this->response, ['type' => 'invalid_type']);
    }

    public function testShowReturnsItem()
    {
        // Arrange
        $args = ['type' => 'test_type', 'id' => 1];
        $this->acl->method('check')->willReturn(true);

        // Act
        $response = $this->controller->show($this->request, $this->response, $args);

        // Assert
        $this->assertInstanceOf(ResponseInterface::class, $response);
    }

    public function testStoreCreatesItem()
    {
        // Arrange
        $args = ['type' => 'test_type'];
        $this->request->method('getParsedBody')->willReturn(['name' => 'New Item']);
        $this->acl->method('check')->willReturn(true);

        // Act
        $response = $this->controller->store($this->request, $this->response, $args);

        // Assert
        $this->assertInstanceOf(ResponseInterface::class, $response);
    }

    public function testUpdateModifiesItem()
    {
        // Arrange
        $args = ['type' => 'test_type', 'id' => 1];
        $this->request->method('getParsedBody')->willReturn(['name' => 'Updated Item']);
        $this->acl->method('check')->willReturn(true);

        // Act
        $response = $this->controller->update($this->request, $this->response, $args);

        // Assert
        $this->assertInstanceOf(ResponseInterface::class, $response);
    }

    public function testDeleteRemovesItem()
    {
        // Arrange
        $args = ['type' => 'test_type', 'id' => 1];
        $this->acl->method('check')->willReturn(true);

        // Act
        $response = $this->controller->delete($this->request, $this->response, $args);

        // Assert
        $this->assertInstanceOf(ResponseInterface::class, $response);
    }
}

/**
 * Mock Registry Class for Testing
 */
class MockGenericRegistry extends GenericRegistry
{
    public $name;

    public static function getAvailableTypes(): array
    {
        return ['test_type'];
    }

    public static function getRegistryConfig(string $type): array
    {
        if ($type === 'test_type') {
            return [
                'name' => 'Test Registry',
                'table' => 'test_table',
                'acl_location' => '.test',
                'acl_app' => 'test_app',
                'fields' => [
                    ['name' => 'name', 'type' => 'varchar', 'label' => 'Name']
                ]
            ];
        }
        return [];
    }

    protected static function loadRegistryDefinitions(): void
    {
        // No-op
    }

    // Mock Database Interactions
    public static function findWhereByType(string $type, array $conditions = [], array $options = []): array
    {
        // Return dummy data
        $item = new static($type);
        $item->id = 1;
        $item->name = 'Test Item';
        return [$item];
    }

    public static function findByType(string $type, int $id): ?static
    {
        if ($id === 1) {
            $item = new static($type);
            $item->id = 1;
            $item->name = 'Test Item';
            return $item;
        }
        return null;
    }

    public static function createForType(string $type, array $data = []): static
    {
        $item = new static($type);
        $item->populate($data);
        return $item;
    }

    public function save(): bool
    {
        $this->id = 1; // Simulate auto-increment
        return true;
    }

    public function delete(): bool
    {
        return true;
    }
    
    // Override to avoid DB calls in constructor/parent
    public function __construct(string $registryType = '', array $data = [])
    {
        $this->registryType = $registryType;
        $this->registryConfig = static::getRegistryConfig($registryType);
        // Skip parent constructor to avoid DB connection
    }
    
    public function populate(array $data): self
    {
        foreach ($data as $key => $value) {
            $this->$key = $value;
        }
        return $this;
    }
    
    public function toArray(array $context = [], bool $short = false): ?array
    {
        return [
            'id' => $this->id,
            'name' => $this->name ?? null
        ];
    }
}
