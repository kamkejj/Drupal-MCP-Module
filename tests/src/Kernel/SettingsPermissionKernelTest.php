<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_mcp\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the dedicated permission guarding the MCP settings form.
 */
#[Group('drupal_mcp')]
#[RunTestsInSeparateProcesses]
final class SettingsPermissionKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'image',
    'options',
    'serialization',
    'path_alias',
    'consumers',
    'simple_oauth',
    'simple_oauth_21',
    'simple_oauth_server_metadata',
    'drupal_mcp',
  ];

  /**
   * Tests the settings route requires the restricted MCP permission.
   */
  public function testSettingsRouteRequiresRestrictedPermission(): void {
    $route = $this->container->get('router.route_provider')->getRouteByName('drupal_mcp.settings');
    $this->assertSame('administer drupal mcp', $route->getRequirement('_permission'));

    $permissions = $this->container->get('user.permissions')->getPermissions();
    $this->assertArrayHasKey('administer drupal mcp', $permissions);
    $this->assertTrue($permissions['administer drupal mcp']['restrict access']);
  }

  /**
   * Tests the update grants the permission only to existing administrators.
   */
  public function testUpdatePreservesExistingAdministratorAccess(): void {
    Role::create([
      'id' => 'site_admin',
      'label' => 'Site admin',
      'permissions' => ['administer site configuration'],
    ])->save();
    Role::create(['id' => 'editor', 'label' => 'Editor', 'permissions' => ['access content']])->save();

    $this->container->get('module_handler')->loadInclude('drupal_mcp', 'install');
    drupal_mcp_update_11004();

    $this->assertTrue(Role::load('site_admin')->hasPermission('administer drupal mcp'));
    $this->assertFalse(Role::load('editor')->hasPermission('administer drupal mcp'));
  }

}
