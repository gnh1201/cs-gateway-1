<?php

$tablename = exec_db_table_create(array(
    "assetid" => array("int", 11),
    "assetname" => array("varchar", 255),
    "assetip" => array("varchar", 255),
    "datetime" => array("datetime"),
    "last" => array("datetime")
), "autoget_data_hosts.itsm", array(
    "setindex" => array(
        "index_1" => array("assetip", "assetid")
    )
));


