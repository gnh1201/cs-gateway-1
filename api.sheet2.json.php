<?php
loadHelper("string.utils");
loadHelper("exectool");

$response_id = get_requested_value("response_id");
$device_id = get_requested_value("device_id");
$end_dt = get_requested_value("end_dt");
$start_dt = get_requested_value("start_dt");
$adjust = get_requested_value("adjust");

write_debug_log("PID: $mypid, response_id: $response_id", "api.sheets2.json");

$now_dt = get_current_datetime();

if(empty($response_id)) {
    set_error("response_id is required");
    show_errors();
}

if(empty($adjust)) {
    $adjust = "-1m";
}

if(empty($end_dt)) {
    $end_dt = $now_dt;
}

if(empty($start_dt)) {
    $start_dt = get_current_datetime(array(
        "now" => $end_dt,
        "adjust" => $adjust
    ));
}

$data = array(
    "success" => false
);

$bind = array(
    "id" => $response_id
);
$sql = get_bind_to_sql_select("autoget_responses", $bind);
$responses = exec_db_fetch_all($sql, $bind);

// set scheme
$scheme = array(
    "response_id" => array("bigint", 20),
    "command_id" => array("int", 11),
    "device_id" => array("int", 11),
    "pos_y" => array("int", 5),
    "pos_x" => array("int", 5),
    "term" => array("varchar", 255),
    "datetime" => array("datetime")
);

// set tablename
$tablename = exec_db_table_create($scheme, "autoget_sheets", array(
    "suffix" => sprintf(".%s%s", date("YmdH"), sprintf("%02d", floor(date("i") / 10) * 10)),
    "setindex" => array(
        "index_1" => array("command_id", "device_id"),
        "index_2" => array("pos_y", "datetime"),
        "index_3" => array("pos_x", "datetime")
    )
));

// set `is_read` to 1
foreach($responses as $response) {
    $bind = array(
        "id" => $response['id'],
        "is_read" => 1
    );
    $sql = get_bind_to_sql_update("autoget_responses", $bind, array(
        "setkeys" => array("id")
    ));
    exec_db_query($sql, $bind);
}

// processing responses
foreach($responses as $response) {
    $result = exec_command(sprintf("/home/gw/public_html/bin/makesheet %s %s", $response['id'], $tablename));
    write_debug_log(sprintf("affected %s rows, response_id: %s, command_id: %s, device_id: %s", trim($result), $response['id'], $response['command_id'], $response['device_id']), "api.sheet2.json");
}

$data['success'] = true;

header("Content-Type: application/json");
echo json_encode($data);
