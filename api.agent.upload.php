<?php
$files = move_uploaded_file_to_stroage();

foreach($files as $file) {
    echo $file['upload_url'];
    break;
}

