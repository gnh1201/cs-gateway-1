<?php
loadHelper("string.utils");
loadHelper("zabbix.api");

$device_id = get_requested_value("device_id");
$start_dt = get_requested_value("start_dt");
$end_dt = get_requested_value("end_dt");
$adjust = get_requested_value("adjust");
$mode = get_requested_value("mode");

$op = get_requested_value("op");
$debug = get_requested_value("debug");

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
    // get platform
    $platform = "";
    $bind = array(
        "id" => $device_id
    );
    $sql = get_bind_to_sql_select("autoget_devices", $bind);
    $rows = exec_db_fetch_all($sql, $bind);
    foreach($rows as $row) {
        $platform = $row['platform'];
    }

    // initalize rows
    $rows = array();

    // for windows
    if($platform == "windows") {
        $bind = array(
            "device_id" => $device_id,
            "command_id" => 40
        );
        $sql = get_bind_to_sql_select("autoget_sheets", $bind, array(
            "setwheres" => array(
                array("and", array("gt", "pos_y", "1")),
                array("and", array("lte", "datetime", $end_dt)),
                array("and", array("gte", "datetime", $start_dt))
            )
        ));
        $_tbl1 = exec_db_temp_start($sql, $bind);

        // step 1
        $sql = "
            select
                group_concat(if(a.pos_x = 1, b.term, null)) as name,
                ifnull(group_concat(if(a.pos_x = 4, a.term, null)), 0) as total,
                ifnull(group_concat(if(a.pos_x = 3, a.term, null)), 0) as available
            from
                $_tbl1 a
            group by
                a.pos_y, a.datetime
        ";
        $_tbl2 = exec_db_temp_start($sql, false);

        // step 2
        $sql = "select name, avg(total) as total, avg(available) as available from $_tbl2 group by name";
        $rows = exec_db_fetch_all($sql, false);
    }

    // for linux
    if($platform == "linux") {
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
                ifnull(group_concat(if(a.pos_x = 2, a.term, null)), 0) as total,
                ifnull(group_concat(if(a.pos_x = 4, a.term, null)), 0) as available
            from
                $_tbl1 a
            group by
                a.pos_y, a.datetime
        ";
        $_tbl2 = exec_db_temp_start($sql, false);

        // step 2
        $sql = "select name, (avg(total) * 1024) as total, avg(available) * 1024) as available from $_tbl2 group by name";
        $rows = exec_db_fetch_all($sql, false);
    }

    // create data table
    $tablename = exec_db_table_create(array(
        "device_id" => array("int", 11),
        "name" => array("varchar", 255),
        "total" => array("bigint", 20),
        "available" => array("bigint", 20),
        "used" => array("bigint", 20),
        "load" => array("float", "5,2"),
        "basetime" => array("datetime")
    ), "autoget_data_disk", array(
        "setindex" => array(
            "index_1" => array("device_id", "datetime")
        )
    ));
    
    // insert data
    $bulkid = exec_db_bulk_start();
    foreach($rows as $row) {
        $used = $row['total'] - $row['available'];
        $bind = array(
            "device_id" => $device_id,
            "name" => $row['name'],
            "total" => $row['total'],
            "available" => $row['available'],
            "used" => $used,
            "load" => ($used / $row['total']) * 100,
            "basetime" => $now_dt
        );
        //$sql = get_bind_to_sql_insert($tablename, $bind);
        //exec_db_query($sql, $bind);
        exec_db_bulk_push($bulkid, $bind);
    }
    exec_db_bulk_end($bulkid, $tablename, array("device_id", "name", "total", "available", "used", "load", "basetime"));
 
    $data['success'] = true;
} elseif($mode == "background.zabbix") {
    zabbix_authenticate();

    $hostips = array();
    
    $bind = array(
        "id" => $device_id
    );
    $sql = get_bind_to_sql_select("autoget_devices", $bind);
    $device = exec_db_fetch($sql, $bind);

    // get disk data from zabbix
    $itemnames = array();
    $records = array();
    if(!array_key_empty("zabbix_hostid", $device)) {
        $items = zabbix_get_items($device['zabbix_hostid']);
        foreach($items as $item) {
            $itemname = strtolower($item->name);
            if(strpos($itemname, "disk") !== false && $item->status == "0") {
                $record = new stdClass();
                $record->itemid = $item->itemid;
                $record->clock = $item->lastclock;
                $record->value = $item->lastvalue;
                $records[] = $record;
                $itemnames[$item->itemid] = $item->name;
            }
        }
    }
    
    $tablename = exec_db_temp_create(array(
        "itemid" => array("int", 11),
        "itemname" => array("varchar", 255),
        "clock" => array("int", 11),
        "value" => array("float", "20,2")
    ));

    $bulkid = exec_db_bulk_start();
    foreach($records as $record) {
        $bind = array(
            "itemid" => $record->itemid,
            "itemname" => strtolower($itemnames[$record->itemid]),
            "clock" => $record->clock,
            "value" => $record->value
        );
        //$sql = get_bind_to_sql_insert($tablename, $bind);
        //exec_db_query($sql, $bind);
        exec_db_bulk_push($bulkid, $bind);
    }
    exec_db_bulk_end($bulkid, $tablename, array("itemid", "itemname", "clock", "value"));

    $sql = "select itemid, itemname, avg(value) as value from $tablename group by itemid";
    $_tbl1 = exec_db_temp_start($sql);
    
    if(!empty($debug)) {
        $sql = "select * from $_tbl1";
        $rows = exec_db_fetch_all($sql);
        var_dump($rows);
        exit;
    }

    // create data table
    $tablename = exec_db_table_create(array(
        "device_id" => array("int", 11),
        "name" => array("varchar", 255),
        "total" => array("bigint", 20),
        "available" => array("bigint", 20),
        "used" => array("bigint", 20),
        "load" => array("float", "5,2"),
        "basetime" => array("datetime")
    ), "autoget_data_disk", array(
        "suffix" => ".zabbix",
        "setindex" => array(
            "index_1" => array("device_id", "datetime")
        )
    ));

    $sql = "select
        round(avg(if(itemname like 'total disk %', value, null))) as total,
        round(avg(if(itemname like 'free disk size %', value, null))) as pavailable,
        round(avg(if(itemname like 'used disk %' and not itemname like 'used disk space %', value, null))) as pused,
        substring_index(itemname, ' ', -1) as name,
        itemname
    from $_tbl1 group by name";
    $rows = exec_db_fetch_all($sql);

    // insert data
    $bulkid = exec_db_bulk_start();
    foreach($rows as $row) {
        $terms = get_tokenized_text($row['itemname']);
        if(in_array("used", $terms) || in_array("total", $terms) || in_array("free", $terms)) {
            $available = $row['total'] * ($row['pavailable'] / 100);
            $used = $row['total'] * ($row['pused'] / 100);
            $load = ($used / $row['total']) * 100;

            $bind = array(
                "device_id" => $device_id,
                "name" => $row['name'],
                "total" => $row['total'],
                "available" => $available,
                "used" => $used,
                "load" => $load,
                "basetime" => $now_dt
            );
            //$sql = get_bind_to_sql_insert($tablename, $bind);
            //exec_db_query($sql, $bind);
            exec_db_bulk_push($bulkid, $bind);
        }
    }
    exec_db_bulk_end($bulkid, $tablename, array("device_id", "name", "total", "available", "used", "load", "basetime"));

    $data['success'] = true;
} else {
    $bind = array(
        "device_id" => $device_id,
        "start_dt" => $start_dt,
        "end_dt" => $end_dt
    );
    
    // summary
    if($op == "sum") {
        $sql = "
        select sum(total) as total, sum(available) as available, ((sum(used) / sum(total)) * 100) as `load`, max(basetime) as basetime
            from `autoget_data_disk.zabbix`
            where device_id = :device_id and basetime >= :start_dt and basetime <= :end_dt
            group by device_id
        ";
    } else {
        $sql = "
       select name, avg(total) as total, avg(available) as available, max(basetime) as basetime
            from `autoget_data_disk.zabbix`
            where device_id = :device_id and basetime >= :start_dt and basetime <= :end_dt
            group by name
        ";
    }
    $rows = exec_db_fetch_all($sql, $bind);
    
    $data['success'] = true;
    $data['data'] = $rows;
}

header("Content-Type: application/json");
echo json_encode($data);
