<?php

namespace Drupal\dw;


use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure Datawarehouse settings for this site.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * Constructs a \Drupal\dw\SettingsForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   */
  public function __construct(ConfigFactoryInterface $config_factory, ModuleHandlerInterface $module_handler) {
    parent::__construct($config_factory);

    $this->moduleHandler = $module_handler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('module_handler')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'dw_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['dw.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('dw.settings');
    $DataWarehouse = new \Drupal\dw\Controller\DataWarehouse();
    $form['status'] = [
      '#type' => 'details',
      '#title' => t('DataWarehouse Data Status'),
      '#open' => TRUE,
    ];
    $current_entity= $config->get('dw_entity');
    $record_keep_table = 'dw_entity_' . $current_entity;
    if (\Drupal::database()->schema()->tableExists($record_keep_table)){
      $source_count = $DataWarehouse->get_entity_count($current_entity);
      $target_count = $DataWarehouse->get_record_count($current_entity);
      $source_max = $DataWarehouse->get_entity_max_id($current_entity);
      $target_max = $DataWarehouse->get_max_id($current_entity);
      $diff = $source_count - $target_count;
      $form['status']['source_count'] = ['#markup' => "<strong>Records in Source table</strong>: " . $source_count, '#prefix' => '<p>', '#suffix' => '</p>'];
      $form['status']['count'] = ['#markup' => "<strong>Records in DW table</strong>: " . $target_count, '#prefix' => '<p>', '#suffix' => '</p>'];
      $form['status']['max'] = ['#markup' => "<strong>Last ID collected</strong>: " . $target_max, '#prefix' => '<p>', '#suffix' => '</p>'];
      if ($source_max != $target_max) {
        $form['status']['next'] = ['#markup' => "<strong>Max Source ID (not yet collected)</strong>: " . $source_max, '#prefix' => '<p>', '#suffix' => '</p>'];
      }
      if ($diff) {
        $form['status']['difference'] = ['#markup' => "<strong>Difference between Source and Target</strong>: " . $diff . " records will be collected on next Cron Run. (Actual number of records collected vary depending on view settings.)", '#prefix' => '<p>', '#suffix' => '</p>'];
        $form['status']['execute'] = [
          '#type' => 'link',
          '#title' => t("Start Manual Collection Now"),
          '#url' => Url::fromRoute('dw.execute'),
          '#attributes' => [
            'class' => ['use-ajax'],
            'data-dialog-type' => 'modal',
          ],
          '#prefix' => '<p>',
          '#suffix' => '</p>',
        ];
      }
    }else {
      $form['status']['difference'] = ['#markup' => "<strong>Tables do not exist yet. First Run has not Happened.</strong>", '#prefix' => '<p>', '#suffix' => '</p>'];
    }
      $form['data'] = [
        '#type' => 'details',
        '#title' => t('DataWarehouse Data settings'),
        '#open' => TRUE,
      ];
      $form['data']['dw_entity'] = [
        '#type' => 'textfield',
        '#title' => t('Entity Machine Name'),
        '#default_value' => $config->get('dw_entity'),
        '#description' => t('The Machine Name of the base entity that data is being collected from.'),
        '#required' => TRUE,
      ];
      $form['data']['dw_view'] = [
        '#type' => 'textfield',
        '#title' => t('View Machine Name'),
        '#default_value' => $config->get('dw_view'),
        '#description' => t('The Machine Name of the View that is used to collect data.'),
        '#required' => TRUE,
      ];
      $form['data']['dw_view_display'] = [
        '#type' => 'textfield',
        '#title' => t('View Display Machine Name'),
        '#default_value' => $config->get('dw_view_display'),
        '#description' => t('The Machine Name of the JSON Export View Display used during data collection'),
        '#required' => TRUE,
      ];
      if (!empty($config->get('dw_view')) && !empty($config->get('dw_view_display'))) {
        $form['data']['view_link'] = [
          '#markup' => "Data collected by this module is drawn from a JSON Export View, the currently defined view can be modified here: <a href='/admin/structure/views/view/" . $config->get('dw_view') . "/edit/" . $config->get('dw_view_display') . "'>Edit View Settings</a>",
          '#prefix' => '<p>',
          '#suffix' => '</p>',
        ];
      }
      $form['data']['dw_start_over'] = [
        '#type' => 'checkbox',
        '#title' => t('Start Over'),
        '#default_value' => $config->get('dw_start_over', 0),
        '#description' => t('Erases All DataWarehouse Data for the current Entity. Useful when the structure of the View has changed or the source data has been updated. Will only run once, and will automatically disable this setting.'),
      ];

    $form['cron'] = [
      '#type' => 'details',
      '#title' => t('DataWarehouse Cron settings'),
      '#open' => TRUE,
    ];
    $form['cron']['dw_active'] = [
      '#type' => 'checkbox',
      '#title' => t('Active'),
      '#default_value' => $config->get('dw_active'),
      '#description' => t('Turn off active DW collection'),
    ];
    $form['cron']['interval'] = [
      '#type' => 'number',
      '#title' => t('Cron Interval'),
      '#default_value' => $config->get('interval')/60,
      '#description' => t('How often (in minutes) can the DataWarehouse task collect data.'),
    ];
    $form['cron']['last_run'] = ['#markup'=>"Last Cron Run: ".\Drupal::service('date.formatter')->format(\Drupal::state()->get('dw.last_cron', 0), 'custom', 'm/d/Y h:i:sa')];
    $form['cron']['execute']=[
      '#type'=>'link',
      '#title'=>t("Start Manual Collection Now"),
      '#url'=>Url::fromRoute('dw.execute'),
      '#attributes' => [
        'class' => ['use-ajax'],
        'data-dialog-type' => 'modal',
      ],
      '#prefix' => '<p>',
      '#suffix' => '</p>',
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('dw.settings')
      ->set('dw_entity', $form_state->getValue('dw_entity'))
      ->set('dw_view', $form_state->getValue('dw_view'))
      ->set('dw_view_display', $form_state->getValue('dw_view_display'))
      ->set('dw_start_over', $form_state->getValue('dw_start_over'))
      ->set('dw_active', $form_state->getValue('dw_active'))
      ->set('interval', $form_state->getValue('interval')*60)
      ->save();

    parent::submitForm($form, $form_state);
  }

}
