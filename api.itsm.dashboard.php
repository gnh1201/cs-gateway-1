<?php
loadHelper("itsm.api");
loadHelper("string.utils");

$data = array(
    "success" => false
);

$now_dt = get_current_datetime();

// get requested variables
$type = get_requested_value("type");

// get data from itsm server
$clients = itsm_get_data("clients");
$assets = itsm_get_data("assets");
$licenses = itsm_get_data("licenses");
$credentials = itsm_get_data("credentials");

//write_debug_log(json_encode($credentials));

$_data = array();

switch($type) {
    case "panel1":
        foreach($clients as $client) {
            $num_assets = 0;
            $num_licenses = 0;
            $num_credentials = 0;
            $num_os_win = 0;
            $num_os_lin = 0;
            $num_os_oth = 0;

            // get number of assets
            foreach($assets as $asset) {
                if($client->id == $asset->clientid) {
                    $num_assets++;
                    $device_uuid = get_property_value("102", $asset->customfields, "");
                    $bind = array(
                        "uuid" => $device_uuid
                    );
                    $sql = get_bind_to_sql_select("autoget_devices", $bind);
                    $rows = exec_db_fetch_all($sql, $bind);
                    foreach($rows as $row) {
                        $osnames = get_tokenized_text(strtolower($row['os']));
                        if(in_array("windows", $osnames)) {
                            $num_os_win++;
                        } elseif(in_array("linux", $osnames)) {
                            $num_os_lin++;
                        } else {
                            $num_os_oth++;
                        }
                    }
                }
            }

            // get number of licenses
            foreach($licenses as $license) {
                if($client->id == $license->clientid) {
                    $num_licenses++;
                }
            }

            // get number of credentials
            foreach($credentials as $credential) {
                if($client->id == $credential->clientid) {
                    $num_credentials++;
                }
            }

            // add to data
            $_data[] = array(
                "clientid" => $client->id,
                "clientname" => $client->name,
                "num_assets" => $num_assets,
                "num_licenses" => $num_licenses,
                "num_credentials" => $num_credentials,
                "num_os_win" => $num_os_win,
                "num_os_lin" => $num_os_lin,
                "num_os_oth" => $num_os_oth
            );
        }
        break;

    case "panel2":
        // get messages
        $bind = array(
            "end_dt" => $now_dt,
            "start_dt" => get_current_datetime(array(
                "now" => $now_dt,
                "adjust" => "-30d"
            ))
        );
        $sql = "select clientid as client_id, round(count(if(type='text', 1, null)) / 6) as sent_text, round(count(if(type='voice', 1, null)) / 6) as sent_voice from twilio_messages where datetime >= :start_dt and datetime <= :end_dt group by clientid";
        $rows = exec_db_fetch_all($sql, $bind);
        $_rows = array();
        foreach($rows as $k=>$row) {
            $client_name = "";
            foreach($clients as $client) {
                if($row['client_id'] == $client->id) {
                    $client_name = $client->name;
                    break;
                }
            }
            $rows[$k]['client_name'] = $client_name;
            
            if(!empty($client_name)) {
                $_rows[] = $rows[$k];
            }
        }
        $_data = $_rows;
        break;

    case "panel3":
        // get cpu last 10
        $bind = array(
            "end_dt" => $now_dt,
            "start_dt" => get_current_datetime(array(
                "now" => $now_dt,
                "adjust" => "-20m"
            ))
        );
        $sql = "
            select a.device_id as device_id, b.computer_name as device_name, a.`load` as `load` from (
                select device_id,  max(`load`) as `load` from `autoget_data_cpu.zabbix`
                    where basetime >= :start_dt and basetime <= :end_dt
                    group by device_id
            ) a, autoget_devices b where a.device_id = b.id and `load` < 100 order by `load` desc limit 10
        ";
        $rows = exec_db_fetch_all($sql, $bind);
        $_data['cpulasts'] = $rows;

        // get memory last 10
        $bind = array(
            "end_dt" => $now_dt, 
            "start_dt" => get_current_datetime(array(
                "now" => $now_dt,
                "adjust" => "-20m"
            ))
        );
        $sql = "
            select a.device_id as device_id, b.computer_name as device_name, a.`load` as `load` from (
                select device_id, max(`load`) as `load` from `autoget_data_mem.zabbix`
                    where basetime >= :start_dt and basetime <= :end_dt
                    group by device_id
            ) a, autoget_devices b where a.device_id = b.id and `load` < 100 order by `load` desc limit 10
        ";
        $rows = exec_db_fetch_all($sql, $bind);
        $_data['memlasts'] = $rows;

        break;
}

$data['success'] = true;
$data['data'] = $_data;

header("Content-Type: application/json");
echo json_encode($data);
