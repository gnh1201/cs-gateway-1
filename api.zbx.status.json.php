<?php
loadHelper("json.format");
loadHelper("zabbix.api");

$uri = get_uri();
$mode = get_requested_value("mode");

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
		$filename = $panel_hash;
		$fr = read_storage_file($filename, array(
			"storage_type" => "cache"
		));
		if(!empty($fr)) {
			echo $fr;
			exit;
		}
	}

    // get hosts from zabbix server
    zabbix_authenticate();
    $hosts = zabbix_retrieve_hosts();

    // make temporary database by hosts
    $_tbl1 = exec_db_temp_create(array(
        "hostid" => array("int", 11),
        "hostname" => array("varchar", 255),
        "hostip" => array("varchar", 255)
    ));
    foreach($hosts as $host) {
        $bind = array(
            "hostid" => $host->hostid,
            "hostname" => $host->host,
            "hostip" => $host->interfaces[0]->ip
        );
        $sql = get_bind_to_sql_insert($_tbl1, $bind);
        exec_db_query($sql, $bind);
    }

    // get IPs by range
    $hostips = array();
    $hostnames = array();
    $types = array("polystat"); // polystat is default
    $severities = array();
    foreach($targets as $target) {
        switch($target->target) {
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

    // get hosts by IP
    $setwheres = array();
    $_setwheres = array();
    foreach($hostips as $ip) {
        $_setwheres[] = array("or", array("eq", "hostip", $ip));

        $d = explode("*", $ip);
        if(count($d) > 1) {
            $_setwheres[] = array("or", array("left", "hostip", $d[0]));
        }
    }
    foreach($hostnames as $name) {
        $_setwheres[] = array("or", array("eq", "hostname", $name));

        $d = explode("*", $name);
        if(count($d) > 1) {
            $_setwheres[] = array("or", array("left", "hostname", $d[0]));
        }
    }
    $setwheres[] = array("and", $_setwheres);

    // make SQL statement
    $sql = get_bind_to_sql_select($_tbl1, false, array(
        "setwheres" => $setwheres
    ));

    // save rows to temporary table
    $_tbl1_0 = exec_db_temp_start($sql, false);

    // get rows
    $sql = get_bind_to_sql_select($_tbl1_0);
    $rows = exec_db_fetch_all($sql);

/*
    // get problems
    $_tbl2 = exec_db_temp_create(array(
        "hostid" => array("int", 11),
        "eventid" => array("int", 11),
        "hostname" => array("varchar", 255),
        "description" => array("varchar", 255),
        "severity" => array("tinyint", 1),
        "suppressed" => array("tinyint", 1),
        "acknowledged" => array("tinyint", 1),
        "debug" => array("text")
    ));
    foreach($rows as $row) {
        $problems = zabbix_get_problems($row['hostid']);
        foreach($problems as $problem) {
            $bind = array(
                "hostid" => $row['hostid'],
                "eventid" => $problem->eventid,
                "hostname" => $row['hostname'],
                "description" => $problem->name,
                "severity" => $problem->severity,
                "suppressed" => $problem->suppressed,
                "acknowledged" => $problem->acknowledged,
                "debug" => json_encode($problem)
            );
            $sql = get_bind_to_sql_insert($_tbl2, $bind);
            exec_db_query($sql, $bind);
        }
    }
*/

    // get triggers
    $_tbl2 = exec_db_temp_create(array(
        "hostid" => array("int", 11),
        "hostname" => array("varchar", 255),
        "description" => array("varchar", 255),
        "severity" => array("tinyint", 1),
        "timestamp" => array("varchar", 255)
        //"debug" => array("text")
    ));
    foreach($rows as $row) {
        $triggers = zabbix_get_triggers($row['hostid']);
        $alerts = zabbix_get_alerts($row['hostid']);

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
                //"debug" => json_encode($trigger),
            );
            $sql = get_bind_to_sql_insert($_tbl2, $bind);
            exec_db_query($sql, $bind);
        }
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
        //$sql = "select hostid, hostname, max(severity) as severity from $_tbl2 group by hostid";
        $sql = "select a.hostid as hostid, a.hostname as hostname, max(b.severity) as severity from $_tbl1_0 a left join $_tbl2 b on a.hostid = b.hostid group by a.hostid";
        $_tbl3 = exec_db_temp_start($sql);

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
                array("text" => "Timestamp", "type" => "timestamp"),
                //array("text" => "Suppressed", "type" => "number"),
                //array("text" => "Acknowledged", "type" => "number"),
                //array("text" => "Debug", "type" => "number")
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
    $fw = write_storage_file($result, array(
        "storage_type" => "cache",
        "filename" => $panel_hash
    ));
}

echo $result;
