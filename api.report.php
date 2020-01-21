<?php
// get devices
$bind = array(
    "platform" => "windows"
);
$sql = get_bind_to_sql_select("autoget_devices", $bind);
$devices = exec_db_fetch_all($sql, $bind);

// load spreadsheet
$fw = write_storage_file("", array(
    "storage_type" => "autoget",
    "filename" => "template.xlsx",
    "mode" => "fake"
));
$spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($fw);

// set datetime
$adjust = "-24h";
$end_dt = get_current_datetime();
$start_dt = get_current_datetime(array(
    "now" => $end_dt,
    "adjust" => $adjust
));

/*
//change it
$sheet = $spreadsheet->getActiveSheet();
$sheet->setCellValue('A1', 'New Value');

//write it again to Filesystem with the same name (=replace)
$writer = new Xlsx($spreadsheet);
$writer->save('yourspreadsheet.xls');
*/

// create summary sheet
$summary_sheet = clone $spreadsheet->getSheetByName("Template_Summary");
$summary_sheet->setTitle('Summary');

$summary_sheet->setCellValue("D3", $end_dt); // reported datetime
$summary_sheet->setCellValue("D7", count($devices)); // number of servers

$row_offset = 13;
$col_offsets = range('A', 'M');
$row_n = 0;
foreach($devices as $device) {
    // draw summary report
    $bind = array(
        "device_id" => $device['id'],
        "start_dt" => $start_dt,
        "end_dt" => $end_dt 
    );
    $sql = get_bind_to_sqlx("autoget_summary_report");
    $rows = exec_db_fetch_all($sql, $bind);
    foreach($rows as $row) {
        foreach($col_offsets as $col_offset) {
                $cell = sprintf("%s%s", $col_offset, $row_offset + $row_n);
                switch($col_offset) {
                    case "A":
                        $summary_sheet->setCellValue($cell, $row_n + 1);
                        break;
                    case "B":
                        $summary_sheet->setCellValue($cell, ""); // group
                        break;
                    case "C":
                        $summary_sheet->setCellValue($cell, $device['computer_name']);
                        break;
                    case "D":
                        $summary_sheet->setCellValue($cell, $row['cpu_max_load']);
                        break;
                    case "E":
                        $summary_sheet->setCellValue($cell, $row['cpu_avg_load']);
                        break;
                    case "F":
                        $summary_sheet->setCellValue($cell, $row['mem_max_load']);
                        break;
                    case "G":
                        $summary_sheet->setCellValue($cell, $row['mem_avg_load']);
                        break;
                    case "H":
                        $summary_sheet->setCellValue($cell, ""); // network QTY
                        break;
                    case "I":
                        $summary_sheet->setCellValue($cell, ""); // network MAX
                        break;
                    case "J":
                        $summary_sheet->setCellValue($cell, ""); // network AVG
                        break;
                    case "K":
                        $summary_sheet->setCellValue($cell, $row['disk_qty']);
                        break;
                    case "L":
                        $summary_sheet->setCellValue($cell, $row['disk_max_load']);
                        break;
                    case "M":
                        $summary_sheet->setCellValue($cell, $row['disk_avg_load']);
                        break;
                    default:
                        continue;
                }
        }

        // dive to next row
        $row_n++;
    }
    
    // limit 100 items
    if($row_n > 99) {
        break;
    }
}

// add summary sheet
$spreadsheet->addSheet($summary_sheet);

// create detail sheet
foreach($devices as $device) {
    // create detail sheet
    $detail_sheet = clone $spreadsheet->getSheetByName("Template_Detail");
    $detail_sheet->setTitle($device['computer_name']);
    
    $detail_sheet->setCellValue("D3", $end_dt); // reported datetime
    $detail_sheet->setCellValue("D7", $device['computer_name']); // server nam

    // draw detail report
    $bind = array(
        "device_id" => $device['id'],
        "start_dt" => $start_dt,
        "end_dt" => $end_dt
    );
    $sql = get_bind_to_sqlx("autoget_detail_report");
    $rows = exec_db_fetch_all($sql, $bind);

    $row_offset = 13;
    $col_offsets = range('A', 'M');
    $row_n = 0;
    foreach($rows as $row) {
        foreach($col_offsets as $col_offset) {
                $cell = sprintf("%s%s", $col_offset, $row_offset + $row_n);
                switch($col_offset) {
                    case "A":
                        $detail_sheet->mergeCells(sprintf("%s:%s", $cell, sprintf("C%s", $row_offset + $row_n)));
                        $detail_sheet->setCellValue($cell, $row['basetime']);
                        break;
                    case "D":
                        $detail_sheet->setCellValue($cell, $row['cpu_max_load']);
                        break;
                    case "E":
                        $detail_sheet->setCellValue($cell, $row['cpu_avg_load']);
                        break;
                    case "F":
                        $detail_sheet->setCellValue($cell, $row['mem_max_load']);
                        break;
                    case "G":
                        $detail_sheet->setCellValue($cell, $row['mem_avg_load']);
                        break;
                    case "H":
                        $detail_sheet->setCellValue($cell, ""); // network QTY
                        break;
                    case "I":
                        $detail_sheet->setCellValue($cell, ""); // network MAX
                        break;
                    case "J":
                        $detail_sheet->setCellValue($cell, ""); // network AVG
                        break;
                    case "K":
                        $detail_sheet->setCellValue($cell, $row['disk_qty']);
                        break;
                    case "L":
                        $detail_sheet->setCellValue($cell, $row['disk_max_load']);
                        break;
                    case "M":
                        $detail_sheet->setCellValue($cell, $row['disk_avg_load']);
                        break;
                }
        }

        // dive to next row
        $row_n++;
    }
    
    // add detail sheet
    $spreadsheet->addSheet($detail_sheet);

    break;
}

// remove template
$sheet_index_1 = $spreadsheet->getIndex($spreadsheet->getSheetByName("Template_Summary"));
$spreadsheet->removeSheetByIndex($sheet_index_1);
$sheet_index_2 = $spreadsheet->getIndex($spreadsheet->getSheetByName("Template_Detail"));
$spreadsheet->removeSheetByIndex($sheet_index_2);

// save excel file
$fw = write_storage_file("", array(
    "storage_type" => "autoget",
    "filename" => "test.xlsx",
    "mode" => "fake"
));
$writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
$writer->save($fw);

echo "Done";
