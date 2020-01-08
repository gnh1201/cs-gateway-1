<?php
loadHelper("json.format");
loadHelper("JSLoader.class");

$device_id = get_requested_value("device_id");
$start_dt = get_requested_value("start_dt");
$end_dt = get_requested_value("end_dt");

$data = array();

if(empty($end_dt)) {
    $end_dt = get_current_datetime();
}

if(empty($start_dt)) {
    $start_dt = get_current_datetime(array(
        "now" => $end_dt,
        "adjust" => "-1 hour"
    ));
}

$bind = array(
    "device_id" => $device_id
);
$sql = get_bind_to_sql_select("autoget_responses", $bind, array(
    "setwheres" => array(
        array("and", array(
            array("or", array("eq", "command_id", 28)),
            array("or", array("eq", "command_id", 14))
        )),
        array("and", array("gte", "datetime", $start_dt)),
        array("and", array("lte", "datetime", $end_dt))
    )
));
$rows = exec_db_fetch_all($sql, $bind);

$_tbl1 = exec_db_temp_create(array(
    "itemname" => array("varchar", 255),
    "decision" => array("tinyint", 1),
    "detail" => array("text"),
    "datetime" => array("datetime")
));

foreach($rows as $row) {
    $severities = array("N/A", "양호", "수동", "인터뷰", "취약");
    $pos = strpos($row['response'], "<Group>");
    if($pos !== false) {
        $raw_content = substr($row['response'], $pos);
        $xml = simplexml_load_string($raw_content);
        foreach($xml->Items as $item) {
            $bind = array(
                "itemname" => $item->Item,
                "decision" => array_search($item->decision, $severities),
                "detail" => $item->Detail,
                "datetime" => $row['datetime']
            );
            $sql = get_bind_to_sql_insert($_tbl1, $bind);
            exec_db_query($sql, $bind);
        }
    }
}
$sql = get_bind_to_sql_select($_tbl1);
$rows = exec_db_fetch_all($sql);

$_map0 = array();
foreach($rows as $row) {
    $_map0[] = array(
        "id" => get_hashed_text($row['itemname'] . $row['datetime']),
        "group" => get_hashed_text($row['itemname']),
        "content" => $row['decision'],
        "start" => substr($row['datetime'], 0, 10)
    );
}
$data['map0'] = write_storage_file(json_encode_ex($_map0), array(
    "storage_type" => "temp",
    "url" => true,
    "extension" => "json"
));

$_tbl0 = array();
$_tbl0_itemnames = array();
foreach($rows as $row) {
    $_tbl0[] = array(
        "rowid" => get_hashed_text($row['itemname'] . $row['datetime']),
        "itemname" => $row['itemname'],
        "decision" => $row['decision'],
        "detail" => $row['detail'],
        "datetime" => $row['datetime']
    );
    if(!in_array($row['itemname'], $_tbl0_itemnames)) {
        $_tbl0_itemnames[] = $row['itemname'];
    }
}
$data['tbl0'] = write_storage_file(json_encode_ex(array(
    "data" => $_tbl0
)), array(
    "storage_type" => "temp",
    "url" => true,
    "extension" => "json"
));

// make javascript content
$base_url = base_url();
$jscontent = <<<EOF
    $("<link/>").attr({
        "type": "text/css",
        "rel": "stylesheet",
        "href": "{$base_url}vendor/_dist/apexcharts/dist/apexcharts.css"
    }).appendTo("head");
/*
    $("#tbl0").DataTable({
        "ajax": "{$data['tbl0']}",
        "rowId": "rowid",
        "pageLength": 100,
        "columns": [
            {"data": "itemname"},
            {"data": "decision"},
            {"data": "datetime"}
        ]
    });
*/
    // draw chart
    $.get("{$data['tbl0']}", function(res) {
        var generateData = function(itemname) {
            var count = 10;
            var data = res.data;
            var series = [];

            var i = 0;
            for(var k in data) {
                if(data[k].itemname == itemname) {
                    var x = (i + 1).toString();
                    var y = data[k].decision;
                    var z = '<textarea style="width: 500px; height: 300px; border: 0;">' + data[k].detail + '</textarea>';
                    series.push({
                        x: x,
                        y: y,
                        z: z
                    });
                    i++;
                }
            }

            for(var k = i; k < count; k++) {
                var x = (i + 1).toString();
                var y = 0;
                series.push({
                    x: x,
                    y: y
                });
                i++;
            }

            return series;
         }

    var options = {
      chart: {
	height: 2048,
	type: 'heatmap',
      },
      plotOptions: {
	heatmap: {
	  shadeIntensity: 0.5,

	  colorScale: {
	    ranges: [{
                from: -1,
                to: 0,
                name: 'N/A',
                color: '#888888'
              },
              {
	        from: 1,
	        to: 1,
	        name: 'Safe',
	        color: '#00A100'
	      },
	      {
	        from: 2,
	        to: 2,
	        name: 'Info`',
	        color: '#128FD9'
	      },
	      {
	        from: 3,
	        to: 3,
	        name: 'Warning',
	        color: '#FFB200'
	      },
	      {
	        from: 4,
	        to: 4,
	        name: 'Critical',
	        color: '#FF0000'
	      }
	    ]
	  }
	}
      },
      dataLabels: {
	enabled: false
      },
      series: [

EOF;

foreach($_tbl0_itemnames as $itemname) {
    $jscontent .= <<<EOF
	{
	  name: '$itemname',
	  data: generateData('$itemname')
	},

EOF;
}

$jscontent .= <<<EOF
      ],
      title: {
	text: 'Security status'
      },
      tooltip: {
          /*z: {
              formatter: function(value, a, b, c) {
                  return value;
              }
          }*/
      }
    }

	var chart = new ApexCharts(document.querySelector("#map0"), options);
	chart.render();
    }, "json");
EOF;
$jsloader = new JSLoader();
$jsloader->add_scripts(base_url() . "/vendor/_dist/apexcharts/dist/apexcharts.min.js");
$jsloader->add_scripts(write_storage_file($jscontent, array(
    "storage_type" => "temp",
    "url" => true,
    "extension" => "js"
)));
$data['jsoutput'] = $jsloader->get_output();

renderView("view_security.timeline", $data);

