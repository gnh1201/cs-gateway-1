<?php
$device_id = get_requested_value("device_id");
$start_dt = get_requested_value("start_dt");
$end_dt = get_requested_value("end_dt");
$adjust = get_requested_value("adjust");
$type = get_requested_value("type");
$mode = get_requested_value("mode");

$now_dt = get_current_datetime();

if(empty($adjust)) {
    $adjust = "-1h";
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

if(empty($type)) {
    $type = "table";
}

$data = array(
    "success" => false
);

if($mode == "background") {
    $sql = get_bind_to_sql_select("autoget_sheets", false, array(
        "setwheres" => array(
            array("and", array("eq", "device_id", $device_id)),
            array("and", array("eq", "command_id", 8)),
            array("and", array("gt", "pos_y", 1)),
            array("and", array("gte", "datetime", $start_dt)),
            array("and", array("lte", "datetime", $end_dt))
        )
    ));
    $_tbl1 = exec_db_temp_start($sql, false);

    $_tbl2 = exec_db_temp_create(array(
        "name" => array("varchar", 255),
        "date" => array("date")
    ));

    $sql = "select group_concat(b.term) as terms from $_tbl1 a left join autoget_terms b on a.term_id = b.id group by a.pos_y, a.datetime";
    $rows = exec_db_fetch_all($sql);

    // end of life
    $EOLs = array("2014-03-02", "2019-03-02");
    foreach($rows as $row) {
        $terms = explode(",", $row['terms']);
        
        $name = "Unknown";
        $date = "0000-00-00";

        foreach($terms as $term) {
            $d1 = explode("/", trim($term));
            $d2 = explode("-", trim($term));
            if(max(array(count($d1), count($d2))) == 3) {
                if(count($d1) == 3) {
                    $date = sprintf("%02d-%02d-%02d", $d1[2], $d1[0], $d1[1]);
                } elseif(count($d1) == 3) {
                    $date = sprintf("%02d-%02d-%02d", $d1[0], $d1[1], $d1[2]);
                }
            } elseif(strlen($term) == 8 && ctype_digit($term)) {
                $date = sprintf("%02d-%02d-%02d", substr($term, 0, 4), substr($term, 4, 2), substr($term, 6, 2));
            } elseif(strlen($term) == 16) {
                // https://superuser.com/questions/1515297/what-is-the-time-format-like-01d15614cbaee92c-in-windows-hotfixes
                $date = date("Y-m-d", intval(substr(hex2dec($term), 0, -8)));
            } else {
                $c1 = substr($term, 0, 1);
                if(in_array($c1, array("K", "Q", "{"))) {
                    $name =  $term;
                }
                if($date == "0000-00-00" && $c1 == "Q") {
                    $date = $EOLs[0];
                }
            }
        }

        if($date == "0000-00-00") {
            if(in_array("XP", $terms)) {
                $date = $EOLs[0];
            } else {
                $date = $EOLs[1];
            }
        }

        $bind = array(
            "name" => $name,
            "date" => $date
        );
        $sql = get_bind_to_sql_insert($_tbl2, $bind);
        exec_db_query($sql, $bind);
    }

    $sql = "select name, date from $_tbl2 where name <> 'Unknown' group by name";
    $rows = exec_db_fetch_all($sql);

    // create table
    $tablename = exec_db_table_create(array(
        "device_id" => array("int", 11),
        "name" => array("varchar", 255),
        "date" => array("date")
    ), "autoget_data_hotfix", array(
        "setindex" => array(
            "index_1" => array("device_id", "date"),
        ),
        "setunique" => array(
            "unique_1" => array("device_id", "name")
        )
    ));

    // insert selected rows
    foreach($rows as $row) {
        $bind = array(
            "device_id" => $device_id,
            "name" => $row['name'],
            "date" => $row['date']
        );
        $sql = get_bind_to_sql_insert($tablename, $bind);
        exec_db_query($sql, $bind);
    }
    
    $data['success'] = true;
} else {
    $bind = array(
        "device_id" => $device_id
    );

    if($type == "table") {
        $sql = "select name, date from autoget_data_hotfix where device_id = :device_id order by date desc";
        $rows = exec_db_fetch_all($sql, $bind);
    } elseif($type == "line") {
        $sql = "select count(name) as cnt, max(date) as date, year(date) as dy, ceil(month(date) / 6) as dm from autoget_data_hotfix where device_id = :device_id group by dy, dm order by date desc";
        $rows = exec_db_fetch_all($sql, $bind);
    }

    $data['success'] = true;
    $data['data'] = $rows;
}

header("Content-Type: application/json");
echo json_encode($data);

