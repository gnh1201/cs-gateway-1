<?php
loadHelper("zabbix.api");

    // get hosts from zabbix server
    zabbix_authenticate();
    $hosts = zabbix_retrieve_hosts();

foreach($hosts as $host) {
    var_dump($host);
    exit;
}
