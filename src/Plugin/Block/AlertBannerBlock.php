<?php

namespace Drupal\localgov_alert_banner\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\field\Entity\FieldStorageConfig;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the Alert banner block.
 *
 * @Block(
 *   id = "localgov_alert_banner_block",
 *   admin_label = @Translation("Alert banner"),
 *   category = @Translation("Localgov Alert banner"),
 * )
 */
class AlertBannerBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Current user service.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The entity repository services.
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface
   */
  protected $entityRepository;

  /**
   * Constructs a new AlertBannerBlock.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   Current user service.
   * @param Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, AccountProxyInterface $current_user, EntityRepositoryInterface $entity_repository) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->entityRepository = $entity_repository;
  }

  /**
   * Create the Alert banner block instance.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   Container object.
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   *
   * @return static
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('entity.repository'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'include_types' => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) : array {
    $form = parent::blockForm($form, $form_state);
    $type_storage = $this->entityTypeManager->getStorage('localgov_alert_banner_type');
    $config = $this->getConfiguration();
    $config_options = [];
    foreach ($type_storage->loadMultiple() as $id => $type) {
      $config_options[$id] = $type->label();
    }
    $form['include_types'] = [
      '#type' => 'checkboxes',
      '#options' => $config_options,
      '#title' => $this->t('Display types'),
      '#description' => $this->t('If no types are selected all will be displayed.'),
      '#default_value' => !empty($config['include_types']) ? $config['include_types'] : [],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    parent::blockSubmit($form, $form_state);
    $values = $form_state->getValues();
    $this->configuration['include_types'] = $values['include_types'];
  }

  /**
   * {@inheritdoc}
   */
  public function build() {

    // Fetch the current published banner.
    $published_alert_banners = $this->getCurrentAlertBanners();

    // If no banner found, return NULL so block is not rendered.
    if (empty($published_alert_banners)) {
      return NULL;
    }

    // Render the alert banner.
    $build = [];
    foreach ($published_alert_banners as $alert_banner) {

      // Only add to the build if it is visible.
      // @see #154.
      if ($alert_banner->isVisible()) {
        $build[] = $this->entityTypeManager->getViewBuilder('localgov_alert_banner')
          ->view($alert_banner);
      }
    }
    return $build;
  }

  /**
   * Get current alert banner(s).
   *
   * Note: Default order will be by the field type_of_alert
   * (only on the default) and then updated date.
   *
   * @return \Drupal\localgov_alert_banner\Entity\AlertBannerEntity[]
   *   Array of all published and visible alert banners.
   */
  protected function getCurrentAlertBanners() {

    $current_alert_banners = [];

    // Get list of published alert banner IDs.
    $types = $this->mapTypesConfigToQuery();
    $published_alert_banner_query = $this->entityTypeManager->getStorage('localgov_alert_banner')
      ->getQuery()
      ->condition('status', 1);

    // Only order by type of alert if the field is present.
    $alert_banner_has_type_of_alert = FieldStorageConfig::loadByName('localgov_alert_banner', 'type_of_alert');
    if (!empty($alert_banner_has_type_of_alert)) {
      $published_alert_banner_query->sort('type_of_alert', 'DESC');
    }

    // Continue alert banner query.
    $published_alert_banner_query->sort('changed', 'DESC');

    // If types (bunldes) are selected, add filter condition.
    if (!empty($types)) {
      $published_alert_banner_query->condition('type', $types, 'IN');
    }

    // Execute alert banner query.
    $published_alert_banners = $published_alert_banner_query
      ->accessCheck(TRUE)
      ->execute();

    // Load alert banners and add all.
    // Visibility check happens in build, so we get cache contexts on all.
    foreach ($published_alert_banners as $alert_banner_id) {
      $alert_banner = $this->entityTypeManager->getStorage('localgov_alert_banner')->load($alert_banner_id);
      $alert_banner = $this->entityRepository->getTranslationFromContext($alert_banner);

      $is_accessible = $alert_banner->access('view', $this->currentUser);
      if ($is_accessible) {
        $current_alert_banners[] = $alert_banner;
      }
    }

    return $current_alert_banners;
  }

  /**
   * Get an array of types from config we can use for querying.
   *
   * @return array
   *   An array of alter banner type IDs as keys and values.
   */
  protected function mapTypesConfigToQuery() : array {
    $include_types = $this->configuration['include_types'];
    return array_filter($include_types, function ($t) {
      return (bool) $t;
    });
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    $contexts = [];
    foreach ($this->getCurrentAlertBanners() as $alert_banner) {
      $contexts = Cache::mergeContexts($contexts, $alert_banner->getCacheContexts());
    }
    return Cache::mergeContexts(parent::getCacheContexts(), $contexts);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    // Invalidate cache on changes to alert banners.
    return Cache::mergeTags(parent::getCacheTags(), ['localgov_alert_banner_list']);
  }

}
