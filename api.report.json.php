<?php
loadHelper("webpagetool");
loadHelper("itsm.api");

$device_id = get_requested_value("device_id");
$category_id = get_requested_value("category_id");

$planner = get_requested_value("planner"); // daily, weekly, monthly

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
        //if(!empty($device_id) && $device_id != $device['id'])  continue;
        if(!empty($device['disabled'])) continue;

        $bind = array(
            "device_id" => $device['id'],
            "start_dt" => $start_dt,
            "end_dt" => $end_dt
        );
        $sql = get_bind_to_sqlx("autoget_detail_report");        
        $rows = exec_db_fetch_all($sql, $bind);
        foreach($rows as $row) {
            // build values
            $bind = array(
                "device_id" => $row['device_id'],
                "basetime" => $row['basetime'],
                "cpu_max_load" => $row['cpu_max_load'],
                "cpu_avg_load" => $row['cpu_avg_load'],
                "mem_max_load" => $row['mem_max_load'],
                "mem_avg_load" => $row['mem_avg_load'],
                "net_qty" => $row['net_qty'],
                "net_max_load" => $row['net_max_load'],
                "net_avg_load" => $row['net_avg_load'],
                "disk_qty" => $row['disk_qty'],
                "disk_max_load" => $row['disk_max_load'],
                "disk_avg_load" => $row['disk_avg_load']
            );
            
            $sql = get_bind_to_sql_insert("autoget_summaries", $bind);
            exec_db_query($sql, $bind);
        }
    }
} elseif($mode == "make.excel") {
    // check planner
    if(empty($planner)) {
        set_error("planner is required");
        show_errors();
    }
    
    // get clients information
    $clientcategories = itsm_get_data("clientcategories");
    $clients = itsm_get_data("clients");
    $assets = itsm_get_data("assets");

    foreach($clientcategories as $clientcategory) {
        $client_category_id = $clientcategory->id;
        $client_category_name = $clientcategory->name;
        
        // get client IDs
        $clientids = array();
        foreach($clients as $client) {
            $_categories = explode(",", $client->category);
            if(in_array($client_category_id, $_categories)) {
                $clientids[] = $client->id;
            }
        }

        // get asset UUIDs
        $assetuuids = array();
        foreach($assets as $asset) {
            if(in_array($asset->clientid, $clientids)) {
                $assetuuids[] = get_property_value("102", $asset->customfields, "");
            }
        }

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

        $summary_sheet->setCellValue("A1", ucfirst($planner) . " Server Report"); // set title
        $summary_sheet->setCellValue("D3", $end_dt); // reported datetime
        $summary_sheet->setCellValue("D6", $client_category_name); // group name
        $summary_sheet->setCellValue("D7", count($devices)); // number of servers
        $summary_sheet->setCellValue("D10", $start_dt . " ~ Now"); // time range

        $row_offset = 13;
        $col_offsets = range('A', 'M');
        $row_n = 0;
        foreach($devices as $device) {
            // except not matched device
            if(!in_array($device['uuid'], $assetuuids)) continue;
            if(!empty($device['disabled'])) continue;

            // draw summary report
            $bind = array(
                "device_id" => $device['id'],
                "start_dt" => $start_dt,
                "end_dt" => $end_dt,
                "start_time" => "08:00:00",
                "end_time" => "18:00:00"
            );
            
            // SQL by planner
            $sql = "";
            switch($planner) {
                case "daily":
                    $sql = "
                        select
                            device_id,
                            max(basetime) as basetime,
                            round(max(cpu_max_load), 2) as cpu_max_load,
                            round(avg(cpu_avg_load), 2) as cpu_avg_load,
                            round(max(mem_max_load), 2) as mem_max_load,
                            round(avg(mem_avg_load), 2) as mem_avg_load,
                            max(net_qty) as net_qty,
                            round(max(net_max_load), 2) as net_max_load,
                            round(avg(net_avg_load), 2) as net_avg_load,
                            max(disk_qty) as disk_qty,
                            round(max(disk_max_load), 2) as disk_max_load,
                            round(avg(disk_avg_load), 2) as disk_avg_load
                        from autoget_summaries
                        where device_id = :device_id and basetime >= :start_dt and basetime <= :end_dt
                    ";
                    break;
                    
                case "weekly":
                case "monthly":
                    $sql = "
                        select
                            device_id,
                            max(basetime) as basetime,
                            round(max(cpu_max_load), 2) as cpu_max_load,
                            round(avg(cpu_avg_load), 2) as cpu_avg_load,
                            round(max(mem_max_load), 2) as mem_max_load,
                            round(avg(mem_avg_load), 2) as mem_avg_load,
                            max(net_qty) as net_qty,
                            round(max(net_max_load), 2) as net_max_load,
                            round(avg(net_avg_load), 2) as net_avg_load,
                            max(disk_qty) as disk_qty,
                            round(max(disk_max_load), 2) as disk_max_load,
                            round(avg(disk_avg_load), 2) as disk_avg_load
                        from autoget_summaries
                        where
                                   device_id = :device_id
                            and basetime >= :start_dt
                            and basetime <= :end_dt
                            and time(basetime) >= :start_time
                            and time(basetime) <= :end_time
                            and dayofweek(date(basetime)) not in (1, 7)
                    ";
                    break;

                default:
                    continue;
            }
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
                                $summary_sheet->setCellValue($cell, $row['net_qty']);
                                break;
                            case "I":
                                $summary_sheet->setCellValue($cell, $row['net_max_load']);
                                break;
                            case "J":
                                $summary_sheet->setCellValue($cell,  $row['net_avg_load']);
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
            // except not matched device
            if(!in_array($device['uuid'], $assetuuids)) {
                    continue;
            }

            // create detail sheet
            $detail_sheet = clone $spreadsheet->getSheetByName("Template_Detail");
            $detail_sheet->setTitle($device['computer_name']);
            
            $detail_sheet->setCellValue("D3", $end_dt); // reported datetime
            $detail_sheet->setCellValue("D6", $client_category_name); // group name
            $detail_sheet->setCellValue("D7", $device['computer_name']); // server name
            $detail_sheet->setCellValue("D10", $start_dt . " ~ Now"); // time range

            // draw detail report
            $bind = array(
                "device_id" => $device['id'],
                "start_dt" => $start_dt,
                "end_dt" => $end_dt,
                "start_time" => "08:00:00",
                "end_time" => "18:00:00"
            );

            // SQL by planner
            $sql = "";
            switch($planner) {
                case "daily":
                    $sql = "
                        select
                            device_id,
                            max(basetime) as basetime,
                            round(max(cpu_max_load), 2) as cpu_max_load,
                            round(avg(cpu_avg_load), 2) as cpu_avg_load,
                            round(max(mem_max_load), 2) as mem_max_load,
                            round(avg(mem_avg_load), 2) as mem_avg_load,
                            max(net_qty) as net_qty,
                            round(max(net_max_load), 2) as net_max_load,
                            round(avg(net_avg_load), 2) as net_avg_load,
                            max(disk_qty) as disk_qty,
                            round(max(disk_max_load), 2) as disk_max_load,
                            round(avg(disk_avg_load), 2) as disk_avg_load,
                            floor(unix_timestamp(max(basetime)) / 15 * 60) as timekey
                        from autoget_summaries
                        where device_id = :device_id and basetime >= :start_dt and basetime <= :end_dt
                        group by timekey order by basetime asc
                    ";
                    break;

                case "weekly":
                case "monthly":
                    $sql = "
                        select
                            device_id,
                            max(basetime) as basetime,
                            round(max(cpu_max_load), 2) as cpu_max_load,
                            round(avg(cpu_avg_load), 2) as cpu_avg_load,
                            round(max(mem_max_load), 2) as mem_max_load,
                            round(avg(mem_avg_load), 2) as mem_avg_load,
                            max(net_qty) as net_qty,
                            round(max(net_max_load), 2) as net_max_load,
                            round(avg(net_avg_load), 2) as net_avg_load,
                            max(disk_qty) as disk_qty,
                            round(max(disk_max_load), 2) as disk_max_load,
                            round(avg(disk_avg_load), 2) as disk_avg_load,
                            concat(week(basetime), '-', dayofweek(date(basetime))) as timekey
                        from autoget_summaries
                        where
                                   device_id = :device_id
                            and basetime >= :start_dt
                            and basetime <= :end_dt
                            and time(basetime) >= :start_time
                            and time(basetime) <= :end_time
                            and dayofweek(date(basetime)) not in (1, 7)
                        group by timekey order by basetime asc
                    ";
                    break;
                    
                default:
                    continue;
            }
            
            $rows = exec_db_fetch_all($sql, $bind);
            $row_offset = 13;
            $col_offsets = range('A', 'M');
            $row_n = 0;
            foreach($rows as $row) {
                foreach($col_offsets as $col_offset) {
                        $cell = sprintf("%s%s", $col_offset, $row_offset + $row_n);
                        switch($col_offset) {
                            case "A":
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
                                $detail_sheet->setCellValue($cell, $row['net_qty']);
                                break;
                            case "I":
                                $detail_sheet->setCellValue($cell, $row['net_max_load']);
                                break;
                            case "J":
                                $detail_sheet->setCellValue($cell,  $row['net_avg_load']);
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
                            default:
                                continue;
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
            "type" => $planner,
            "category_id" => $client_category_id,
            "filename" => $fw,
            "basetime" => $end_dt,
            "datetime" => get_current_datetime()
        );
        $sql = get_bind_to_sql_insert("autoget_reports", $bind);
        exec_db_query($sql, $bind);
    }

    $data['success'] = true;
    $data['filename'] = $fw;
} else {
    $bind = array(
        "category_id" => $category_id
    );
    $sql = get_bind_to_sql_select("autoget_reports", $bind, array(
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
