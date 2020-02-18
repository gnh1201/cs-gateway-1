<?php
$data = array(
    "success" => false
);

// get now
$now_dt = get_current_datetime();

// get devices
$bind = false;
$sql = get_bind_to_sql_select("autoget_devices", $bind);
$devices = exec_db_fetch_all($sql, $bind);

$bulkid = exec_db_bulk_start();

foreach($devices as $device) {
    // set variable
    $device_os = strtolower($device['os']);
    $device_id = $device['id'];

    // add new queue
    $_bind = array(
        "platform" => $device['platform']
    );
    $_sql = get_bind_to_sql_select("autoget_commands", $_bind, array(
        "setwheres" => array(
            array("and", array("not", "disabled", 1))
        ),
        "setorders" => array(
            array("asc", "last") // `asc:last` means set high priority to old commands
        )
    ));
    $_rows = exec_db_fetch_all($_sql, $_bind);

    foreach($_rows as $_row) {
        $_pos = strpos($device_os, strtolower($_row['platform']));
        
        // skip if invalid platform
        if($_pos === false) continue;

        // get the last
        $__bind = array(
            "device_id" => $device_id,
            "command_id" => $_row['id']
        );
        $__sql = get_bind_to_sql_select("autoget_lasts", $__bind);
        $__row = exec_db_fetch($__sql, $__bind);

        // compare now and last
        $last_dt = get_value_in_array("queue_last", $__row, "");

        if(!empty($last_dt)) {
            $__bind = array(
                "now_dt" => $now_dt,
                "last_dt" => $last_dt
            );
            $__sql = sprintf("select (%s - time_to_sec(timediff(:now_dt, :last_dt))) as dtf", intval($_row['period']));
            $__row = exec_db_fetch($__sql, $__bind);
            $dtf = intval(get_value_in_array("dtf", $__row, 0));
            if($dtf > 0) {
                continue;
            }
        }

        // add to queue
        $__bind = array(
            "device_id" => $device_id,
            "jobkey" => "cmd",
            "jobstage" => $_row['id'],
            "message" => $_row['command'],
            "created_on" => $now_dt,
            "expired_on" => get_current_datetime(array(
                "now" => $now_dt,
                "adjust" => "10m"
            ))
        );
        //$__sql = get_bind_to_sql_insert("autoget_tx_queue", $__bind);
        //exec_db_query($__sql, $__bind);
        exec_db_bulk_push($bulkid, $__bind);
        
        // update to queue last
        $__bind = array(
            "device_id" => $device_id,
            "command_id" => $_row['id'],
            "queue_last" => $now_dt
        );
        $__sql = get_bind_to_sql_update("autoget_lasts", $__bind, array(
            "setkeys" => array("device_id", "command_id")
        ));
        exec_db_query($__sql, $__bind);
    }
}

exec_db_bulk_end($bulkid, "autoget_tx_queue", array("device_id" "jobkey", "jobstage", "message", "created_on", "expired_on"));

$data['success'] = true;

header("Content-Type: application/json");
echo json_encode($data);
