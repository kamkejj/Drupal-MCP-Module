<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_mcp\Unit;

use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\drupal_mcp\Access\McpAccessPolicy;
use Drupal\drupal_mcp\Tool\SiteToolProvider;
use Drupal\simple_oauth\Authentication\TokenAuthUser;
use Drupal\Tests\drupal_mcp\Unit\Fixtures\ConfigFactoryStub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests the public site identity tool.
 */
final class SiteToolProviderTest extends TestCase {

  /**
   * Tests site identity includes Drupal's absolute front-page URL.
   */
  public function testSiteInfoIncludesSiteUrl(): void {
    $configFactory = new ConfigFactoryStub([
      'system.site' => [
        'name' => 'Example site',
        'slogan' => 'Example slogan',
        'langcode' => 'en',
        'mail' => 'private@example.com',
      ],
      'core.extension' => ['profile' => 'standard'],
    ]);
    $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
    $urlGenerator->expects($this->once())
      ->method('generateFromRoute')
      ->with('<front>', [], ['absolute' => TRUE])
      ->willReturn('https://example.com/drupal/');
    $provider = new SiteToolProvider(
      $configFactory,
      new McpAccessPolicy($configFactory, new NullLogger()),
      $urlGenerator,
    );
    $tools = $provider->tools();
    $siteInfo = array_values(array_filter($tools, fn ($tool) => $tool->name === 'drupal_site_info'))[0];
    $result = ($siteInfo->handler)([], $this->createMock(TokenAuthUser::class));

    $this->assertSame('https://example.com/drupal/', $result['site_url']);
    $this->assertSame('Example site', $result['site_name']);
    $this->assertSame('Example slogan', $result['site_slogan']);
    $this->assertSame('en', $result['default_langcode']);
    $this->assertSame('standard', $result['install_profile']);
    $this->assertTrue($result['mcp']['read_only']);
    $this->assertArrayNotHasKey('mail', $result);
    $this->assertStringNotContainsString('private@example.com', json_encode($result, JSON_THROW_ON_ERROR));
  }

}
