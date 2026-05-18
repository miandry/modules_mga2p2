<?php

namespace Drupal\mga2p2\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Shell for the mga2p2Form theme Vue app at /form.
 */
class SpaFormController extends ControllerBase {

  public function shell(): array {
    return [
      '#markup' => '',
      '#cache' => ['max-age' => 0],
    ];
  }

}
