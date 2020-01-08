<?php
loadHelper("itsm.api");

$data = itsm_get_data("users");

var_dump($data);
