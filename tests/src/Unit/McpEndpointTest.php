<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_mcp\Unit;

use Drupal\drupal_mcp\Http\McpEndpoint;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests MCP endpoint path matching against Drupal's routing semantics.
 */
final class McpEndpointTest extends TestCase {

  /**
   * Tests that every path variant Drupal routes to /mcp is matched.
   */
  public function testMatchesRoutedVariants(): void {
    foreach (['/mcp', '/mcp/', '/mcp//', '/MCP', '/Mcp/'] as $path) {
      $this->assertTrue(McpEndpoint::matches(Request::create($path, 'POST')), $path);
    }
  }

  /**
   * Tests that other paths are not matched.
   */
  public function testRejectsOtherPaths(): void {
    foreach (['/', '/mcpx', '/mcp/extra', '/api/mcp', '/.well-known/oauth-protected-resource'] as $path) {
      $this->assertFalse(McpEndpoint::matches(Request::create($path, 'POST')), $path);
    }
  }

}
