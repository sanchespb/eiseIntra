<?php
include("common/auth.php");
header("Cache-Control: no-cache, must-revalidate"); // HTTP/1.1
header("Expires: Mon, 26 Jul 1997 05:00:00 GMT"); // Date in the past
header("Content-type: application/json");
include eiseIntraAbsolutePath."inc_ajax_details.php";