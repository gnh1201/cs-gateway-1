<?php
// Go Namhyeon <gnh1201@gmail.com>

loadHelper("string.utils");

$data = array(
    "success" => true,
    "data" => array()
);

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
        "adjust" => "-24 hours"
    ));
}

// get devices
$bind = array(
    "id" => $device_id
);

$sql = get_bind_to_sql_select("autoget_responses", false, array(
    "setwheres" => array(
        array("and", array("eq", "device_id", $device_id)),
        array("and", array("eq", "command_id", 9)),
        array("and", array("gte", "datetime", $start_dt)),
        array("and", array("lte", "datetime", $end_dt))
    )
));
$rows = exec_db_fetch_all($sql);

// _tbl1
$_tbl1 = exec_db_temp_create(array(
   "hotfix_id" => array("varchar", 255),
   "hotfix_name" => array("varchar", 255),
   "datetime" => array("datetime"),
));
foreach($rows as $row) {
    $lines = split_by_line($row['response']);
    foreach($lines as $line) {
        $terms = get_tokenized_text($line, array("  "));
        if(count($terms) > 3) {
            $datetime = "";
            $dtx_a = explode("/", trim($terms[2]));
            $dtx_b = explode("-", trim($terms[2]));
            if(count($dtx_a) == 3) {
                $datetime = sprintf("%02d-%02d-%02d 01:00:00", $dtx_a[2], $dtx_a[0], $dtx_a[1]);
            } elseif(count($dtx_b) == 3) {
                $datetime = sprintf("%02d-%02d-%02d 01:00:00", $dtx_b[0], $dtx_b[1], $dtx_b[2]);
            }

            $bind = array(
                "hotfix_id" => trim($terms[1]),
                "hotfix_name" => trim($terms[0]),
                "datetime" => $datetime
            );
            $sql = get_bind_to_sql_insert($_tbl1, $bind);
            exec_db_query($sql, $bind);
        }
    }
}

// _tbl2
$sql = "select * from $_tbl1 group by hotfix_id";
$rows = exec_db_fetch_all($sql);

header("Content-Type: application/json");
echo json_encode(array(
    "data" => $rows
));

exec_db_temp_end($_tbl1);

