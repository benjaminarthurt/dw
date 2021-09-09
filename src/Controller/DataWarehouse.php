<?php

namespace Drupal\dw\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\views\Views;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Core\Url;


class DataWarehouse extends ControllerBase
{

  public function execute()
  {
    $settings =  \Drupal::service('config.factory')->getEditable('dw.settings');
    $entity_type = $settings->get('dw_entity');
    $view_name = $settings->get('dw_view');
    $view_display = $settings->get('dw_view_display');
    if($settings->get('dw_start_over')){
      $this->drop_table($entity_type);
      $settings->set('dw_start_over',0);
      $settings->save();
    }
    $start = $this->get_record_count($entity_type);
    $this->run($entity_type, $view_name, $view_display);
    $end = $this->get_record_count($entity_type);
    $diff = $end-$start;
    $response = new Response($diff);
    if($diff){
      \Drupal::state()->set('dw.last_cron', \Drupal::time()->getRequestTime());
    }
    return $response;
  }

  public function cron()
  {
    $settings =  \Drupal::service('config.factory')->getEditable('dw.settings');
    $entity_type = $settings->get('dw_entity');
    $view_name = $settings->get('dw_view');
    $view_display = $settings->get('dw_view_display');
    if($settings->get('dw_start_over')){
      $this->drop_table($entity_type);
      $settings->set('dw_start_over',0);
      $settings->save();
    }
    $output = $this->run($entity_type, $view_name, $view_display);
    \Drupal::logger('dw')->notice('dw cron ran. New Records added: ' . count($output));
    return ($output ? count($output) : 0);
  }

  private function run($entity_type, $view_name, $view_display)
  {
    $view = Views::getView($view_name);
    $record_keep_table = 'dw_entity_' . $entity_type;
    if (\Drupal::database()->schema()->tableExists($record_keep_table)) {
      $max = $this->get_max_id($entity_type);
      $view->setExposedInput(['id' => $max]);
    }
    $render_array = $view->buildRenderable($view_display, [], FALSE);
    $rendered = \Drupal::service('renderer')->renderRoot($render_array);
    if (is_object($rendered)) {
      $json_string = $rendered->jsonSerialize();
      $json_object = json_decode($json_string);
      if (count((array) $json_object)) {
        $this->create_table($entity_type, $json_object);
        if ($this->bulk_insert($entity_type, $json_object)) {
          return $json_object;
        }
      }
    }
    return false;
  }

  private function create_table($entity_type, $data)
  {
    $record_keep_table = 'dw_entity_' . $entity_type;
    if (!\Drupal::database()->schema()->tableExists($record_keep_table)) {
      $schema = $this->generate_schema($data);
      \Drupal::database()->schema()->createTable($record_keep_table, $schema);
    }
  }

  private function generate_schema($input, $fields_insert = false)
  {
    $rand = rand(0, count($input));
    $data = (array)$input[$rand];
    $keys = array_keys($data);
    $schema = [];
    $schema['fields'] = [];
    if (!$fields_insert) {
      $schema['fields']['dw_id'] = ['type' => 'serial', 'not null' => TRUE];
    }
    foreach ($keys as $key) {
      switch ($key) {
        case 'id':
          $schema['fields']['id'] = ['type' => 'int', 'not null' => TRUE];
          break;
        case 'created':
          $schema['fields']['created'] = ['type' => 'int', 'not null' => TRUE];
          $this->dynamic_date_fields('created', $schema);
          break;
        case 'changed':
          $schema['fields']['changed'] = ['type' => 'int', 'not null' => TRUE];
          $this->dynamic_date_fields('changed', $schema);
          break;
        default:
          if (strlen($data[$key]) > 255) {
            $schema['fields'][$key] = ['type' => 'text', 'size' => 'big', 'not null' => FALSE];
          } else {
            $schema['fields'][$key] = ['type' => 'varchar', 'length' => 255, 'not null' => FALSE];
          }
      }
    }
    $schema['primary key'] = ['dw_id'];
    if ($fields_insert) {
      return array_keys($schema['fields']);
    }
    return $schema;
  }

  private function dynamic_date_fields($field_name, &$schema)
  {
    $schema['fields'][$field_name . "_datetime_local"] = ['type' => 'varchar', 'length' => 45, 'not null' => FALSE];
    $schema['fields'][$field_name . "_datetime_local_short"] = ['type' => 'varchar', 'length' => 45, 'not null' => FALSE];
    $schema['fields'][$field_name . "_date"] = ['type' => 'varchar', 'length' => 45, 'not null' => FALSE];
    $schema['fields'][$field_name . "_year"] = ['type' => 'int', 'size' => 'small', 'not null' => FALSE];
    $schema['fields'][$field_name . "_month"] = ['type' => 'varchar', 'length' => 20, 'not null' => FALSE];
    $schema['fields'][$field_name . "_month_num"] = ['type' => 'int', 'size' => 'small', 'not null' => FALSE];
    $schema['fields'][$field_name . "_year_month"] = ['type' => 'float', 'size' => 'small', 'precision' => 2, 'not null' => FALSE];
    $schema['fields'][$field_name . "_q"] = ['type' => 'varchar', 'length' => 2, 'not null' => FALSE];
    $schema['fields'][$field_name . "_year_q"] = ['type' => 'float', 'size' => 'small', 'precision' => 2, 'not null' => FALSE];
    $schema['fields'][$field_name . "_c"] = ['type' => 'varchar', 'length' => 45, 'not null' => FALSE];
    $schema['fields'][$field_name . "_r"] = ['type' => 'varchar', 'length' => 45, 'not null' => FALSE];
    $schema['fields'][$field_name . "_day"] = ['type' => 'varchar', 'length' => 20, 'not null' => FALSE];
    $schema['fields'][$field_name . "_week"] = ['type' => 'int', 'size' => 'small', 'not null' => FALSE];
    $schema['fields'][$field_name . "_year_week"] = ['type' => 'float', 'size' => 'small', 'precision' => 2, 'not null' => FALSE];
  }

  private function dynamic_date_values($field_name, $data)
  {
    $location_of = strpos($field_name, "_");
    $function = substr($field_name, $location_of);
    $parent_field = substr($field_name, 0, $location_of);
    $parent_field_value = $data[$parent_field];
    switch ($function) {
      case '_datetime_local':
        return \Drupal::service('date.formatter')->format($parent_field_value, 'custom', 'F j, Y h:ia');
        break;
      case '_datetime_local_short':
        return \Drupal::service('date.formatter')->format($parent_field_value, 'custom', 'm/d/Y h:ia');
        break;
      case '_date':
        return \Drupal::service('date.formatter')->format($parent_field_value, 'custom', 'm/d/Y');
        break;
      case '_year':
        return \Drupal::service('date.formatter')->format($parent_field_value, 'custom', 'Y');
        break;
      case '_month':
        return \Drupal::service('date.formatter')->format($parent_field_value, 'custom', 'F');
        break;
      case '_month_num':
        return \Drupal::service('date.formatter')->format($parent_field_value, 'custom', 'm');
        break;
      case '_year_month':
        return \Drupal::service('date.formatter')->format($parent_field_value, 'custom', 'Ym');
        break;
      case '_c':
        //	ISO 8601 date
        return \Drupal::service('date.formatter')->format($parent_field_value, 'custom', 'c');
        break;
      case '_r':
        //RFC 2822 formatted date
        return \Drupal::service('date.formatter')->format($parent_field_value, 'custom', 'r');
        break;
      case '_day':
        return \Drupal::service('date.formatter')->format($parent_field_value, 'custom', 'l');
        break;
      case '_week':
        return \Drupal::service('date.formatter')->format($parent_field_value, 'custom', 'W');
        break;
      case '_year_week':
        return \Drupal::service('date.formatter')->format($parent_field_value, 'custom', 'YW');
        break;
      case '_q':
        $month = \Drupal::service('date.formatter')->format($parent_field_value, 'custom', 'n');
        $yearQuarter = ceil($month / 3);
        return $yearQuarter;
        break;
      case '_year_q':
        $month = \Drupal::service('date.formatter')->format($parent_field_value, 'custom', 'n');
        $yearQuarter = ceil($month / 3);
        return \Drupal::service('date.formatter')->format($parent_field_value, 'custom', 'Y') . "" . $yearQuarter;
        break;
      default:
        return "0";
    }
  }

  private function drop_table($entity_type)
  {
    $record_keep_table = 'dw_entity_' . $entity_type;
    \Drupal::database()->schema()->dropTable($record_keep_table);
  }

  private function bulk_insert($entity_type, $data)
  {
    $table = 'dw_entity_' . $entity_type;
    $fields = $this->generate_schema($data, true);
    $query = \Drupal::database()->insert($table)->fields($fields);
    foreach ($data as $record) {
      $cols = (array)$record;
      $col_names = array_keys($cols);
      $row = [];
      foreach ($fields as $field) {
        if (in_array($field, $col_names)) {
          $row[$field] = trim($cols[$field]);
        } else {
          $row[$field] = $this->dynamic_date_values($field, $cols);
        }
      }
      $query->values($row);

    }
    if ($query->execute() !== NULL) {
      return true;
    }
    return false;
  }

  public function get_max_id($entity_type)
  {
    $table = 'dw_entity_' . $entity_type;
    $database = \Drupal::database();
    $query = $database->query("SELECT MAX(id) as max_id FROM {$table}");
    $result = $query->fetchAll();
    return $result[0]->max_id;
  }

  public function get_record_count($entity_type)
  {
    $table = 'dw_entity_' . $entity_type;
    if (\Drupal::database()->schema()->tableExists($table)) {
      $result = \Drupal::database()->select($table)
        ->countQuery()
        ->execute()->fetchField();
      return $result;
    }
    return 0;
  }

  public function bi_dump()
  {
    $data = $this->get_all_data('call');
    $response = json_encode($data);
    $json = new Response($response);
    return $json;
  }

  private function get_all_data($entity_type)
  {
    $table = 'dw_entity_' . $entity_type;
    $query = \Drupal::database()->query("SELECT * FROM {$table}");
    $result = [];
    if ($query) {
      while ($row = $query->fetchAssoc()) {
        $result[] = $row;
      }
      return $result;
    }
  }

  public function get_entity_max_id($entity_type){
    $ids = \Drupal::entityQuery($entity_type)
      ->sort('id', 'DESC')
      ->range(0,1)
      ->execute();
    return array_keys($ids)[0];
  }

  public function get_entity_count($entity_type){
    $ids = \Drupal::entityQuery($entity_type)
      ->execute();
    return count($ids);
  }

}