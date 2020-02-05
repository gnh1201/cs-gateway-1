<?php
require_once ('vendor/_dist/jpgraph/src/jpgraph.php');
require_once ('vendor/_dist/jpgraph/src/jpgraph_line.php');

$type = get_requested_value("type", array("_JSON", "_ALL"));
$data = get_requested_value("data", array("_JSON"));

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
    $glabels[] = substr($item->basetime, 11, 5);
    $row_n++;
}

// Create the graph. These two calls are always required
$graph = new Graph(640, 320, 'auto');
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

// Create the line plots
$colors = array("#cc1111", "#11cccc", "#1111cc");
foreach($gdata as $k=>$v) {
    $plot = new LinePlot($v);
    $plot->setLegend($k);
    $plot->SetColor(next($colors));
    $graph->Add($plot);
}

// set legend posision
$graph->legend->SetPos(0.0, 0.0, "right", "top");
$graph->img->SetTransparent("white");

/*
// Create the bar plots
$gplots = array();
$colors = array("#cc1111", "#11cccc", "#1111cc");
foreach($gdata as $k=>$v) {
    $plot = new BarPlot($v);
    $plot->setLegend($k);
    $plot->SetFillColor(next($colors));
    $gplots[] = $plot;
}

// Create the grouped bar plot
$gbplot = new GroupBarPlot($gplots);
// ...and add it to the graPH
$graph->legend->SetPos(0.0,0.0,'right','top');
$graph->img->SetTransparent("white");
$graph->Add($gbplot);
*/

/*
$b1plot->SetColor("white");
$b1plot->SetFillColor("#cc1111");

$b2plot->SetColor("white");
$b2plot->SetFillColor("#11cccc");

$b3plot->SetColor("white");
$b3plot->SetFillColor("#1111cc");
*/

$graph->title->Set($type);

// Display the graph
$graph->Stroke();
