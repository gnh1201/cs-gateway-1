<?php
loadHelper("zabbix.api");

zabbix_authenticate();

$uri = get_uri();
$_p = explode("/", $uri);

$mode = get_requested_value("mode");

$adjust = "-3h";
$end_dt = get_current_datetime();
$start_dt = get_current_datetime(array(
    "adjust" => $adjust
));

$tablename = exec_db_table_create(array(
    "hostid" => array("int", 11),
    "hostname" => array("varchar", 255),
    "severity" => array("tinyint", 1),
    "hostgroups" => array("varchar", 255),
    "datetime" => array("datetime")
), "autoget_severity", array(
    "suffix" => ".zabbix",
    "setindex" => array(
        "index_1" => array("hostid", "datetime"),
        "index_2" => array("datetime")
    )
));

$data = array();

if($mode == "background") {
    $hosts = zabbix_get_hosts();
    foreach($hosts as $host) {
        $severity = 0;
        
        $_hostgroups = array();
		foreach($host->groups as $hostgroup) {
			$_hostgroups[] = $hostgroup->name;
		}
        
        $triggers = zabbix_get_triggers($host->hostid);
        foreach($triggers as $trigger) {
            $_severity = intval($trigger->priority);
            if($_severity > $severity) {
                $severity = $_severity;
            }
        }

        $bind = array(
            "hostid" => $host->hostid
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
            "severity" => $severity,
            "hostgroups" => implode(",", $_hostgroups),
            "datetime" => $end_dt
        );

        if($_value > 0) {
            $sql = get_bind_to_sql_update($tablename, $bind, array(
                "setkeys" => array("hostid")
            ));
            exec_db_query($sql, $bind);
        } else {
            $sql = get_bind_to_sql_insert($tablename, $bind);
            exec_db_query($sql, $bind);
        }
    }
    
    $data['success'] = true;
} else {
    if(in_array("query", $_p)) {
        $targets = get_requested_value("targets", array("_JSON"));

        // get IPs by range
        $hostgroups = array();
        $hostips = array();
        $hostnames = array();
        $types = array("polystat"); // polystat is default
        $severities = array();
        foreach($targets as $target) {
            switch($target->target) {
                case "hostgroups":
                    foreach($target->data as $v) {
                        $hostgroups[] = $v;
                    }
                    break;
                case "hostips":
                    $hostips = array();
                    foreach($target->data as $v) {
                        $hostips[] = $v;
                    }
                    break;
                case "hostnames":
                    $hostnames = array();
                    foreach($targets->data as $v) {
                        $hostnames[] = $v;
                    }
                    break;
                case "types":
                    $types = array();
                    foreach($target->data as $v) {
                        $types[] = $v;
                    }
                    break;
                case "severities":
                    $severities = array();
                    foreach($target->data as $v) {
                        $severities[] = $v;
                    }
                    break;
                default:
                    $d = explode(".", $target->target);
                    if($d[0] == "hostname") {
                        $hostnames[] = $d[1];
                    }
            }
        }
        
        if(count($types) == 0) {
            $types[] = "polystat";
        }

        if(in_array("polystat", $types)) {
            $bind = false;
            $options = array(
                "setwheres" => array(
                    array("and", array("gt", "datetime", $start_dt))
                )
            );

            if(count($hostgroups) > 0) {
                $options['setwheres'][] = array("and", array("inset", "hostgroups", $hostgroups));
            }

            if(count($severities) > 0) {
                $options['setwheres'][] = array("and", array("in", "severity", $severities));
            }

            $sql = get_bind_to_sql_select($tablename, $bind, $options);
            $rows = exec_db_fetch_all($sql, $bind);
            foreach($rows as $row) {
                $data[] = array(
                    "target" => $row['hostname'],
                    "datapoints" => array(
                        array(intval($row['severity']), $end_dt) 
                    )
                );
            }
        }
        
        if(in_array("singlestat", $types)) {
            $bind = false;
            $options = array(
                "setwheres" => array(
                    array("and", array("gt", "datetime", $start_dt))
                )
            );

            if(count($hostgroups) > 0) {
                $options['setwheres'][] = array("and", array("inset", "hostgroups", $hostgroups));
            }

            if(count($severities) > 0) {
                $options['setwheres'][] = array("and", array("in", "severity", $severities));
            }
            
            $options['getcount'] = true;

            $sql = get_bind_to_sql_select($tablename, $bind, $options);
            $rows = exec_db_fetch_all($sql, $bind);
            foreach($rows as $row) {
                $data[] = array(
                    "target" => "target_1",
                    "datapoints" => array(
                        array(intval($row['value']), $end_dt) 
                    )
                );
            }
        }
    }
}

header("Content-Type: application/json");
echo json_encode($data);
