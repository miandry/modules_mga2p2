<?php

namespace Drupal\mga2p2\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\mga2p2\BinanceClient;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin form for Binance API credentials and proxy options.
 */
class SettingsForm extends ConfigFormBase {

  protected BinanceClient $client;

  public function __construct($configFactory, $typedConfigManager, BinanceClient $client) {
    parent::__construct($configFactory, $typedConfigManager);
    $this->client = $client;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('mga2p2.binance_client'),
    );
  }

  protected function getEditableConfigNames() {
    return ['mga2p2.settings'];
  }

  public function getFormId() {
    return 'mga2p2_settings_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('mga2p2.settings');

    $form['credentials'] = [
      '#type' => 'details',
      '#title' => $this->t('Binance API credentials'),
      '#description' => $this->t('Create restricted keys in <a href="@url" target="_blank">Binance API Management</a>. Enable only "Reading" + (optionally) "Spot Trading". <strong>Never enable withdrawals.</strong>', [
        '@url' => 'https://www.binance.com/en/my/settings/api-management',
      ]),
      '#open' => TRUE,
    ];

    $form['credentials']['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API key'),
      '#default_value' => $config->get('api_key'),
      '#maxlength' => 128,
      '#size' => 80,
    ];

    $form['credentials']['secret_key'] = [
      '#type' => 'password',
      '#title' => $this->t('Secret key'),
      '#description' => $this->t('Leave empty to keep the existing value.'),
      '#size' => 80,
    ];

    $form['credentials']['has_secret'] = [
      '#type' => 'item',
      '#title' => $this->t('Stored secret'),
      '#markup' => $config->get('secret_key')
        ? '<span style="color:#0ECB81">✓ ' . $this->t('A secret is stored.') . '</span>'
        : '<span style="color:#F6465D">✗ ' . $this->t('No secret stored yet.') . '</span>',
    ];

    $form['proxy'] = [
      '#type' => 'details',
      '#title' => $this->t('Proxy options'),
      '#open' => TRUE,
    ];

    $form['proxy']['allow_anonymous_proxy'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow anonymous users to call the proxy'),
      '#description' => $this->t('If unchecked, only users with the "Use the Binance API proxy" permission can call /binance-proxy.'),
      '#default_value' => $config->get('allow_anonymous_proxy'),
    ];

    $form['proxy']['recv_window'] = [
      '#type' => 'number',
      '#title' => $this->t('recvWindow (ms)'),
      '#description' => $this->t('Max time the request is valid (Binance default 5000, max 60000).'),
      '#default_value' => $config->get('recv_window') ?: 10000,
      '#min' => 1000,
      '#max' => 60000,
    ];

    $form['proxy']['allowed_path_prefixes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Allowed path prefixes'),
      '#description' => $this->t('One per line. Requests not matching are rejected.'),
      '#default_value' => implode("\n", $config->get('allowed_path_prefixes') ?: []),
      '#rows' => 4,
    ];

    $form['openai'] = [
      '#type' => 'details',
      '#title' => $this->t('Receipt AI (OpenAI-compatible vision)'),
      '#description' => $this->t('Used by the <em>mga2p2Form</em> theme at <code>/form</code>. You can also set the key in <code>settings.php</code> as <code>$settings[\'mga2p2_openai_api_key\']</code>, or export <code>MGA2P2_OPENAI_API_KEY</code> / <code>OPENAI_API_KEY</code> for Apache/PHP-FPM (restart after env changes).'),
      '#open' => TRUE,
    ];
    $form['openai']['openai_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API key'),
      '#default_value' => $config->get('openai_api_key') ?? '',
      '#maxlength' => 512,
      '#size' => 80,
      '#description' => $this->t('Save the form after pasting your key. If the field looks empty after save, the key may still be loaded from environment or settings.php.'),
    ];
    $form['openai']['openai_base_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Base URL'),
      '#default_value' => $config->get('openai_base_url') ?: 'https://api.openai.com/v1',
      '#maxlength' => 256,
      '#size' => 80,
    ];
    $form['openai']['openai_model'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Model'),
      '#default_value' => $config->get('openai_model') ?: 'gpt-4o-mini',
      '#maxlength' => 128,
    ];

    $form['test'] = [
      '#type' => 'details',
      '#title' => $this->t('Test connection'),
      '#open' => $this->client->isConfigured(),
    ];

    if ($this->client->isConfigured()) {
      $form['test']['help'] = [
        '#markup' => $this->t('Open <a href="@u" target="_blank">@u</a> in your browser. You should get a JSON response with your spot balances.', [
          '@u' => '/binance-proxy?path=/api/v3/account',
        ]),
      ];
    }
    else {
      $form['test']['help'] = [
        '#markup' => '<em>' . $this->t('Save credentials first, then test.') . '</em>',
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('mga2p2.settings');
    $config->set('api_key', trim((string) $form_state->getValue('api_key')));

    // Only overwrite the secret when a new value is typed in.
    $newSecret = trim((string) $form_state->getValue('secret_key'));
    if ($newSecret !== '') {
      $config->set('secret_key', $newSecret);
    }

    $config->set('allow_anonymous_proxy', (bool) $form_state->getValue('allow_anonymous_proxy'));
    $config->set('recv_window', (int) $form_state->getValue('recv_window'));

    $prefixes = preg_split('/\r?\n/', (string) $form_state->getValue('allowed_path_prefixes'));
    $prefixes = array_values(array_filter(array_map('trim', $prefixes)));
    if ($prefixes === []) {
      $prefixes = [
        '/api/v3/',
        '/sapi/v1/',
      ];
    }
    $config->set('allowed_path_prefixes', $prefixes);

    $newOpenai = trim((string) $form_state->getValue('openai_api_key'));
    $config->set('openai_api_key', $newOpenai);
    $config->set('openai_base_url', rtrim(trim((string) $form_state->getValue('openai_base_url')), '/'));
    $config->set('openai_model', trim((string) $form_state->getValue('openai_model')));

    $config->save();

    parent::submitForm($form, $form_state);
  }

}
