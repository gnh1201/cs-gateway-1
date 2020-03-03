<?php
loadHelper("string.utils");
loadHelper("zabbix.api");

$end_dt = get_requested_value("end_dt");
$start_dt = get_requested_value("start_dt");
$adjust = get_requested_value("adjust");
$device_id = get_requested_value("device_id");
$mode = get_requested_value("mode");

$data = array(
    "success" => false
);

if(empty($adjust)) {
    $adjust = "-3h";
}

if(empty($end_dt)) {
    $end_dt = get_current_datetime();
}

if(empty($start_dt)) {
    $start_dt = get_current_datetime(array(
        "now" => $end_dt,
        "adjust" => $adjust
    ));
}

if($mode == "background") {
    // get total of memory
    $_total = 0;
    $bind = array(
        "device_id" => $device_id
    );
    $sql = get_bind_to_sql_select("autoget_data_memtotal.zabbix", $bind);
    $rows = exec_db_fetch_all($sql, $bind);
    foreach($rows as $row) {
        $_total += get_int($row['total']);
    }

    /*
    // if 0(zero) total, set average total of all computers
    if(!($_total > 0)) {
        $sql = "select round(avg(total), 0) as total from autoget_data_memtotal";
        $rows = exec_db_fetch_all($sql);
        foreach($rows as $row) {
            $_total += get_int($row['total']);
        }
    }

    // get memory usage by process (from tasklist)
    $bind = array(
        "command_id" => 1,
        "device_id" => $device_id
    );
    $sql = get_bind_to_sql_select("autoget_sheets", $bind, array(
        "setwheres" => array(
            array("and", array("gt", "pos_y", 3)),
            array("and", array("in", "pos_x", array(1, 4))),
            array("and", array("gte", "datetime", $start_dt)),
            array("and", array("lte", "datetime", $end_dt))
        )
    ));
    $_tbl2 = exec_db_temp_start($sql, $bind);

    $sql = "
    select
        group_concat(if(pos_x = 1, b.term, null)) as name,
        max(if(pos_x = 4, replace(b.term, ',', ''), null)) as _value, 
        round((max(if(pos_x = 4, replace(b.term, ',', ''), null)) / {$_total} ) * 100, 5) as value,
        a.datetime as datetime
    from $_tbl2 a left join autoget_terms b on a.term_id = b.id
    group by a.pos_y, a.datetime
    ";
    $_tbl3 = exec_db_temp_start($sql);

    $sql = "select sum(value) as value, datetime from $_tbl3 group by datetime";
    $_tbl4 = exec_db_temp_start($sql);

    $sql = "select max(value) as `load`, {$_total} as `total`, floor(unix_timestamp(datetime) / (5 * 60)) as `timekey`, max(datetime) as basetime from $_tbl4 group by timekey";
    $rows = exec_db_fetch_all($sql);
    */

    $bind = array(
        "device_id" => $device_id,
        "start_dt" => $start_dt,
        "end_dt" => $end_dt
    );
    $sql = "
        select sum(`value`) as `load`, max(`basetime`) as `basetime`
        from autoget_data_memtime
        where device_id = :device_id and basetime >= :start_dt and basetime <= :end_dt
        group by basetime
    ";
    $rows = exec_db_fetch_all($sql, $bind);

    // create table
    $tablename = exec_db_table_create(array(
        "device_id" => array("int", 11),
        "load" => array("float", "5,2"),
        "total" => array("int", 45),
        "basetime" => array("datetime")
    ), "autoget_data_mem", array(
        "setindex" => array(
            "index_1" => array("device_id", "basetime")
        )
    ));

    // insert selected rows
    $bulkid = exec_db_bulk_start();
    foreach($rows as $row) {
        $bind = array(
            "device_id" => $device_id,
            "load" => $row['load'],
            "total" => $_total,
            "basetime" => $row['basetime']
        );
        //$sql = get_bind_to_sql_insert($tablename, $bind);
        //exec_db_query($sql, $bind);
        exec_db_bulk_push($bulkid, $bind);
    }
    exec_db_bulk_end($bulkid, $tablename, array("device_id", "load", "total", "basetime"));

    $data['success'] = true;
} elseif($mode == "background.zabbix") {
    zabbix_authenticate();
    
    $hostips = array();
    
    $bind = array(
        "id" => $device_id
    );
    $sql = get_bind_to_sql_select("autoget_devices", $bind);
    $device = exec_db_fetch($sql, $bind);

    // get total of memory
    $_total = 0;
    $bind = array(
        "device_id" => $device_id
    );
    $sql = get_bind_to_sql_select("autoget_data_memtotal.zabbix", $bind);
    $rows = exec_db_fetch_all($sql, $bind);
    foreach($rows as $row) {
        $_total += get_int($row['total']);
    }

    // get memory data from zabbix
    $records = array();
    if(!array_key_empty("zabbix_hostid", $device)) {
        $items = zabbix_get_items($device['zabbix_hostid']);
        foreach($items as $item) {
            if($item->name == "Memory Usage" && $item->status == "0") {
                $_records = zabbix_get_records($item->itemid, $end_dt, $adjust);
                $records = array_merge($records, $_records);
            }
        }
    }

    $tablename = exec_db_temp_create(array(
        "itemid" => array("int", 11),
        "clock" => array("int", 11),
        "value" => array("float", "5,2")
    ));

    $bulkid = exec_db_bulk_start();
    foreach($records as $record) {
        $bind = array(
            "itemid" => $record->itemid,
            "clock" => $record->clock,
            "value" => $record->value
        );
        //$sql = get_bind_to_sql_insert($tablename, $bind);
        //exec_db_query($sql, $bind);
        exec_db_bulk_push($bulkid, $bind);
    }
    exec_db_bulk_end($bulkid, $tablename, array("itemid", "clock", "value"));

    $sql = "select itemid, avg(value) as value, floor(clock / (5 * 60)) as timekey, max(from_unixtime(clock)) as basetime from $tablename group by itemid, timekey";
    $rows = exec_db_fetch_all($sql);

    // create table
    $tablename = exec_db_table_create(array(
        "device_id" => array("int", 11),
        "load" => array("float", "5,2"),
        "total" => array("int", 45),
        "basetime" => array("datetime")
    ), "autoget_data_mem", array(
        "suffix" => ".zabbix",
        "setindex" => array(
            "index_1" => array("device_id", "basetime")
        )
    ));

    // insert selected rows
    $bulkid = exec_db_bulk_start();
    foreach($rows as $row) {
        $bind = array(
            "device_id" => $device_id,
            "load" => $row['value'],
            "total" => $_total,
            "basetime" => $row['basetime']
        );
        //$sql = get_bind_to_sql_insert($tablename, $bind);
        //exec_db_query($sql, $bind);
        exec_db_bulk_push($bulkid, $bind);
    }
    exec_db_bulk_end($bulkid, $tablename, array("device_id", "load", "total", "basetime"));

    $data['success'] = true;
} else {
    $bind = array(
        "device_id" => $device_id,
        "start_dt" => $start_dt,
        "end_dt" => $end_dt
    );
    $sql = "
        select avg(`load`) as `load`, avg(`total`) as `total`, max(`basetime`) as `basetime`, floor(unix_timestamp(`basetime`) / (5 * 60)) as `timekey`
        from `autoget_data_mem`
        where device_id = :device_id and basetime >= :start_dt and basetime <= :end_dt
        group by timekey
    ";
    $rows = exec_db_fetch_all($sql, $bind);

    $data['success'] = true;
    $data['data'] = $rows;
}

header("Content-Type: application/json");
echo json_encode($data);
