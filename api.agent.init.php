<?php


$sql = get_bind_to_sql_select("autoget_devices");
$devices = exec_db_fetch_all($sql);

foreach($devices as $device) {
    /*
    $bind = array(
        "device_id" => $device['id'],
        "jobkey" => "init",
        "jobstage" => 0,
        "message" => "init",
        "created_on" => get_current_datetime(),
        "expired_on" => get_current_datetime(array("adjust" => "20m")),
        "is_read" => 0
    );
    $sql = get_bind_to_sql_insert("autoget_tx_queue", $bind);
    exec_db_query($sql, $bind);
    */
    
    // get valid IP list
    $network_ip_list = explode(",", $device['net_ip']);
    $ipaddrs = array();
    foreach($network_ip_list as $ip) {
        if(!in_array($ip, array("127.0.0.1", "::1"))) {
            $ipaddrs[] = $ip;
        }
    }
    $ipaddrs = array_filter($ipaddrs);

    // find zabbix host ID
    $bind = false;
    $sql = get_bind_to_sql_select("autoget_data_hosts.zabbix", $bind, array(
        "setwheres" => array(
            array("and", array("in", "hostip", $ipaddrs))
        ),
        "setlimit" => 1,
        "setpage" => 1
    ));
    $rows = exec_db_fetch_all($sql, $bind);
    foreach($rows as $row) {
        $bind = array(
            "id" => $device['id'],
            "zabbix_hostid" => $row['hostid']
        );
        $sql = get_bind_to_sql_update("autoget_devices", $bind, array(
            "setkeys" => array("id")
        ));
        exec_db_query($sql, $bind); 
    }

    // find itsm asset ID
    $bind = false;
    $sql = get_bind_to_sql_select("autoget_data_hosts.itsm", $bind, array(
        "setwheres" => array(
            array("and", array("inset", "assetip", $ipaddrs))
        ),
        "setlimit" => 1,
        "setpage" => 1
    ));

    $rows = exec_db_fetch_all($sql, $bind);
    foreach($rows as $row) {
        $bind = array(
            "id" => $device['id'],
            "itsm_assetid" => $row['assetid']
        );
        $sql = get_bind_to_sql_update("autoget_devices", $bind, array(
            "setkeys" => array("id")
        ));
        exec_db_query($sql, $bind); 
    }
}

$data = array(
    "success" => true
);

header("Content-Type: application/json");
echo json_encode($data);

