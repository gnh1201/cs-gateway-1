<?php
loadHelper("json.format");
loadHelper("zabbix.api");

$requests = get_requests();

$uri = get_uri();
$mode = get_requested_value("mode");
$code = get_requested_value("code");

$panel_hash = "";

$_p = explode("/", $uri);
$_data = array();

if(in_array("query", $_p)) {
    // get requested data
    $targets = get_requested_value("targets", array("_JSON"));
    $panel_id = get_requested_value("panelId", array("_JSON"));
    $panel_hash = get_hashed_text(serialize(array("panel_id" => $panel_id, "targets" => $targets)));

    // save requested data
    $bind = array(
        "name" => $panel_hash,
        "text" => $requests['_RAW'],
        "uri" => $uri,
        "datetime" => get_current_datetime()
    );
    $sql = get_bind_to_sql_insert("autoget_data_reverse", $bind, array(
        "setkeys" => array("name")
    ));
    exec_db_query($sql, $bind);

    // get saved data
    if($mode != "background") {
        $bind = array(
            "name" => $panel_hash,
            "status" => 1
        );
        $sql = get_bind_to_sql_select("autoget_data_reverse_file", $bind, array(
            "setorders" => array(
                array("desc", "datetime")
            ),
            "setlimit" => 1,
            "setpage" => 1
        ));
        $rows = exec_db_fetch_all($sql, $bind);

        foreach($rows as $row) {
            $filename = $row['file'];
            $fr = read_storage_file($filename, array(
                "storage_type" => "cache"
            ));
            if(!empty($fr)) {
                echo $fr;
                exit;
            }
        }
    }

    // get hosts from zabbix server
    zabbix_authenticate();
    $hosts = zabbix_retrieve_hosts();

    // make temporary database by hosts
    $bulkid = exec_db_bulk_start();
    $_tbl1 = exec_db_temp_create(array(
        "hostid" => array("int", 11),
        "hostname" => array("varchar", 255),
        "hostip" => array("varchar", 255),
        "hostgroups" => array("varchar", 255)
    ));

    foreach($hosts as $host) {
		$_hostgroups = array();
		foreach($host->groups as $hostgroup) {
			$_hostgroups[] = $hostgroup->name;
		}

        $bind = array(
            "hostid" => $host->hostid,
            "hostname" => $host->host,
            "hostip" => $host->interfaces[0]->ip,
            "hostgroups" => implode(",", $_hostgroups)
        );
        exec_db_bulk_push($bulkid, $bind);
    }
    exec_db_bulk_end($bulkid, $_tbl1, array("hostid", "hostname", "hostip", "hostgroups"));

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

	// initialize
    $setwheres = array();

    // get hosts by IP
    foreach($hostips as $ip) {
        $setwheres[] = array("or", array("eq", "hostip", $ip));

        $d = explode("*", $ip);
        if(count($d) > 1) {
            $setwheres[] = array("or", array("left", "hostip", $d[0]));
        }
    }
    
    // get hosts by host name
    foreach($hostnames as $name) {
        $setwheres[] = array("or", array("eq", "hostname", $name));

        $d = explode("*", $name);
        if(count($d) > 1) {
            $setwheres[] = array("or", array("left", "hostname", $d[0]));
        }
    }

    // get hosts by hostgroups
    if(count($hostgroups) > 0) {
		$setwheres[] = array("or", array("inset", "hostgroups", $hostgroups));
	}
    
    // make SQL statement
    $sql = get_bind_to_sql_select($_tbl1, false, array(
        "setwheres" => $setwheres
    ));
    
    // save rows to temporary table
    $_tbl1_0 = exec_db_temp_start($sql, false);

    // get rows
    $sql = get_bind_to_sql_select($_tbl1_0);
    $rows = exec_db_fetch_all($sql);

    // get triggers
    $_tbl2 = exec_db_temp_create(array(
        "hostid" => array("int", 11),
        "hostname" => array("varchar", 255),
        "description" => array("varchar", 255),
        "severity" => array("tinyint", 1),
        "timestamp" => array("varchar", 255)
    ));

    foreach($rows as $row) {
        $triggers = zabbix_get_triggers($row['hostid']);
        $alerts = zabbix_get_alerts($row['hostid']);

        $bulkid = exec_db_bulk_start();
        foreach($triggers as $trigger) {
            $_timestamp = get_current_timestamp();
            foreach($alerts as $alert) {
                if($alert->name == $trigger->description) {
                    $_timestamp = $alert->clock;
                    break;
                }
            }

            $bind = array(
                "hostid" => $row['hostid'],
                "hostname" => $row['hostname'],
                "description" => $trigger->description,
                "severity" => $trigger->priority,
                "timestamp" => date($config['timeformat'], intval($_timestamp)),
            );
            exec_db_bulk_push($bulkid, $bind);
        }
        exec_db_bulk_end($bulkid, $_tbl2, array("hostid", "hostname", "description", "severity", "timestamp"));
    }

    // if panel type is polystat
    if(in_array("polystat", $types)) {
        // post-processing problems
        $sql = "select a.hostid as hostid, a.hostname as hostname, max(b.severity) as severity from $_tbl1_0 a left join $_tbl2 b on a.hostid = b.hostid group by a.hostid";
        $rows = exec_db_fetch_all($sql, false, array(
            "getvalues" => true
        ));

        foreach($rows as $row) {
            $_data[] = array(
                "target" => $row[1],
                "datapoints" => array(
                    array(intval($row[2]), get_current_datetime())
                )
            );
        }
    }

    // if panel type is singlestat
    if(in_array("singlestat", $types)) {
        // post-processing problems
        $sql = "select a.hostid as hostid, a.hostname as hostname, max(b.severity) as severity from $_tbl1_0 a left join $_tbl2 b on a.hostid = b.hostid group by a.hostid";
        $_tbl3 = exec_db_temp_start($sql);

        $sql = "";
        if(count($severities) > 0) {
            $sql = sprintf("select concat('upper_', severity) as name, count(*) as lastvalue from $_tbl3 where severity in (%s) group by severity", implode(",", $severities));
        } else {
            $sql = "select concat('upper_', severity) as name, count(*) as lastvalue from $_tbl3";
        }
        $rows = exec_db_fetch_all($sql, false, array(
            "getvalues" => true
        ));

        foreach($rows as $row) {
            $_data[] = array(
                "target" => $row[0],
                "datapoints" => array(
                    array(intval($row[1]), get_current_datetime())
                )
            );
        }

        exec_db_temp_end($_tbl3);
    }

    // if panel type is table
    if(in_array("list", $types)) {
        //$sql = "select hostname, description, severity, timestamp, debug from $_tbl2";
        $sql = "select hostname, description, severity, timestamp from $_tbl2";
        $rows = exec_db_fetch_all($sql, false, array(
            "getvalues" => true
        ));

        $_data[] = array(
            "columns" => array(
                array("text" => "Hostname", "type" => "text"),
                array("text" => "Description", "type" => "text"),
                array("text" => "Severity", "type" => "number"),
                array("text" => "Timestamp", "type" => "timestamp")
            ),
            "rows" => $rows,
            "type" => "table"
        );
    }
}

write_common_log($uri, "api.zbx.status.json");
write_common_log($requests['_RAW'], "api.zbx.status.json");

header("Content-Type: application/json");
$result = json_encode($_data);

if(!empty($panel_hash)) {
    // make panel cache
    $fw = write_storage_file($result, array(
        "storage_type" => "cache",
        "basename" => true
    ));

    // add reverse file
    $bind = array(
        "name" => $panel_hash,
        "file" => $fw,
        "status" => 0,
        "datetime" => get_current_datetime()
    );
    $sql = get_bind_to_sql_insert("autoget_data_reverse_file", $bind);
    exec_db_query($sql, $bind);
}

echo $result;
