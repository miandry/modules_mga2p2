<?php

namespace Drupal\mga2p2\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Renders the SPA shell.
 *
 * Drupal must provide a real response for every path that the Vue Router
 * inside the mga2p2 theme knows about, otherwise a browser refresh on e.g.
 * /account returns a 404 from Drupal. This controller returns an empty
 * render array — the active theme (mga2p2) renders <div id="app"></div>
 * via templates/page.html.twig and Vue Router picks up from there.
 */
class SpaController extends ControllerBase {

  public function shell(): array {
    return [
      '#markup' => '',
      '#cache' => ['max-age' => 0],
    ];
  }

}
