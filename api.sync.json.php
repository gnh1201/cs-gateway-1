<?php
loadHelper("webpagetool");
loadHelper("itsm.api");
loadHelper("UUID.class");

$data = array(
    "success" => false
);

// step 1: zabbix->datamon
$sql = get_bind_to_sql_select("autoget_data_hosts.view1");
$rows = exec_db_fetch_all($sql);

foreach($rows as $row) {
    if(empty($row['itsm_assetid'])) {
        $tag = "ZBXHOST-" . $row['zabbix_hostid'];

        itsm_add_data("assets", array(
            "categoryid" => 1,
            "adminid" => 1,
            "clientid" => 1,
            "userid" => 1,
            "manufacturerid" => "",
            "modelid" => 1,
            "supplierid" => "",
            "statusid" => "4",
            "purchase_date" => date("Y-m-d"),
            "warranty_date" => date("Y-m-d"),
            "warranty_months" => 36,
            "tag" => $tag,
            "name" => $row['hostname'],
            "serial" => "",
            "notes" => "",
            "locationid" => "",
            "qrvalue" => ""
        ), array(
            49 => $row['hostip']
        ));
    }
}

// step 2: reload hosts list
get_web_page(get_route_link("api.hosts.json"), "get");

// step 3: init agents
get_web_page(get_route_link("api.agent.init"), "get");

// step 4: change status to idle
/*
$bind = false;
$sql = get_bind_to_sql_select("autoget_devices", $bind);
$rows = exec_db_fetch_all($sql, $bind); 
foreach($rows as $row) {
    $is_zabbix = !empty($row['zabbix_hostid']);
    $is_itsm = !empty($row['itsm_assetid']);

    if(!$is_zabbix && $is_itsm) {
        // change asset status to idle(idle=2)
        $rows = itsm_get_data("assets", array(
            "id" => $row['itsm_assetid'],
            "statusid" => 1
        ));
        foreach($rows as $row) {
            $responses[] = itsm_edit_data("assets", array(
                "id" => $row->id,
                "statusid" => "2"
            ));
        }
    }
}
*/

$bind = false;
$sql = get_bind_to_sql_select("autoget_data_hosts.view2", $bind);
$rows = exec_db_fetch_all($sql, $bind);
foreach($rows as $row) {
    $assetips = explode(",", $row['assetip']);
    if(!in_array($row['hostip'], $assetips)) {
        // Use -> Idle
        $_rows = itsm_get_data("assets", array(
            "id" => $row['assetid'],
            "statusid" => 1
        ));
        foreach($_rows as $_row) {
            $responses[] = itsm_edit_data("assets", array(
                "id" => $_row->id,
                "statusid" => "2"
            ));
        }
    } else {
        // Idle -> New
        $_rows = itsm_get_data("assets", array(
            "id" => $row['assetid'],
            "statusid" => 2
        ));
        foreach($_rows as $_row) {
            $responses[] = itsm_edit_data("assets", array(
                "id" => $_row->id,
                "statusid" => "4"
            ));
        }
    }
}

$data['success'] = true;
$data['responses'] = $responses;

header("Content-Type: application/json");
echo json_encode($data);
