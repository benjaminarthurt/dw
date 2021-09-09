<?php

//todo: Load settings dynamically instead of full system path
require('/var/www/mysite/web/sites/default/settings.php');

if($databases['default']['default']['driver'] == 'mysql') {
  $conn = new mysqli($databases['default']['default']['host'], $databases['default']['default']['username'], $databases['default']['default']['password'], $databases['default']['default']['database']);
  if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
  }
  $sql = "SELECT * FROM dw_entity_call;";
  $result = $conn->query($sql);
  if (!$result) die('Couldn\'t fetch records');
  $num_fields = mysqli_num_fields($result);
  $headers = array();
  while ($fieldinfo = mysqli_fetch_field($result)) {
    $headers[] = $fieldinfo->name;
  }
  $fp = fopen('php://output', 'w');
  if ($fp && $result) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="export.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');
    fputcsv($fp, $headers);
    while ($row = $result->fetch_array(MYSQLI_NUM)) {
      fputcsv($fp, array_values($row));
    }
    die;
  }
  $conn->close();
}
