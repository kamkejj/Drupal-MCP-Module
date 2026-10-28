<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_mcp\Unit;

use Drupal\Core\Lock\LockBackendInterface;
use Drupal\drupal_mcp\Diagnostics\DatabaseInspector;
use Drupal\drupal_mcp\Diagnostics\ReadOnlyDatabaseConnection;
use Drupal\drupal_mcp\Mcp\SignedCursor;
use Drupal\drupal_mcp\Tool\DatabaseToolProvider;
use Drupal\simple_oauth\Authentication\TokenAuthUser;
use Drupal\Tests\drupal_mcp\Unit\Fixtures\ConfigFactoryStub;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\drupal_mcp\Settings;

/**
 * Tests native permission enforcement for database surfaces.
 */
final class DatabaseInspectorAccessTest extends TestCase {

  /**
   * Tests the surface catalogue is filtered by native permissions.
   */
  public function testTablesOnlyListsSurfacesAllowedForCurrentAccount(): void {
    $inspector = $this->inspector('administer users');

    $result = $inspector->tables($this->caller('administer users'));

    $this->assertSame(1, $result['count']);
    $this->assertSame(['users_public'], array_column($result['surfaces'], 'name'));
  }

  /**
   * Tests the catalogue tool hands its caller to the inspector.
   */
  public function testTablesToolFiltersForItsCaller(): void {
    $provider = new DatabaseToolProvider($this->inspector());
    $tools = array_column(array_map(
      static fn ($definition): array => ['name' => $definition->name, 'handler' => $definition->handler],
      $provider->tools(),
    ), 'handler', 'name');

    $result = $tools['drupal_database_tables']([], $this->caller('administer taxonomy'));

    $this->assertSame(['taxonomy_public'], array_column($result['surfaces'], 'name'));
  }

  /**
   * Tests direct surface access cannot bypass catalogue filtering.
   */
  public function testDescribeRejectsSurfaceWithoutNativePermission(): void {
    $inspector = $this->inspector('administer users');

    $this->expectException(ToolCallException::class);
    $this->expectExceptionMessage('Database surface "taxonomy_public" is not approved.');
    $inspector->describe($this->caller('administer users'), 'taxonomy_public');
  }

  /**
   * Builds an inspector whose database connection is never opened.
   */
  private function inspector(string ...$permissions): DatabaseInspector {
    return new DatabaseInspector(
      new ReadOnlyDatabaseConnection(),
      new Settings(new ConfigFactoryStub([
        Settings::NAME => [
          'database' => ['surfaces' => ['users_public', 'taxonomy_public']],
        ],
      ])),
      new RequestStack(),
      new NullLogger(),
      $this->createStub(LockBackendInterface::class),
      new SignedCursor(),
    );
  }

  /**
   * Builds a caller carrying only the supplied permissions.
   */
  private function caller(string ...$permissions): TokenAuthUser {
    $caller = $this->createMock(TokenAuthUser::class);
    $caller->method('hasPermission')->willReturnCallback(
      static fn (string $permission): bool => \in_array($permission, $permissions, TRUE),
    );
    $caller->method('id')->willReturn(1);
    return $caller;
  }

}
