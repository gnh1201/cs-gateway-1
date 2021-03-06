<?php
loadHelper("json.format");
loadHelper("zabbix.api");

$data = array();

$uri = get_uri();
$mode = get_requested_value("mode");

$adjust = "-3h";
$end_dt = get_current_datetime();
$start_dt = get_current_datetime(array(
    "adjust" => $adjust
));

$_p = explode("/", $uri);

$tablename = exec_db_table_create(array(
    "hostid" => array("int", 11),
    "hostname" => array("varchar", 255),
    "hostgroups" => array("varchar", 255),
    "itemid" => array("int", 11),
    "itemname" => array("varchar", 255),
    "value" => array("float", "5,2"),
    "datetime" => array("datetime")
), "autoget_items", array(
    "suffix" => ".float.zabbix",
    "setindex" => array(
        "index_1" => array("hostid", "itemid", "datetime"),
        "index_2" => array("datetime")
    )
));

if($mode == "background") {
    zabbix_authenticate();
    
    $hosts = zabbix_get_hosts();
    $itemnames = array("CPU Usage", "Memory Usage");
    foreach($hosts as $host) {
        $_hostgroups = array();
		foreach($host->groups as $hostgroup) {
			$_hostgroups[] = $hostgroup->name;
		}
        
        $items = zabbix_get_items($host->hostid);
        foreach($items as $item) {
            if(in_array($item->name, $itemnames)) {
                $bind = array(
                    "hostid" => $host->hostid,
                    "itemid" => $item->itemid
                );
                $options = array(
                    "getcount" => true
                );
                $sql = get_bind_to_sql_select($tablename, $bind, $options);
                $rows = exec_db_fetch($sql, $bind);
                $_value = 0;
                foreach($rows as $row) {
                    $_value = $row['value'];
                }
                
                $bind = array(
                    "hostid" => $host->hostid,
                    "hostname" => $host->host,
                    "hostgroups" => implode(",", $_hostgroups),
                    "itemid" => $item->itemid,
                    "itemname" => $item->name,
                    "value" => $item->lastvalue,
                    "datetime" => $end_dt
                );

                if($_value > 0) {
                    $sql = get_bind_to_sql_update($tablename, $bind, array(
                        "setkeys" => array("hostid", "itemid")
                    ));
                    exec_db_query($sql, $bind);
                } else {
                    $sql = get_bind_to_sql_insert($tablename, $bind);
                    exec_db_query($sql, $bind);
                }
            }
        }
    }
    
    $data['success'] = true;
} else {
    if(in_array("query", $_p)) {
        $targets = get_requested_value("targets", array("_JSON"));
        
        foreach($targets as $target) {
            switch($target->target) {
                case "hostgroups":
                    foreach($target->data as $v) {
                        $hostgroups[] = $v;
                    }
                    break;
                case "itemnames":
                    foreach($target->data as $v) {
                        $itemnames[] = $v;
                    }
                    break;
                case "hostnames":
                    foreach($target->data as $v) {
                        $hostnames[] = $v;
                    }
                    break;
            }
        }
        
        $bind = false;
        $options = array(
            "fieldnames" => array("hostname", "value"),
            "setwheres" => array(),
            //"setlimit" => 10,
            //"setpage" => 1,
            //"setorders" => array(
            //    array("desc", "value")
            //)
        );

        if(count($hostgroups) > 0) {
            $options['setwheres'][] = array("and", array("inset", "hostgroups", $hostgroups));
        }

        if(count($itemnames) > 0) {
            $options['setwheres'][] = array("and", array("in", "itemname", $itemnames));
        }
        
        if(count($hostnames) > 0) {
            $options['setwheres'][] = array("and", array("in", "hostnames", $hostnames));
        }
        
        $_tablename = exec_db_temp_create(array(
            "hostname" => array("varchar", 255),
            "itemname" => array("varchar", 255),
            "value" => array("float", "5,2")
        ));
        
        $rows = array();
        
        $sql = get_bind_to_sql_select($tablename, $bind, $options);
        $_rows = exec_db_fetch_all($sql, $bind);
        foreach($_rows as $_row) {
            $value = $_row['value'];
            if($_row['itemname'] == "CPU Usage") {
                $value = 100 - $_row['value'];
            }
            
            if($value <= 0) {
                continue;
            }

            $bind = array(
                "hostname" => $_row['hostname'],
                "itemname" => $_row['itemname'],
                "value" => $value
            );
            $sql = get_bind_to_sql_insert($_tablename, $bind);
            exec_db_query($sql, $bind);
        }
        
        $bind = false;
        $sql = get_bind_to_sql_select($_tablename, $bind);
        $_rows = exec_db_fetch_all($sql, $bind);
        $i = 0;
        foreach($_rows as $_row) {
            $i++;
            $rows[] = array($i, $_row['hostname'], $_row['value']);
            if($i == 10) break;
        }

        $data[] = array(
            "columns" => array(
                array("text" => "Rank", "type" => "number"),
                array("text" => "Hostname", "type" => "string"),
                array("text" => "Value", "type" => "string")
            ),
            "rows" => $rows,
            "type" => "table"
        );
    }
}

header("Content-Type: application/json");
echo json_encode($data);
