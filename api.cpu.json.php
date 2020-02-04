<?php
loadHelper("string.utils");
loadHelper("zabbix.api");

$device_id = get_requested_value("device_id");
$start_dt = get_requested_value("start_dt");
$end_dt = get_requested_value("end_dt");
$adjust = get_requested_value("adjust");
$mode = get_requested_value("mode");

if(empty($device_id)) {
    set_error("device_id is required");
}

if(empty($end_dt)) {
    $end_dt = get_current_datetime();
}

if(empty($adjust)) {
    $adjust = "-1h";
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
        "id" => $device_id
    );
    $sql = get_bind_to_sql_select("autoget_devices", $bind);
    $device = exec_db_fetch($sql, $bind);
 
    // get number of cores
    $_core = 0;
    $bind = array(
        "device_id" => $device_id
    );
    $sql = get_bind_to_sql_select("autoget_data_cpucore", $bind);
    $rows = exec_db_fetch_all($sql, $bind);
    foreach($rows as $row) {
        $_core += get_int($row['core']);
    }

    /*
    // if 0(zero) core, set average cores of all computers
    if(!($_core > 0)) {
        $sql = "select round(avg(core), 0) as core from autoget_data_cpucore";
        $rows = exec_db_fetch_all($sql);
        foreach($rows as $row) {
            $_core += get_int($row['core']);
        }
    }

    // get cpu usage
    $sql = get_bind_to_sql_select("autoget_sheets", false, array(
        "setwheres" => array(
            array("and", array("eq", "device_id", $device_id)),
            array("and", array("in", "pos_y", array(2, 3))),
            array("and", array("eq", "command_id", 47)),
            array("and", array("lte", "datetime", $end_dt)),
            array("and", array("gte", "datetime", $start_dt))
        )
    ));
    $_tbl1 = exec_db_temp_start($sql, false);
    
    $sql = "
    select a.pos_y as pos_y, if(a.pos_y = 2, ((a.pos_x + 1) / 6), (a.pos_x - 2)) as pos_x, b.term as term, a.datetime as datetime
        from $_tbl1 a left join autoget_terms b on a.term_id = b.id
            where (pos_y = 2 and mod(pos_x + 1, 6) = 0) or (pos_y = 3 and pos_x - 2 > 0)
    ";
    $_tbl2 = exec_db_temp_start($sql, false);
    
    $sql = "select group_concat(if(pos_y = 2, term, null)) as name, group_concat(if(pos_y = 3, term, null)) as value, datetime from $_tbl2 group by pos_x, datetime";
    $_tbl3 = exec_db_temp_start($sql, false);
    
    $delimiters = array(" ", "(", ")");
    $stopwords = array("Idle", "_Total", "typepref");
    $stopwords = array_merge(get_tokenized_text($device['computer_name'], $delimiters), $stopwords);
    $sql = sprintf("select sum(value) as value, datetime from $_tbl3 where name not in ('%s') group by datetime", implode("', '", $stopwords));
    $_tbl4 = exec_db_temp_start($sql, false);

    $sql = "select (max(value) / {$_core}) as `load`, {$_core} as `core`, floor(unix_timestamp(datetime) / (5 * 60)) as `timekey`, max(datetime) as `basetime` from $_tbl4 group by timekey";
    $rows = exec_db_fetch_all($sql);
    */

    // get cputime data from internal API
    $bind = array(
        "device_id" => $device_id,
        "start_dt" => $start_dt,
        "end_dt" => $end_dt
    );
    $sql = "
        select sum(`value`) as `load`, max('core') as `core`, max(`basetime`) as `basetime`
        from autoget_data_cputime
        where device_id = :device_id and basetime >= :start_dt and basetime <= :end_dt group by basetime
    ";
    $rows = exec_db_fetch_all($sql, $bind);

    // create table
    $tablename = exec_db_table_create(array(
        "device_id" => array("int", 11),
        "load" => array("float", "5,2"),
        "core" => array("int", 3),
        "basetime" => array("datetime")
    ), "autoget_data_cpu", array(
        "setindex" => array(
            "index_1" => array("device_id", "basetime")
        )
    ));

    // insert selected rows
    foreach($rows as $row) {
        $bind = array(
            "device_id" => $device_id,
            "load" => $row['load'],
            "core" => $_core,
            "basetime" => $row['basetime']
        );
        $sql = get_bind_to_sql_insert($tablename, $bind);
        exec_db_query($sql, $bind);
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

    // get number of cores
    $_core = 0;
    $bind = array(
        "device_id" => $device_id
    );
    $sql = get_bind_to_sql_select("autoget_data_cpucore", $bind);
    $rows = exec_db_fetch_all($sql, $bind);
    foreach($rows as $row) {
        $_core += get_int($row['core']);
    }

    // get cpu data from zabbix
    $records = array();
    $hosts = zabbix_get_hosts();
    foreach($hosts as $host) {
        foreach($host->interfaces as $interface) {
            if(in_array($interface->ip, $hostips)) {
                $items = zabbix_get_items($host->hostid);
                foreach($items as $item) {
                    if($item->name == "CPU Usage" && $item->status == "0") {
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
        "value" => array("float", "5,2")
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

    $sql = "select itemid, (100 - avg(value)) as value, floor(clock / (5 * 60)) as timekey, max(from_unixtime(clock)) as basetime from $tablename group by itemid, timekey";
    $rows = exec_db_fetch_all($sql);

    // create table
    $tablename = exec_db_table_create(array(
        "device_id" => array("int", 11),
        "load" => array("float", "5,2"),
        "core" => array("int", 3),
        "basetime" => array("datetime")
    ), "autoget_data_cpu", array(
        "suffix" => ".zabbix",
        "setindex" => array(
            "index_1" => array("device_id", "basetime")
        )
    ));

    // insert selected rows
    foreach($rows as $row) {
        $bind = array(
            "device_id" => $device_id,
            "load" => $row['value'],
            "core" => $_core,
            "basetime" => $row['basetime']
        );
        $sql = get_bind_to_sql_insert($tablename, $bind);
        exec_db_query($sql, $bind);
    }

    $data['success'] = true;
} else {
    $bind = array(
        "device_id" => $device_id,
        "start_dt" => $start_dt,
        "end_dt" => $end_dt
    );
    $sql = "
        select ifnull(avg(`load`), 0.0) as `load`, max(`core`) as `core`, max(`basetime`) as `basetime`, floor(unix_timestamp(`basetime`) / (5 * 60)) as `timekey`
            from autoget_data_cpu
            where device_id = :device_id and basetime >= :start_dt and basetime <= :end_dt
            group by timekey
    ";
    $rows = exec_db_fetch_all($sql, $bind);

    $data['success'] = true;
    $data['data'] = $rows;
}

header("Content-Type: application/json");
echo json_encode($data);
