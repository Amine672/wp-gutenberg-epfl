<?php

use \EPFL\Plugins\Gutenberg\Lib\Utils;

require_once (dirname(__FILE__) . '/../lib/utils.php');

header('Content-Type: application/json');

$url = "https://project-portal.epfl.ch/api/public/schools";

$response = Utils::zen_api_request($url);
if ($response === false || $response === null) {
    http_response_code(502);
    $response = array('error' => 'Unable to reach the Project Portal. Please try again later.');
}
echo json_encode($response);
