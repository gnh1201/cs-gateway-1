<?php
loadHelper("webpagetool");

$device_id = get_requested_value("device_id");
$category_id = get_requested_value("category_id");

$mode = get_requested_value("mode");
$output = get_requested_value("output");

// set datetime
$adjust = get_requested_value("adjust");
$end_dt = get_requested_value("end_dt");
$start_dt = get_requested_value("start_dt");

if(empty($adjust)) {
    $adjust = "-24h";
}

if(empty($end_dt)) {
    $end_dt = get_current_datetime();
}

if(empty($start_dt)) {
    $start_dt = get_current_datetime(array(
        "now" => $end_dt,
        "adjust" => $adjust
    ));
}

$data = array(
    "success" => false
);

if($mode == "table.insert") {
    // get active devices
    $bind = array(
        "platform" => "windows"
    );
    $sql = get_bind_to_sql_select("autoget_devices", $bind, array(
        "setwheres" => array(
            array("and", array("gte", "last", $start_dt))
        )
    ));
    $devices = exec_db_fetch_all($sql, $bind);

    foreach($devices as $device) {
        if(!empty($device_id)) {
            if($device_id != $device['id']) continue;
        }
        
        $bind = array(
            "device_id" => $device['id'],
            "start_dt" => $start_dt,
            "end_dt" => $end_dt
        );
        $sql = get_bind_to_sqlx("autoget_detail_report");
        $rows = exec_db_fetch_all($sql, $bind);
        foreach($rows as $row) {
            $net_qty = 0;
            $net_max_load = 0;
            $net_avg_load = 0;

            // get network load from zabbix
            $response = get_web_json(get_route_link("api.report.network.json"), "get", array(
                "now_dt" => $end_dt,
                "adjust" => $adjust,
                "hostips" => current(explode(",", $device['net_ip']))
            ));

            foreach($response->data as $record) {
                if($record->timekey == $row['timekey']) {
                    $net_qty = $record->qty;
                    $net_max_load = $record->max_value;
                    $net_avg_load = $record->avg_value;
                    break;
                }
            }

            // build values
            $bind = array(
                "device_id" => $row['device_id'],
                "basetime" => $row['basetime'],
                "cpu_max_load" => $row['cpu_max_load'],
                "cpu_avg_load" => $row['cpu_avg_load'],
                "mem_max_load" => $row['mem_max_load'],
                "mem_avg_load" => $row['mem_avg_load'],
                "net_qty" => $net_qty,
                "net_max_load" => $net_max_load,
                "net_avg_load" => $net_avg_load,
                "disk_qty" => $row['disk_qty'],
                "disk_max_load" => $row['disk_max_load'],
                "disk_avg_load" => $row['disk_avg_load']
            );
            
            $sql = get_bind_to_sql_insert("autoget_summaries", $bind);
            exec_db_query($sql, $bind);
        }
    }
}

if($mode == "make.excel") {
    // get active devices
    $bind = array(
        "platform" => "windows"
    );
    $sql = get_bind_to_sql_select("autoget_devices", $bind, array(
        "setwheres" => array(
            array("and", array("gte", "last", $start_dt))
        )
    ));
    $devices = exec_db_fetch_all($sql, $bind);

    // load template
    $fw = write_storage_file("", array(
        "storage_type" => "autoget",
        "filename" => "template.xlsx",
        "mode" => "fake"
    ));
    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($fw);

    // create summary sheet
    $summary_sheet = clone $spreadsheet->getSheetByName("Template_Summary");
    $summary_sheet->setTitle('Summary');

    $summary_sheet->setCellValue("D3", $end_dt); // reported datetime
    $summary_sheet->setCellValue("D7", count($devices)); // number of servers
    $summary_sheet->setCellValue("D10", $start_dt . " ~ Now"); // time range

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
        $sql = "
            select
                device_id,
                max(basetime) as basetime,
                max(cpu_max_load) as cpu_max_load,
                avg(cpu_avg_load) as cpu_avg_load,
                max(mem_max_load) as mem_max_load,
                avg(mem_avg_load) as mem_avg_load,
                max(net_qty) as net_qty,
                max(net_max_load) as net_max_load,
                avg(net_avg_load) as net_avg_load,
                max(disk_qty) as disk_qty,
                max(disk_max_load) as disk_max_load,
                avg(disk_avg_load) as disk_avg_load
            from autoget_summaries
            where device_id = :device_id and basetime >= :start_dt and basetime <= :end_dt
        ";
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
                            $summary_sheet->setCellValue($cell, $row['net_qty']); // network QTY
                            break;
                        case "I":
                            $summary_sheet->setCellValue($cell, $row['net_max_load']); // network MAX
                            break;
                        case "J":
                            $summary_sheet->setCellValue($cell,  $row['net_avg_load']); // network AVG
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
    write_common_log("summary sheet added", "api.report.json -> make.excel");
    
    // create detail sheet
    foreach($devices as $device) {
        // create detail sheet
        $detail_sheet = clone $spreadsheet->getSheetByName("Template_Detail");
        $detail_sheet->setTitle($device['computer_name']);
        
        $detail_sheet->setCellValue("D3", $end_dt); // reported datetime
        $detail_sheet->setCellValue("D7", $device['computer_name']); // server name
        $detail_sheet->setCellValue("D10", $start_dt . " ~ Now"); // time range

        // draw detail report
        $bind = array(
            "device_id" => $device['id'],
            "start_dt" => $start_dt,
            "end_dt" => $end_dt
        );
        $sql = "
            select
                device_id,
                max(basetime) as basetime,
                max(cpu_max_load) as cpu_max_load,
                avg(cpu_avg_load) as cpu_avg_load,
                max(mem_max_load) as mem_max_load,
                avg(mem_avg_load) as mem_avg_load,
                max(net_qty) as net_qty,
                max(net_max_load) as net_max_load,
                avg(net_avg_load) as net_avg_load,
                max(disk_qty) as disk_qty,
                max(disk_max_load) as disk_max_load,
                avg(disk_avg_load) as disk_avg_load,
                floor(unix_timestamp(basetime) / 15 * 60) as timekey
            from autoget_summaries
            where device_id = :device_id and basetime >= :start_dt and basetime <= :end_dt
            group by timekey order by basetime asc
        ";
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
                            $summary_sheet->setCellValue($cell, $row['net_qty']); // network QTY
                            break;
                        case "I":
                            $summary_sheet->setCellValue($cell, $row['net_max_load']); // network MAX
                            break;
                        case "J":
                            $summary_sheet->setCellValue($cell,  $row['net_avg_load']); // network AVG
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
        write_common_log("detail sheet added", "api.report.json -> make.excel");
    }
    
    // remove templates
    $sheet_index_1 = $spreadsheet->getIndex($spreadsheet->getSheetByName("Template_Summary"));
    $spreadsheet->removeSheetByIndex($sheet_index_1);
    $sheet_index_2 = $spreadsheet->getIndex($spreadsheet->getSheetByName("Template_Detail"));
    $spreadsheet->removeSheetByIndex($sheet_index_2);
    write_common_log("removed template", "api.report.json -> make.excel");
    
    // save file
    $fw = write_storage_file("", array(
        "extension" => "xlsx",
        "mode" => "fake"
    ));
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save($fw);
    write_common_log("excel saved: " . $fw, "api.report.json -> make.excel");
    
    // add to report table
    $bind = array(
        "type" => "daily",
        "device_id" => 0,
        "filename" => $fw,
        "basetime" => $end_dt
    );
    $sql = get_bind_to_sql_insert("autoget_reports", $bind);
    exec_db_query($sql, $bind);

    $data['success'] = true;
    $data['filename'] = $fw;
} else {
    $limit = 10000;
    $bind = array(
        "category_id" => $category_id
    );
    $sql = get_bind_to_sql_select("autoget_reports", $bind, array(
        "setlimit" => $limit,
        "setpage" => 1,
        "setorders" => array(
            array("desc", "basetime")
        )
    ));
    $rows = exec_db_fetch_all($sql, $bind);

    $data['success'] = true;
    $data['data'] = $rows;
}

header("Content-Type: application/json");
echo json_encode($data);
