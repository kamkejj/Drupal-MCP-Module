<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * Identifies requests for the MCP endpoint the way Drupal routing does.
 *
 * Subscribers that run before routing (authentication failures, CORS
 * preflights) cannot rely on the route name. Drupal's router matches paths
 * case-insensitively and ignores trailing slashes, so "/MCP" and "/mcp/"
 * reach the endpoint controller too; every path-based check must accept the
 * same variants or those requests bypass the endpoint's response policy.
 */
final class McpEndpoint {

  public const PATH = '/mcp';

  /**
   * Whether the request path resolves to the MCP endpoint route.
   */
  public static function matches(Request $request): bool {
    return strtolower(rtrim($request->getPathInfo(), '/')) === self::PATH;
  }

}
