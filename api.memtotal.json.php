<?php
loadHelper("zabbix.api");

$device_id = get_requested_value("device_id");
$end_dt = get_requested_value("end_dt");
$start_dt = get_requested_value("start_dt");
$adjust = get_requested_value("adjust");
$mode = get_requested_value("mode");

$now_dt = get_current_datetime();

if(empty($device_id)) {
    set_error("device_id is required");
    show_errors();
}

if(empty($adjust)) {
    $adjust = "-10m";
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

if($mode == "background") {
    $bind = array(
        "device_id" => $device_id,
        "command_id" => 37
    );
    $sql = get_bind_to_sql_select("autoget_sheets", $bind, array(
        "setwheres" => array(
            array("and", array("eq", "pos_y", 2)),
            array("and", array("eq", "pos_x", 1)),
            array("and", array("lte", "datetime", $end_dt)),
            array("and", array("gte", "datetime", $start_dt))
        )
    ));
    $_tbl0 = exec_db_temp_start($sql, $bind);

    $sql = "select a.device_id as device_id, max(b.term) as total from $_tbl0 a, autoget_terms b where a.term_id = b.id group by a.device_id";
    $rows = exec_db_fetch_all($sql);

    $tablename = exec_db_table_create(array(
        "device_id" => array("int", 11),
        "total" => array("int", 45),
        "basetime" => array("datetime")
    ), "autoget_data_memtotal", array(
        "setunique" => array(
            "unique_1" => array("device_id")
        )
    ));
 
    foreach($rows as $row) {
        $total = get_int($row['total']);
        if($total > 0) {
            $bind = array(
                "device_id" => $row['device_id'],
                "total" => $total,
                "basetime" => $now_dt
            );
            $sql = get_bind_to_sql_insert($tablename, $bind, array(
                "setkeys" => array("device_id")
            ));
            exec_db_query($sql, $bind);
        }
    }

    $data['success'] = true;
} elseif($mode == "background.zabbix") {
    zabbix_authenticate();

    $hostips = array();

    $bind = array(
        "id" => $device_id
    );
    $sql = get_bind_to_sql_select("autoget_devices", $bind);
    $devices = exec_db_fetch_all($sql, $bind);
    foreach($devices as $device) {
        $_hostips = array_filter(explode(",", $device['net_ip']));
        $hostips = array_merge($hostips, $_hostips);
    }

    // get memory total data from zabbix
    $total = 0;
    $hosts = zabbix_get_hosts();
    foreach($hosts as $host) {
        foreach($host->interfaces as $interface) {
            if(in_array($interface->ip, $hostips)) {
                $items = zabbix_get_items($host->hostid);
                foreach($items as $item) {
                    if($item->name == "Total memory" && $item->status == "0") {
                        $total = get_int($item->lastvalue) / 1024;
                    }
                }
            }
        }
    }

    // create table of memory total
    $tablename = exec_db_table_create(array(
        "device_id" => array("int", 11),
        "total" => array("int", 45),
        "basetime" => array("datetime")
    ), "autoget_data_memtotal", array(
        "suffix" => ".zabbix",
        "setunique" => array(
            "unique_1" => array("device_id")
        )
    ));

    // update memory total
    if($total > 0) {
        $bind = array(
            "device_id" => $device_id,
            "total" => $total,
            "basetime" => $now_dt
        );
        $sql = get_bind_to_sql_insert($tablename, $bind, array(
            "setkeys" => array("device_id")
        ));
        exec_db_query($sql, $bind);
    }
} else {
    $bind = array(
        "device_id" => $device_id
    );
    $sql = get_bind_to_sql_select("autoget_data_memtotal", $bind, array(
        "setwheres" => array(
            array("and", array("lte", "basetime", $end_dt)),
            array("and", array("gte", "basetime", $start_dt))
        )
    ));
    $rows = exec_db_query($sql, $bind);
    
    $data['success'] = true;
    $data['data'] = $rows;
}

header("Content-Type: application/json");
echo json_encode($data);
