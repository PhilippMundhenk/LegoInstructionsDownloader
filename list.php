<html>
<head>
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

$items = glob($directory . '/*');
foreach ($items as $item) {
        echo '<div class="divTableRow">';
        if (is_dir($item)) {
                $set_id=str_replace("/downloads/", "", $item);
                echo "<div class='divTableCell'>{$set_id}\n</div>";
        }
        echo '<div class="divTableCell">';
        $files = glob($item . '/*');
        foreach ($files as $file) {
                if (is_file($file)) {
                        if (str_contains($file, "rod")) {
                                echo "<img src=$file />";
                                break;
                        }
                }
        }
        echo '</div>';
        echo '</div>';
}
?>

<div class="divTableCell"></div>
<div class="divTableCell"></div>
</div>
</div>
</div>
</body>
</html>