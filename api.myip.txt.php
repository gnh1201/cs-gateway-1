<?php
loadHelper("networktool");

$ne = get_network_event();

header("Content-Type: text/plain");
echo $ne['client'];

