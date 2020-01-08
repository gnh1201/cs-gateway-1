<?php

write_storage_file("hello world", array(
    "storage_type" => "logs",
    "mode" => "a",
    "filename" => "helloworld.txt"
));

echo "hello world";
