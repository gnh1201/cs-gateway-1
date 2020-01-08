<?php
$files = move_uploaded_file_to_stroage();

header("Content-Type: application/json");
echo json_encode($files);

