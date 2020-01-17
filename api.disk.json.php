<?php
loadHelper("string.utils");

$device_id = get_requested_value("device_id");
$start_dt = get_requested_value("start_dt");
$end_dt = get_requested_value("end_dt");

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
        "adjust" => "-1h"
    ));
}

$data = array(
    "success" => false,
    "data" => false
);

$bind = array(
    "id" => $device_id
);
$sql = get_bind_to_sql_select("autoget_devices", $bind);
$device = exec_db_fetch($sql, $bind);
$osnames = get_tokenized_text(strtolower($device['os']), array(" ", "(", ")"));

// create temporary table
// for windows
if(in_array("windows", $osnames)) {
    $sql = get_bind_to_sql_select("autoget_sheets", false, array(
        "setwheres" => array(
            array("and", array("eq", "device_id", $device_id)),
            array("and", array("eq", "command_id", 40)),
            array("and", array("gt", "pos_y", "1")),
            array("and", array("lte", "datetime", $end_dt)),
            array("and", array("gte", "datetime", $start_dt))
        )
    ));
    $_tbl1 = exec_db_temp_start($sql, false);

    // step 1
    $sql = "
        select
            group_concat(if(a.pos_x = 1, b.term, null)) as name,
            ifnull(group_concat(if(a.pos_x = 4, b.term, null)), 0) as total,
            ifnull(group_concat(if(a.pos_x = 3, b.term, null)), 0) as available
        from
            $_tbl1 a
            left join autoget_terms b on a.term_id = b.id
        group by
            a.pos_y, a.datetime
    ";
    $_tbl2 = exec_db_temp_start($sql, false);

    // step 2
    $sql = "select name, avg(total) as total, avg(available) as available from $_tbl2 where name is not null group by name";
    $rows = exec_db_fetch_all($sql, false);
    $data['data'] = $rows;
    $data['success'] = true;
}

// for linux
if(in_array("linux", $osnames)) {
    $sql = get_bind_to_sql_select("autoget_sheets", false, array(
        "setwheres" => array(
            array("and", array("eq", "device_id", $device_id)),
            array("and", array("eq", "command_id", "39")),
            array("and", array("gt", "pos_y", "1")),
            array("and", array("lte", "datetime", $end_dt)),
            array("and", array("gte", "datetime", $start_dt))
        )
    ));
    $_tbl1 = exec_db_temp_start($sql, false);
    
    // step 1
    $sql = "
        select
            group_concat(if(a.pos_x = 1, b.term, null)) as name,
            ifnull(group_concat(if(a.pos_x = 2, b.term, null)), 0) as total,
            ifnull(group_concat(if(a.pos_x = 4, b.term, null)), 0) as available
        from
            $_tbl1 a
            left join autoget_terms b on a.term_id = b.id
        group by
            a.col_y, a.datetime
    ";
    $_tbl2 = exec_db_temp_start($sql, false);

    // step 2
    $sql = "select name, (avg(total) * 1024) as total, (avg(available) * 1024) as available from $_tbl2 where name is not null group by name";
    $rows = exec_db_fetch_all($sql, false);
    $data['data'] = $rows;
    $data['success'] = true;
}

header("Content-Type: application/json");
echo json_encode($data);

