<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="main.css">
</head>
<body>
<div class="divTable" style="width: 100%;border: 1px solid #000;" >
<div class="divTableBody">
<div class="divTableRow">
<div class="divTableCell">Set</div>
<div class="divTableCell">Instructions</div>
</div>

<?php
$directory = '/downloads';
$locale="de-de";

$items = glob($directory . '/*');
foreach ($items as $item) {
        $set_id=0;
        if (is_dir($item)) {
                $set_id=str_replace("/downloads/", "", $item);
        } else {
			continue;
		}
        $json="";
        $files = glob($item . '/*');
	$version = 0;
        foreach ($files as $file) {
                if (str_ends_with($file, "data.json")) {
                        $json=json_decode(file_get_contents($file), true);
			$version=1;
                }
		if (str_ends_with($file, "name.txt")) {
			$version=2;
		}
        }

        echo '<div class="divTableRow">'."\n";
        echo '<div class="divTableCell">';
	if ($version == 1) {
        	$image_url=$item."/".basename($json["hits"]["hits"][0]["_source"]["assets"][0]["assetFiles"][0]["url"]);
	        if ($image_url=="") {
	                $image_url=$item."/".basename($json["hits"]["hits"][0]["_source"]["locale"][$locale]["additional_data"]["primary_image_grownups"]["url"]);
	        }
	} elseif ($version == 2) {
		foreach ($files as $file) {
			if(str_contains($file, '_Prod')) {
				$image_url=$file;
			}
		}
	}
        echo "<img src='".$image_url."' />";
        echo "<br/><br/>";
	if ($version == 1) {
	        echo $json["hits"]["hits"][0]["_source"]["locale"][$locale]["display_title"];
	} elseif ($version == 2) {
		echo file_get_contents($item . '/name.txt');
	}
	echo " ($set_id)";
        echo "</div>\n";

        echo '<div class="divTableCell">';
	if ($version == 1) {
		$instructions=$json["hits"]["hits"][0]["_source"]["product_versions"][0]["building_instructions"];
		usort($instructions, function($a, $b){
			if($a["sequence"]["element"]>=$b["sequence"]["element"]){
				return true;
			} else {
				return false;
			}
       		});
		foreach ($instructions as $instr) {
	                echo "<a href=".$item."/".basename($instr["file"]["url"])."><img src=\"".$item."/".basename($instr["image"]["url"])."\" /></a>";
	                if (next($instructions)) {
	                        echo "<br/><br/>";
                	}
       		}
	} elseif ($version == 2) {
		foreach ($files as $file) {
			if (str_ends_with($file, ".pdf")) {
				$image = str_replace(".pdf", ".png", $file);
				echo "<a href=".$file."><img src=\"".$image."\" /></a>";
			}
		}
	}
        
        echo "</div>\n";
        echo "</div>\n";
}
?>

</div>
</div>
</body>
</html>
