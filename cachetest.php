<?php
$contents = "blah blah";

$fw = write_storage_file($contents, array(
    "filename" => "blah.txt",
    "storage_type" => "cache"
));

echo $fw;
