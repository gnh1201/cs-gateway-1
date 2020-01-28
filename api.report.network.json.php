<?php
loadHelper("webpagetool");
loadHelper("zabbix.api");

$hostids = get_requested_value("hostids");
$hostips = get_requested_value("hostips");
$now_dt = get_requested_value("now_dt");
$adjust = get_requested_value("adjust");

if(empty($now_dt)) {
    $now_dt = get_current_datetime();
}

if(empty($adjust)) {
    $adjust = "-24h";
}

$data = array(
    "success" => false
);

zabbix_authenticate();

$records = array();

$hosts = zabbix_get_hosts();
foreach($hosts as $host) {
    foreach($host->interfaces as $interface) {
        $_hostids = explode(",", $hostids);
        $_hostips = explode(",", $hostips);
        if(in_array($host->hostid, $hostids) || in_array($interface->ip, $_hostips)) {
            $items = zabbix_get_items($host->hostid);
            foreach($items as $item) {
                if(strpos($item->key_, "net.") !== false && $item->status == "0") {
                    $_records = zabbix_get_records($item->itemid, $now_dt, $adjust);
                    $records = array_merge($records, $_records);
                }
            }
        }
    }
}

$tablename = exec_db_temp_create(array(
    "itemid" => array("int", 11),
    "clock" => array("int", 11),
    "value" => array("bigint", 20)
));

foreach($records as $record) {
    $bind = array(
        "itemid" => $record->itemid,
        "clock" => $record->clock,
        "value" => $record->value
    );
    $sql = get_bind_to_sql_insert($tablename, $bind);
    exec_db_query($sql, $bind);
}

$sql = "
    select count(a.itemid) as qty, sum(a.max_value) as max_value, sum(a.avg_value) as avg_value, a.timekey as timekey from (
        select itemid, (max(value) / pow(1024, 2) / 8) as max_value, (avg(value) / pow(1024, 2) / 8) as avg_value, floor(clock / (15 * 60)) as timekey from $tablename group by itemid, timekey
    ) a group by a.timekey order by timekey asc
";
$rows = exec_db_fetch_all($sql);

$_rows = array();
$_max_value = -1;
$_avg_value = -1;
foreach($rows as $row) {
    if($_max_value < 0) {
        $_max_value = $row['max_value'];
        $_avg_value = $row['avg_value'];
        $max_value = 0.0;
        $avg_value = 0.0;
    } else {
        $max_value = $row['max_value'] - $_max_value;
        $avg_value = $row['avg_value'] - $_avg_value;
        $_max_value = $max_value;
        $_avg_value = $avg_value;
    }

    $_rows[] = array(
        "qty" => $row['qty'],
        "max_value" => sprintf("%.02f", round($max_value, 2)),
        "avg_value" => sprintf("%.02f", round($avg_value, 2)),
        "timekey" => $row['timekey']
    );
}

$data['success'] = true;
$data['data'] = $_rows;

header("Content-Type: application/json");
echo json_encode($data);
