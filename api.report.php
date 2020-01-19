<?php
loadHelper("zabbix.api");

$hosts = zabbix_retrieve_hosts();

var_dump($hosts);


