<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_mcp\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\drupal_mcp\Mutation\ConfigurationMutator;
use Drupal\drupal_mcp\Mutation\MutationException;
use Drupal\field\Entity\FieldConfig;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\drupal_mcp\Traits\TokenCallerTrait;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Exercises generic configuration creation against real Drupal config entities.
 */
#[Group('drupal_mcp')]
#[RunTestsInSeparateProcesses]
final class ConfigurationMutationKernelTest extends KernelTestBase {

  use TokenCallerTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'field_ui', 'text', 'filter', 'file',
    'image', 'options', 'node', 'taxonomy', 'serialization', 'consumers',
    'simple_oauth', 'simple_oauth_21', 'simple_oauth_server_metadata',
    'drupal_mcp',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['user', 'node', 'taxonomy_term', 'consumer', 'oauth2_token'] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'filter', 'node', 'drupal_mcp']);
    Role::create([
      'id' => 'builder',
      'label' => 'Builder',
      'permissions' => [
        'access mcp write', 'administer content types', 'administer taxonomy',
        'administer node fields', 'administer node form display',
        'administer node display',
      ],
    ])->save();
    // Reserve UID 1 so tests exercise real permission ceilings.
    User::create(['name' => 'root'])->save();
  }

  /**
   * Tests reusable configuration creation and overwrite prevention.
   */
  public function testGenericCreationAndCollision(): void {
    $user = User::create(['name' => 'builder', 'roles' => ['builder'], 'status' => 1]);
    $user->save();
    $caller = $this->tokenCaller($user);
    $mutator = $this->container->get(ConfigurationMutator::class);
    $this->config('drupal_mcp.settings')
      ->set('families.configuration_mutation', TRUE)
      ->set('configuration.field_targets', ['node:story'])
      ->set('configuration.field_types', ['text_with_summary', 'entity_reference'])
      ->save();
    $mutator->create($caller, 'drupal_content_type_create', ['machine_name' => 'story', 'label' => 'Story']);
    $this->assertNotNull(NodeType::load('story'));
    $this->assertNull(FieldConfig::loadByName('node', 'story', 'body'));
    $mutator->create($caller, 'drupal_vocabulary_create', ['machine_name' => 'topics', 'label' => 'Topics']);
    $this->assertNotNull(Vocabulary::load('topics'));
    $mutator->create($caller, 'drupal_field_create', [
      'entity_type' => 'node',
      'bundle' => 'story',
      'field_name' => 'field_topics',
      'label' => 'Topics',
      'definition' => [
        'type' => 'entity_reference',
        'target_type' => 'taxonomy_term',
        'target_bundles' => ['topics'],
      ],
    ]);
    $field = FieldConfig::loadByName('node', 'story', 'field_topics');
    $this->assertSame(['topics' => 'topics'], $field->getSetting('handler_settings')['target_bundles']);
    // Production caches/key-value stores serialize field definitions, unlike
    // kernel memory backends. Exercise that boundary explicitly.
    $this->assertIsString(serialize($field));
    $this->assertIsString(serialize($field->getFieldStorageDefinition()));
    $installed = $this->container->get('entity.last_installed_schema.repository')
      ->getLastInstalledFieldStorageDefinitions('node');
    $this->assertIsString(serialize($installed));
    $this->assertSame([], $this->config('drupal_mcp.settings')->get('content.bundles'));
    $display = $this->container->get('entity_display.repository')->getFormDisplay('node', 'story');
    $this->assertSame('entity_reference_autocomplete', $display->getComponent('field_topics')['type']);
    $this->expectException(MutationException::class);
    $mutator->create($caller, 'drupal_content_type_create', ['machine_name' => 'story', 'label' => 'Overwrite']);
  }

  /**
   * Tests native administration permission enforcement.
   */
  public function testNativePermissionRequired(): void {
    $this->config('drupal_mcp.settings')->set('families.configuration_mutation', TRUE)->save();
    $user = User::create(['name' => 'reader', 'status' => 1]);
    $user->save();
    $this->expectException(MutationException::class);
    $this->container->get(ConfigurationMutator::class)->create(
      $this->tokenCaller($user), 'drupal_vocabulary_create',
      ['machine_name' => 'topics', 'label' => 'Topics'],
    );
  }

  /**
   * Tests explicit field target approval.
   */
  public function testFieldTargetDisabled(): void {
    $this->config('drupal_mcp.settings')->set('families.configuration_mutation', TRUE)->save();
    $user = User::create(['name' => 'builder', 'roles' => ['builder'], 'status' => 1]);
    $user->save();
    $this->expectException(MutationException::class);
    $this->container->get(ConfigurationMutator::class)->authorize(
      $this->tokenCaller($user), 'drupal_field_create',
      [
        'entity_type' => 'node',
        'bundle' => 'story',
        'definition' => ['type' => 'string'],
      ],
    );
  }

  /**
   * Tests default-off configuration creation.
   */
  public function testDefaultOff(): void {
    $user = User::create(['name' => 'builder', 'roles' => ['builder'], 'status' => 1]);
    $user->save();
    $this->expectException(MutationException::class);
    $this->container->get(ConfigurationMutator::class)->create(
      $this->tokenCaller($user), 'drupal_content_type_create',
      ['machine_name' => 'story', 'label' => 'Story'],
    );
  }

}
