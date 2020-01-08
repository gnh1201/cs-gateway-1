<?php
loadHelper("json.format");
loadHelper("string.utils");

$data = array(
    "success" => false,
    "data" => false
);

$uuid = get_requested_value("uuid");
if(empty($uuid)) {
    set_error("uuid is required");
}

$bind = array(
    "uuid" => $uuid
);
$sql = get_bind_to_sql_select("autoget_devices", $bind);
$rows = exec_db_fetch_all($sql, $bind);

// modify values
for($i = 0; $i < count($rows); $i++) {
    $osnames = get_tokenized_text(strtolower($rows[$i]['os']), array(" ", "(", ")"));
    $arr_ip = explode(",", $rows[$i]['ip']);
    $arr_mac = explode(",", $rows[$i]['mac']);
    if(in_array("windows", $osnames)) {
        $rows[$i]['os'] = "Windows";
    } elseif(in_array("linux", $osnames)) {
        $rows[$i]['os'] = "Linux";
    }
    $rows[$i]['ip'] = current($arr_ip);
    $rows[$i]['mac'] = current($arr_mac);
}

$data['success'] = true;
$data['data'] = $rows;

header("Content-Type: application/json");
echo json_encode_ex($data);

