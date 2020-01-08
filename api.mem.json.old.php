<?php
loadHelper("string.utils");

$device_id = get_requested_value("device_id");
$start_dt = get_requested_value("start_dt");
$end_dt = get_requested_value("end_dt");

$data = array(
    "success" => false,
    "data" => false
);

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

// get device information
$bind = array(
    "id" => $device_id
);
$sql = get_bind_to_sql_select("autoget_devices", $bind);
$device = exec_db_fetch($sql, $bind);
$osnames = get_tokenized_text(strtolower($device['os']), array(" ", "(", ")"));

// if windows
if(in_array("windows", $osnames)) {
    $sql = get_bind_to_sql_select("autoget_sheets", false, array(
        "setwheres" => array(
            array("and", array("eq", "device_id", $device_id)),
            //array("and", array("eq", "command_id", "38")),
            array("and", array("in", "command_id", array(37, 38))),
            array("and", array("eq", "row_n", "2")),
            array("and", array("lte", "datetime", $end_dt)),
            array("and", array("gte", "datetime", $start_dt))
        )
    ));
    $_tbl0 = exec_db_temp_start($sql, false);

    $sql = "
select
    avg(if(a.command_id = 38, b.name, null)) as lastvalue,
    avg(if(a.command_id = 37, b.name, null)) as totalvalue,
    floor(unix_timestamp(a.datetime) / (5 * 60)) as `timekey`,
    max(a.datetime) as basetime
from
    $_tbl0 a, autoget_terms b
where
    term_id = b.id
group by
    timekey
";
    $rows = exec_db_fetch_all($sql, false);
    $data['data'] = $rows;
    $data['success'] = true;
}

/*
// if linux
if(in_array("linux", $osnames)) {
    $sql = get_bind_to_sql_select("autoget_sheets", false, array(
        "setwheres" => array(
            array("and", array("eq", "device_id", $device_id")),
            array("and", array("eq", "command_id", "36")),
            array("and", array("eq", "col_n", "3")),
            array("and', array("lte", "datetime", $end_dt)),
            array("and", array("gte", "datetime", $start_dt))
        )
    ));
    $_tbl0  = exec_db_temp_start($sql, false);

    $sql = "
select
    avg(b.name) as lastvalue,
    floor(unix_timestamp(a.datetime) / (5 * 60)) as `timekey`,
    max(a.datetime) as basetime
from
    $_tbl0 a, autoget_terms b
where
    term_id = b.id
group by
    timekey
";

    $rows = exec_db_fetch_all($sql, false);
    $data['data'] = $rows;
    $data['success'] = true;
}
*/

header("Content-Type: application/json");
echo json_encode($data);

exec_db_temp_end($_tbl0);

