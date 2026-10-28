<?php

declare(strict_types=1);

namespace Drupal\drupal_mcp\Mcp;

use Drupal\Core\Session\AccountInterface;
use Drupal\simple_oauth\Authentication\TokenAuthUser;

/**
 * The authorization capability required by an MCP operation.
 *
 * A capability is granted only when the access token carries its OAuth scope
 * and the token's user holds its Drupal permission.
 */
enum OperationCapability: string {

  case Read = 'read';
  case Write = 'write';

  /**
   * Returns the OAuth scope for this capability.
   */
  public function scope(): string {
    return 'mcp:' . $this->value;
  }

  /**
   * Returns the Drupal permission required for this capability.
   */
  public function permission(): string {
    return 'access mcp ' . $this->value;
  }

  /**
   * Tests whether a token-authenticated account holds this capability.
   */
  public function isGrantedTo(AccountInterface $account): bool {
    return $account instanceof TokenAuthUser
      && \in_array($this->scope(), self::grantedScopes($account), TRUE)
      && $account->hasPermission($this->permission());
  }

  /**
   * Extracts the scope names granted to an account's access token.
   *
   * @return list<string>
   *   Scope names.
   */
  public static function grantedScopes(TokenAuthUser $account): array {
    $scopes = [];
    foreach ($account->getToken()->get('scopes')->getScopes() as $scope) {
      $scopes[] = $scope->getName();
    }
    return $scopes;
  }

}
