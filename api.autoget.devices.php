<?php
loadHelper("json.format");
loadHelper("string.utils");

$uuid = get_requested_value("uuid");

$_sql = get_bind_to_sql_select("autoget_devices");
$_rows = exec_db_fetch_all($_sql);

$rows = array();
foreach($_rows as $_row) {
    $osnames = get_tokenized_text(strtolower($_row['os']), array(" ", "(", ")"));
    if(in_array("windows", $osnames)) {
        $_row['os'] = "Windows";
    } elseif(in_array("linux", $osnames)) {
        $_row['os'] = "Linux";
    }

    $rows[] = $_row;
}

header("Content-Type: application/json");
echo json_encode_ex(array(
    "data" => $rows
));

