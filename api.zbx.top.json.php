<?php
loadHelper("json.format");
loadHelper("zabbix.api");

$uri = get_uri();

$_p = explode("/", $uri);
$_data = array();
if(in_array("query", $_p)) {
    zabbix_authenticate();

    $_tbl1 = exec_db_temp_create(array(
        "hostid" => array("int", 11),
        "hostname" => array("varchar", 255),
        "itemname" => array("varchar", 255),
        "lastvalue" => array("float", "5,2")
    ));

    $itemnames = array();
    $hostips = array();
    $targets = get_requested_value("targets", array("_JSON"));
    foreach($targets as $target) {
        switch($target->target) {
            case "itemnames":
                foreach($target->data as $v) {
                    $itemnames[] = $v;
                }
                break;
            case "hostips":
                foreach($target->data as $v) {
                    $hostips[] = $v;
                }
                break;
        }
    }

    $hosts = zabbix_retrieve_hosts();
    foreach($hosts as $host) {
        $hostid = $host->hostid;
        $hostname = $host->host;
        $hostip = $host->interfaces[0]->ip;

        if(!in_array($hostip, $hostips)) {
            continue;
        }

        foreach($itemnames as $itemname) {
            $items = zabbix_get_items($hostid);
            foreach($items as $item) {
                if(strtolower($item->name) == strtolower($itemname)) {
                    // lastvalue
                    $lastvalue = $item->lastvalue;
                    switch($itemname) {
                        case "CPU Usage":
                            $lastvalue = (-100.0 + floatval($lastvalue)) * (-1.0);
                            break;
                    }

                    // insert into temporary table
                    $bind = array(
                        "hostid" => $hostid,
                        "hostname" => $hostname,
                        "itemname" => $itemname,
                        "lastvalue" => $lastvalue
                    );
                    $sql = get_bind_to_sql_insert($_tbl1, $bind);
                    exec_db_query($sql, $bind);
                }
            }
        }
    }

    //$sql = get_bind_to_sql_select($_tbl1);
    $sql = "select @num := @num + 1 as rank, hostname, lastvalue from $_tbl1, (select @num := 0) r order by lastvalue desc limit 100";
    $rows = exec_db_fetch_all($sql, false, array(
        "getvalues" => true
    ));
    $_data[] = array(
        "columns" => array(
            //array("text" => "Hostid", "type" => "number"),
            array("text" => "Rank", "type" => "number"),
            array("text" => "Hostname", "type" => "string"),
            //array("text" => "Itemname", "type" => "string"),
            array("text" => "Lastvalue", "type" => "string")
        ),
        "rows" => $rows,
        "type" => "table"
    );

}

header("Content-Type: application/json");
echo json_encode_ex($_data);
