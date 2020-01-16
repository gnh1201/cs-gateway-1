<?php
$device_id = get_requested_value("device_id");
$end_dt = get_requested_value("end_dt");
$start_dt = get_requested_value("start_dt");
$adjust = get_requested_value("adjust");

if(empty($device_id)) {
    set_error("device_id is required");
    show_errors();
}

if(empty($adjust)) {
    $adjust = "-10m";
}

if(empty($end_dt)) {
    $end_dt = get_current_datetime();
}

if(empty($start_dt)) {
    $start_dt = get_current_datetime(array(
        "now" => $end_dt,
        "adjust" => $adjust
    ));
}

$bind = array(
    "device_id" => $device_id,
    "command_id" => 50
);
$sql = get_bind_to_sql_select("autoget_sheets", $bind, array(
    "setwheres" => array(
        array("and", array("lte", "datetime", $end_dt)),
        array("and", array("gte", "datetime", $start_dt))
    )
));
$_tbl0 = exec_db_temp_start($sql, $bind);

$sql = "select device_id, max(b.term) as core from $_tbl0 a, autoget_terms b where a.term_id = b.id group by device_id";
$rows = exec_db_fetch_all($sql);

var_dump($rows);
