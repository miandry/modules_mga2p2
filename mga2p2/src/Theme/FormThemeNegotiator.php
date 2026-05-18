<?php

namespace Drupal\mga2p2\Theme;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Theme\ThemeNegotiatorInterface;

/**
 * Uses mga2p2Form theme on /form routes (Vue receipt app).
 */
class FormThemeNegotiator implements ThemeNegotiatorInterface {

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match) {
    if (!\Drupal::service('theme_handler')->themeExists('mga2p2Form')) {
      return FALSE;
    }
    $name = $route_match->getRouteName();
    return $name === 'mga2p2.spa_form.form' || $name === 'mga2p2.spa_form.form_sub';
  }

  /**
   * {@inheritdoc}
   */
  public function determineActiveTheme(RouteMatchInterface $route_match) {
    return 'mga2p2Form';
  }

}
