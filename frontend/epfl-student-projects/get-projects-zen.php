<?php

use \EPFL\Plugins\Gutenberg\Lib\Utils;

require_once (dirname(__FILE__) . '/../lib/utils.php');

header('Content-Type: application/json');

// Used by the editor to preview the projects matching the block settings.
// Exactly one of "sciper", "unit", "tags" or "school" is expected.
$sciper = isset($_GET['sciper']) ? preg_replace('/[^0-9,]/', '', $_GET['sciper']) : '';
$unit = isset($_GET['unit']) ? preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['unit']) : '';
$school = isset($_GET['school']) ? preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['school']) : '';
$tags = isset($_GET['tags']) ? array_values(array_filter(array_map('trim', explode(',', $_GET['tags'])), 'strlen')) : array();
$archived = (isset($_GET['archived']) && $_GET['archived'] === '1') ? '/archived' : '';

$base = "https://project-portal.epfl.ch/api/public/projects";

if ($sciper !== '') {
    $url = $base . "/manager/" . trim($sciper, ',') . $archived;
} else if ($unit !== '') {
    $url = $base . "/unit/" . rawurlencode($unit) . $archived;
} else if (!empty($tags)) {
    // No archived variant exists for tags
    $url = $base . "/tag/" . implode(',', array_map('rawurlencode', $tags));
} else if ($school !== '') {
    // No archived variant exists for schools
    $url = $base . "/school/" . rawurlencode($school);
} else {
    echo json_encode([]);
    return;
}

$response = Utils::zen_api_request($url);
if ($response === false || $response === null) {
    http_response_code(502);
    $response = array('error' => 'Unable to reach the Project Portal. Please try again later.');
}
echo json_encode($response);
