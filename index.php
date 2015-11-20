<?php
$path_to_data_file = "redirect_rules.csv";
$externals = file($path_to_data_file);
foreach ($externals as $line) {
    $line = trim($line);
    $pair = preg_split("/\|/", $line);
    if ($pair[0] == $_SERVER[HTTP_HOST]) {
        if (preg_match("/html$/", $pair[1])) {
            header("Location: $pair[1]");
        } else {
            header("Location: $pair[1]$_SERVER[REQUEST_URI]");
        }
        exit;
    }
}
