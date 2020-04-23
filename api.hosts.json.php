<?php
loadHelper("zabbix.api");
loadHelper("itsm.api");

// Zabbix API
zabbix_authenticate();

$tablename = exec_db_table_create(array(
    "hostid" => array("int", 11),
    "hostname"=> array("varchar", 255),
    "hostip" => array("varchar", 255),
    "datetime" => array("datetime"),
    "last" => array("datetime")
), "autoget_data_hosts", array(
    "before" => array("truncate"),
    "suffix" => ".zabbix",
    "setindex" => array(
        "index_1" => array("hostip", "hostid")
    )
));

$hosts = zabbix_get_hosts();
foreach($hosts as $host) {
    $hostip = "";
    foreach($host->interfaces as $interface) {
        $hostip = $interface->ip;
        break;
    }

    $bind = array(
        "hostid" => $host->hostid,
        "hostname" => $host->host,
        "hostip" => $hostip,
        "datetime" => get_current_datetime(),
        "last" => get_current_datetime()
    );
    $sql = get_bind_to_sql_insert($tablename, $bind, array(
        "setkeys" => array("hostid"),
        "setfixeds" => array("datetime")
    ));
    exec_db_query($sql, $bind);
}

// ITSM API
$tablename = exec_db_table_create(array(
    "assetid" => array("int", 11),
    "assetname" => array("varchar", 255),
    "assetip" => array("varchar", 255),
    "datetime" => array("datetime"),
    "last" => array("datetime")
), "autoget_data_hosts", array(
    "before" => array("truncate"),
    "suffix" => ".itsm",
    "setindex" => array(
        "index_1" => array("assetip", "assetid")
    )
));

$assets = itsm_get_data("assets");
foreach($assets as $asset) {
    $customfields = $asset->customfields;

    $assetip = get_property_value(49, $customfields, "");
    $bind = array(
        "assetid" => $asset->id,
        "assetname" => $asset->name,
        "assetip" => $assetip,
        "datetime" => get_current_datetime(),
        "last" => get_current_datetime()
    );
    $sql = get_bind_to_sql_insert($tablename, $bind, array(
        "setkeys" => array("assetid"),
        "setfixeds" => array("datetime")
    ));
    exec_db_query($sql, $bind);
}

$data = array(
    "success" => true
);

header("Content-Type: application/json");
echo json_encode($data);

