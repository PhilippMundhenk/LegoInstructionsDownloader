<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="main.css">
</head>
<body>
<div class="divTable" style="width: 100%;border: 1px solid #000;" >
<div class="divTableBody">
<div class="divTableRow">
<div class="divTableCell">Set number</div>
<div class="divTableCell">Set image</div>
<div class="divTableCell">Set name</div>
<div class="divTableCell">Instructions</div>
</div>

<?php
$directory = '/downloads';
$locale="de-de";

$items = glob($directory . '/*');
foreach ($items as $item) {
        $set_id=0;
        echo '<div class="divTableRow">'."\n";
        if (is_dir($item)) {
                $set_id=str_replace("/downloads/", "", $item);
                echo "<div class='divTableCell'>{$set_id}</div>\n";
        }
        $json="";
        $files = glob($item . '/*');
        foreach ($files as $file) {
                if (str_ends_with($file, "data.json")) {
                        $json=json_decode(file_get_contents($file), true);
                }
        }

        echo '<div class="divTableCell">';
        echo "<img src='".$item."/".basename($json["hits"]["hits"][0]["_source"]["assets"][0]["assetFiles"][0]["url"])."' />";
        echo "</div>\n";

        echo '<div class="divTableCell">';
        echo $json["hits"]["hits"][0]["_source"]["locale"][$locale]["display_title"];
        echo "</div>\n";

        echo '<div class="divTableCell">';
        $instructions=$json["hits"]["hits"][0]["_source"]["product_versions"][0]["building_instructions"];
        foreach ($instructions as $instr) {
                echo "<a href=".$item."/".basename($instr["file"]["url"])."><img src=\"".$item."/".basename($instr["image"]["url"])."\" /></a>";
                if (next($instructions)) {
                        echo "<br/><br/>";
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