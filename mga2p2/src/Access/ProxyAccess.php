<?php

namespace Drupal\mga2p2\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;

/**
 * Custom access check for the Binance proxy endpoint.
 *
 * Allows anonymous users only if the "allow_anonymous_proxy" config flag is
 * enabled; otherwise requires the "use mga2p2 proxy" permission.
 */
class ProxyAccess {

  public static function check(AccountInterface $account) {
    $config = \Drupal::config('mga2p2.settings');

    if ($config->get('allow_anonymous_proxy')) {
      return AccessResult::allowed()
        ->addCacheTags(['config:mga2p2.settings']);
    }

    return AccessResult::allowedIfHasPermission($account, 'use mga2p2 proxy')
      ->addCacheTags(['config:mga2p2.settings']);
  }

}
