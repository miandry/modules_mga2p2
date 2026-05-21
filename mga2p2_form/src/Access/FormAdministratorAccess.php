<?php

namespace Drupal\mga2p2_form\Access;

use Drupal\Core\Session\AccountInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Restricts Binance ads / market tools to Drupal administrators.
 */
final class FormAdministratorAccess {

  private const NO_STORE = ['Cache-Control' => 'no-store, private'];

  public static function isAdministrator(AccountInterface $account): bool {
    if (!$account->isAuthenticated()) {
      return FALSE;
    }
    if ((int) $account->id() === 1) {
      return TRUE;
    }
    return in_array('administrator', $account->getRoles(), TRUE);
  }

  /**
   * Returns a 403 JSON response when the account is not an administrator.
   */
  public static function denyUnlessAdministrator(AccountInterface $account): ?JsonResponse {
    if (self::isAdministrator($account)) {
      return NULL;
    }
    return new JsonResponse([
      'error' => 'Accès réservé aux administrateurs.',
      'code' => 'administrator_required',
    ], 403, self::NO_STORE);
  }

  /**
   * @return array{uid: int, name: string, roles: array<int, string>, is_administrator: bool}
   */
  public static function userPayload(AccountInterface $account): array {
    return [
      'uid' => (int) $account->id(),
      'name' => $account->getAccountName(),
      'roles' => array_values($account->getRoles()),
      'is_administrator' => self::isAdministrator($account),
    ];
  }

}
