<?php
require_once ('vendor/_dist/jpgraph/src/jpgraph.php');

$type = get_requested_value("type", array("_JSON", "_ALL"));
$plot = get_requested_value("plot", array("_JSON", "_ALL"));
$title = get_requested_value("title", array("_JSON", "_ALL"));
$data = get_requested_value("data", array("_JSON"));

if(empty($plot)) {
    $plot = "line";
}

if($plot == "line") {
    require_once ('vendor/_dist/jpgraph/src/jpgraph_line.php');
} elseif($plot == "bar") {
    require_once ('vendor/_dist/jpgraph/src/jpgraph_bar.php');
} else {
    set_error("Not supported plot");
    show_errors();
}

$gdata = array(
    "max" => array(),
    "avg" => array() 
);
$glabels = array();

$row_n = 1;
foreach($data as $item) {
    switch($type) {
        case "cpu":
            $gdata['max'][] = $item->cpu_max_load;
            $gdata['avg'][] = $item->cpu_avg_load;
            break;
        case "mem":
            $gdata['max'][] = $item->mem_max_load;
            $gdata['avg'][] = $item->mem_avg_load;
            break;
        case "net":
            $gdata['max'][] = $item->net_max_load;
            $gdata['avg'][] = $item->net_avg_load;
            break;
        case "disk":
            $gdata['max'][] = $item->disk_max_load;
            $gdata['avg'][] = $item->disk_avg_load;
            break;
    }

    if($plot == "line") {
        $glabels[] = substr($item->basetime, 11, 5);
    } elseif($plot == "bar") {
        $_bind  = array(
            "id" => $item->device_id
        );
        $_sql = get_bind_to_sql_select("autoget_devices", $_bind);
        $_device = exec_db_fetch($_sql, $_bind);
        $glabels[] = get_value_in_array("computer_name", $_device, "Noname");
    }

    $row_n++;
}

// Create the graph. These two calls are always required
$w = 640;
$h = 320;
if(count($gdata['max']) > 25) {
	$w = $w * 2;
	$h = $h * 2;
}
$graph = new Graph($w, $h, 'auto');
if(in_array($type, array("cpu", "mem", "disk"))) {
    $graph->SetScale("textlin", 0, 100);
} else {
    $graph->SetScale("textlin");
}

$theme_class=new UniversalTheme;
$graph->SetTheme($theme_class);

//$graph->yaxis->SetTickPositions(array(0,30,60,90,120,150), array(15,45,75,105,135));
$graph->SetBox(false);

$graph->ygrid->SetFill(false);
$graph->xaxis->SetTickLabels($glabels);
$graph->xaxis->SetLabelAngle(50);
$graph->yaxis->HideLine(false);
$graph->yaxis->HideTicks(false);

if($plot == "line") {
    // Create the line plots
    $colors = array("#cc1111", "#11cccc", "#1111cc");
    foreach($gdata as $k=>$v) {
        $plot = new LinePlot($v);
        $plot->setLegend($k);
        $plot->SetColor(next($colors));
        $graph->Add($plot);
    }

    // set legend position
    $graph->legend->SetPos(0.0, 0.0, "right", "top");
    $graph->img->SetTransparent("white");
}

if($plot == "bar") {
    // Create the bar plots
    $gplots = array();
    $colors = array("#cc1111", "#11cccc", "#1111cc");
    foreach($gdata as $k=>$v) {
        $plot = new BarPlot($v);
        $plot->setLegend($k);
        $plot->SetColor("white");
        $plot->SetFillColor(next($colors));
        $gplots[] = $plot;
    }

    // Create the grouped bar plot
    $gbplot = new GroupBarPlot($gplots);
    // ...and add it to the graPH
    $graph->legend->SetPos(0.0,0.0,'right','top');
    //$graph->xaxis->SetFont(FF_DEFAULT, FS_NORMAL, 5);
    $graph->img->SetTransparent("white");
    $graph->Add($gbplot);
}

/*
$b1plot->SetColor("white");
$b1plot->SetFillColor("#cc1111");

$b2plot->SetColor("white");
$b2plot->SetFillColor("#11cccc");

$b3plot->SetColor("white");
$b3plot->SetFillColor("#1111cc");
*/

if(empty($title)) {
    $graph->title->Set($type);
} else {
    $graph->title->Set($title);
}

// Display the graph
$graph->Stroke();
