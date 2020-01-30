<?php
loadHelper("webpagetool");
loadHelper("zabbix.api");

$device_id = get_requested_value("device_id");
$mode = get_requested_value("mode");

$hostids = get_requested_value("hostids");
$hostips = get_requested_value("hostips");

$end_dt = get_requested_value("end_dt");
$start_dt = get_requested_value("start_dt");
$adjust = get_requested_value("adjust");

if(empty($adjust)) {
    $adjust = "-20m";
}

if(empty($end_dt)) {
    $end_dt = get_current_datetime();
}

if(empty($start_dt)) {
    $start_dt = get_current_datetime(array(
        "end_dt" => $end_dt,
        "adjust" => $adjust
    ));
}

$data = array(
    "success" => false
);

// get device information
if(!empty($device_id)) {
    $bind = array(
        "id" => $device_id
    );
    $sql = get_bind_to_sql_select("autoget_devices", $bind);
    $rows = exec_db_fetch_all($sql, $bind);
    foreach($rows as $row) {
        $hostips = $row['net_ip'];
    }
}

// background mode
if($mode == "background") {
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
                        $_records = zabbix_get_records($item->itemid, $end_dt, $adjust);
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
        select count(a.itemid) as qty, sum(a.value) as value, a.timekey as timekey, a.basetime as basetime from (
            select itemid, (avg(value) / pow(1024, 2) / 8) as value, floor(clock / (5 * 60)) as timekey, max(from_unixtime(clock)) as basetime from $tablename group by itemid, timekey
        ) a group by a.timekey order by timekey asc
    ";
    $rows = exec_db_fetch_all($sql);

    $tablename = exec_db_table_create(array(
        "device_id" => array("int", 11),
        "qty" => array("int", 2),
        "value" => array("float", "20,2"),
        "basetime" => array("datetime"),
    ), "autoget_data_network", array(
        "setindex" => array(
            "index_1" => array("device_id", "basetime")
        )
    ));

    // calculate delta
    $_rows = array();
    $_value = -1;
    foreach($rows as $row) {
        if($_value < 0) {
            $_value = $row['value'];
        } else {
            $value = $row['value'] - $_value;
            
            if($value < 0) break;
            
            $_value = $row['value'];

            $bind = array(
                "device_id" => $device_id,
                "qty" => $row['qty'],
                "value" => $value,
                "basetime" => $row['basetime']
            );
            $sql = get_bind_to_sql_insert($tablename, $bind);
            exec_db_query($sql, $bind);
        }
    }

    $data['success'] = true;
} else {
    $bind = array(
        "device_id" => $device_id,
        "start_dt" => $start_dt,
        "end_dt" => $end_dt
    );
    $sql = "
        select avg(`value`) as `value`, max(`qty`) as `qty`, max(`basetime`) as `basetime`, floor(unix_timestamp(`basetime`) / (5 * 60)) as `timekey`
            from autoget_data_network
            where device_id = :device_id and basetime >= :start_dt and basetime <= :end_dt
            group by timekey
    ";
    $rows = exec_db_fetch_all($sql, $bind);

    $data['success'] = true;
    $data['data'] = $rows;
}

header("Content-Type: application/json");
echo json_encode($data);
