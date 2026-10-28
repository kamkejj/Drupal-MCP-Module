<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Http;

use Drupal\drupal_mcp\Settings;
use Symfony\Component\HttpFoundation\Request;

/**
 * Validates MCP request Host and Origin policy consistently.
 *
 * The same Settings lists also feed the SDK's DNS-rebinding and CORS
 * middlewares, so the gate and the transport can never disagree.
 */
final class McpRequestValidator {

  public function __construct(
    private readonly Settings $settings,
  ) {}

  /**
   * Returns a denial message, or NULL when the request is allowed.
   */
  public function validate(Request $request): ?string {
    $allowedHosts = $this->settings->allowedHosts();
    if (!\in_array(strtolower($request->getHost()), $allowedHosts, TRUE)) {
      return 'Forbidden: Invalid Host header.';
    }

    $origin = $request->headers->get('Origin');
    if ($origin === NULL) {
      return NULL;
    }

    $originHost = strtolower((string) (parse_url($origin, PHP_URL_HOST) ?: ''));
    if (!\in_array($origin, $this->settings->allowedOrigins(), TRUE) || !\in_array($originHost, $allowedHosts, TRUE)) {
      return 'Forbidden: Invalid Origin header.';
    }

    return NULL;
  }

}
