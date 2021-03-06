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
    "platform" => array("varchar", 255),
    "name" => array("varchar", 255),
    "version" => array("varchar", 255),
    "datetime" => array("datetime")
), "autoget_data_software", array(
    "setindex" => array(
        "index_1" => array("device_id")
    )
));

// get device information
$bind = array(
    "id" => $device_id
);
$sql = get_bind_to_sql_select("autoget_devices", $bind);
$device = exec_db_fetch($sql, $bind);

if($mode == "background") {
    $_tbl1 = false;
    if($device['platform'] == "windows") {
        $bind = array(
            "device_id" => $device_id,
            "command_id" => 57
        );
        $sql = get_bind_to_sql_select("autoget_sheets", $bind, array(
            "setwheres" => array(
                array("and", array("lte", "datetime", $end_dt)),
                array("and", array("gte", "datetime", $start_dt))
            )
        ));
        $_tbl1 = exec_db_temp_start($sql, $bind);
        
        $sql = "
            select
                group_concat(if(a.pos_x = 1, a.term, null)) as name,
                group_concat(if(a.pos_x >= 3, a.term, null) order by a.pos_x asc separator ' ') as value
            from $_tbl1 a
            group by pos_y, datetime
            order by datetime desc, pos_y asc
        ";
        $rows = exec_db_fetch_all($sql, $bind);
        
        $lines = array();
        $words = false;
        foreach($rows as $row) {
            if($row['name'] == "DisplayName") {
                if($words !== false) $lines[] = $words;
                $words = array("name" => "", "datetime" => "");
            }

            switch($row['name']) {
                case "DisplayName":
                    $words['name'] = $row['value'];
                    break;
                case "InstallDate":
                    $words['datetime'] = sprintf("%s-%s-%s 00:00:00", substr($row['value'], 0, 4), substr($row['value'], 4, 2), substr($row['value'], 6, 2));
                    break;
            }
        }

        foreach($lines as $row) {
            $_bind = array(
                "device_id" => $device_id,
                "platform" => "windows",
                "name" => $row['name'],
                "version" => "Not supported",
                "datetime" => $row['datetime']
            );
            $_sql = get_bind_to_sql_insert($tablename, $_bind, array(
                "setkeys" => array("device_id", "name", "platform")
            ));
            exec_db_query($_sql, $_bind);
        }
    } elseif($device['platform'] == "linux") {
        $bind = array(
            "device_id" => $device_id,
            "command_id" => 5
        );
        $sql = get_bind_to_sql_select("autoget_sheets", $bind, array(
            "setwheres" => array(
                array("and", array("lte", "datetime", $end_dt)),
                array("and", array("gte", "datetime", $start_dt))
            )
        ));
        $_tbl1 = exec_db_temp_start($sql, $bind);

        $sql = "
            select
                group_concat(if(a.pos_x = 3, a.term, null)) as name,
                group_concat(if(a.pos_x = 2, a.term, null)) as version,
                from_unixtime(group_concat(if(a.pos_x = 1, a.term, null))) as datetime
            from $_tbl1 a
            group by pos_y, datetime
        ";
        $rows = exec_db_fetch_all($sql);
        foreach($rows as $row) {
            $_bind = array(
                "device_id" => $device_id,
                "platform" => "linux",
                "name" => $row['name'],
                "version" => $row['version'],
                "datetime" => $row['datetime']
            );
            $_sql = get_bind_to_sql_insert($tablename, $_bind, array(
                "setkeys" => array("device_id", "name", "platform")
            ));
            exec_db_query($_sql, $_bind);
        }
    }
    $data['success'] = true;
} elseif($mode == "itsm.import") {
    $assetid = $device['itsm_assetid'];
    if(empty($assetid)) {
        $data['success'] = false;
        $data['message'] = "assetid not setted";
    } else {
        $bind = array(
            "device_id" => $device_id
        );
        $sql = get_bind_to_sql_select($tablename, $bind);
        $rows = exec_db_fetch_all($sql, $bind);
        foreach($rows as $row) {
            $categoryid = 4;
            if($device['platform'] == "windows") {
                $categoryid = 5;
            } elseif($device['platform'] == "linux") {
                $categoryid = 6;
            }

            $tag = sprintf("ITL-%x", crc32($row['name']));
            $result = itsm_add_data("licenses", array(
                "assetid" => $assetid,
                "clientid" => 1,
                "statusid" => 1,
                "categoryid" => $categoryid,
                "supplierid" => 3,
                "seats" => 1,
                "tag" => $tag,
                "name" => $row['name'],
                "serial" => "",
                "notes" => "",
                "qrvalue" => "",
            ), array(
                1 => $row['datetime']
            ));
        }
        $data['success'] = true;
    }
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
