<?php
loadHelper("string,utils");

$start_dt = get_requested_value("start_dt");
$end_dt = get_requested_value("end_dt");

$device_id = get_requested_value("device_id");

if(empty($device_id)) {
    set_error("device_id is required");
    show_errors();
}

if(empty($end_dt)) {
    $end_dt = get_current_datetime();
}

if(empty($start_dt)) {
    $start_dt = get_current_datetime(array(
        "now" => $end_dt,
        "adjust" => "-1 hour"
    ));
}

// get parsed data
$sql = get_bind_to_sql_select("autoget_sheets", false, array(
    "setwheres" => array(
        array("and", array("eq", "device_id", $device_id)),
        array("and", array("eq", "command_id", 30)),
        array("and", array("gt", "row_n", 1)),
        array("and", array("eq", "col_n", 1)),
        array("and", array("gte", "datetime", $start_dt)),
        array("and", array("lte", "datetime", $end_dt))
    )
));
$_tbl1 = exec_db_temp_start($sql, false);

$sql = "
    select
        ifnull(avg(if(a.col_n > 0, b.name, null)), 0) as `load`,
        (max(a.row_n) - 1) as `core`,
        floor(unix_timestamp(a.datetime) / (5 * 60)) as `timekey`,
        max(a.datetime) as `basetime`
    from
        $_tbl1 a, autoget_terms b
    where
        a.term_id = b.id
    group by
        timekey
";
$rows = exec_db_fetch_all($sql, false);

$data = array(
    "success" => true,
    "data" => $rows
);

header("Content-Type: application/json");
echo json_encode($data);

