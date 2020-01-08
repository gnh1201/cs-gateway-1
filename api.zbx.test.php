<?php
loadHelper("zabbix.api");

zabbix_authenticate();

$response = zabbix_get_alerts(10390);

var_dump($response);


