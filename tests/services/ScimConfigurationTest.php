<?php

namespace Tests\Services;

require_once __DIR__ . '/../../vendor/autoload.php';

use App\modules\phpgwapi\services\ScimConfiguration;
use PHPUnit\Framework\TestCase;

class ScimConfigurationTest extends TestCase
{
	public function testDatabaseSettingsOverrideEnvironmentValues(): void
	{
		$settings = [
			'SCIM_TENANT_ID' => 'tenant-from-db',
			'SCIM_BEARER_TOKEN' => 'token-from-db',
			'SCIM_PUBLIC_BASE_URL' => 'https://db.example.no/api/scim/v2',
		];
		$environment = [
			'SCIM_TENANT_ID' => 'tenant-from-env',
			'SCIM_BEARER_TOKEN' => 'token-from-env',
			'SCIM_PUBLIC_BASE_URL' => 'https://env.example.no/api/scim/v2',
		];

		foreach ($environment as $name => $value)
		{
			putenv("{$name}={$value}");
		}

		try
		{
			$configuration = new ScimConfiguration($settings);
			foreach ($settings as $name => $value)
			{
				$this->assertSame($value, $configuration->get($name));
			}
		}
		finally
		{
			foreach (array_keys($environment) as $name)
			{
				putenv($name);
			}
		}
	}

	public function testEmptyDatabaseSettingFallsBackToEnvironment(): void
	{
		putenv('SCIM_TENANT_ID=tenant-from-env');
		try
		{
			$configuration = new ScimConfiguration(['SCIM_TENANT_ID' => '  ']);
			$this->assertSame('tenant-from-env', $configuration->get('SCIM_TENANT_ID'));
		}
		finally
		{
			putenv('SCIM_TENANT_ID');
		}
	}
}