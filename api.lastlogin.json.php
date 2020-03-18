<?php
loadHelper("itsm.api");

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

$tablename = exec_db_table_create(array(
    "device_id" => array("int", 11),
    "username" => array("varchar", 255),
    "datetime" => array("datetime"),
), "autoget_data_lastlogin", array(
    "setindex" => array(
        "index_1" => array("device_id")
    ),
    "setunique" => array(
        "unique_1" => array("device_id", "username", "datetime")
    )
));

// get device information
$bind = array(
    "id" => $device_id
);
$sql = get_bind_to_sql_select("autoget_devices", $bind);
$device = exec_db_fetch($sql, $bind);

if($mode == "background") {
    if($device['platform'] == "windows") {
        $bind = false;
        $sql = get_bind_to_sql_select("autoget_sheets", $bind, array(
            "setwheres" => array(
                 array("and", array("eq", "device_id", $device_id)),
                 array("and", array("eq", "command_id", 56)),
                 array("and", array("in", "pos_x", array(1, 3))),
                 array("and", array("lte", "datetime", $end_dt)),
                 array("and", array("gte", "datetime", $start_dt))
            )
        ));
        $_tbl1 = exec_db_temp_start($sql, $bind);
        
        $sql = "
            select
                group_concat(if(a.pos_x = 1, a.term, null)) as rawtime,
                group_concat(if(a.pos_x = 3, a.term, null)) as username
            from $_tbl1 a group by a.pos_y, a.datetime
        ";
        $rows = exec_db_fetch_all($sql);
        foreach($rows as $row) {
            if(strpos($row['rawtime'], ".") !== false) {
                $year = substr($row['rawtime'], 0, 4);
                $month = substr($row['rawtime'], 4, 2);
                $day = substr($row['rawtime'], 6, 2);
                $hour = substr($row['rawtime'], 8, 2);
                $minute = substr($row['rawtime'], 10, 2);
                $second = substr($row['rawtime'], 12, 2);

                $bind = array(
                    "device_id" => $device_id,
                    "username" => $row['username'],
                    "datetime" => sprintf("%s-%s-%s %s:%s:%s", $year, $month, $day, $hour, $minute, $second)
                );
                $sql = get_bind_to_sql_insert($tablename, $bind);
                exec_db_query($sql, $bind);
            }
        }
    } elseif($device['platform'] == "linux") {
        $bind = false;
        $sql = get_bind_to_sql_select("autoget_sheets", $bind, array(
            "setwheres" => array(
                 array("and", array("eq", "device_id", $device_id)),
                 array("and", array("eq", "command_id", 54)),
                 array("and", array("in", "pos_x", array(1, 7, 5, 6, 8))),
                 array("and", array("lte", "datetime", $end_dt)),
                 array("and", array("gte", "datetime", $start_dt))
            )
        ));
        $_tbl1 = exec_db_temp_start($sql, $bind);

        $sql = "
            select
                group_concat(if(a.pos_x = 1, a.term, null)) as username,
                group_concat(if(a.pos_x = 8, a.term, null)) as year,
                month(str_to_date(group_concat(if(a.pos_x = 5, a.term, null)), '%b')) as month,
                group_concat(if(a.pos_x = 6, a.term, null)) as day,
                group_concat(if(a.pos_x = 7, a.term, null)) as time
            from $_tbl1 a group by a.pos_y, a.datetime
        ";
        $rows = exec_db_fetch_all($sql);
        foreach($rows as $row) {
            $bind = array(
                "device_id" => $device_id,
                "username" => $row['username'],
                "datetime" => sprintf("%s-%s-%s %s", $row['year'], $row['month'], $row['day'], $row['time'])
            );
            $sql = get_bind_to_sql_insert($tablename, $bind);
            exec_db_query($sql, $bind);
        }
    }

    $data['success'] = true;
} elseif($mode =="itsm.import") {
    $bind = array(
        "device_id" => $device_id
    );
    $sql = "select a.device_id as device_id, a.username as username, max(a.datetime) as last from $tablename a where a.device_id = :device_id group by username";
    $rows = exec_db_fetch_all($sql, $bind);
    
    $assetid = $device['itsm_assetid'];
    foreach($rows as $row) {
        if(!empty($assetid)) {
            foreach($rows as $row) {
                itsm_edit_data("credentials", array(
                    "assetid" => $assetid,
                    "username" => $row['username'],
                    "last" => $row['last']
                ), array(
                    1 => $row['last']
                ));
            }
        }
    }

    $data['success'] = true;
} else {
    $bind = array(
        "device_id" => $device_id
    );
    $sql = get_bind_to_sql_select($tablename, $bind);
    $rows = exec_db_fetch_all($sql, $bind);
    $data['data'] = $rows;
}

header("Content-Type: application/json");
echo json_encode($data);
