<?php

$url = isset($_GET['url']) ? $_GET['url'] : '';
$outputFormat = isset($_GET['format']) ? $_GET['format'] : 'html';

if (!empty($url)) {

    $metadata = get_metadata($url);

    if ($outputFormat === 'json') {
        header('Content-Type: application/json');
        echo json_encode($metadata);
    } else {
        echo '<img src="' . $metadata['favicon'] . '">';
        echo '<h1>' . $metadata['title'] . '</h1>';
    }
} else {
    echo "No URL provided.";
}

function get_metadata($url, $path = '../metadata-storage/') {
    $data = array();

    $html = fetch_html($url);

    $title = extract_title($html);
    $favicon = save_favicon($url, $path);

    $data['title'] = $title;
    $data['favicon'] = $favicon;

    return $data;
}

function fetch_html($url) {
    $html = file_get_contents($url);
    return $html;
}

function extract_title($html) {
    $matches = array();
    preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches);
    return isset($matches[1]) ? $matches[1] : '';
}


function save_favicon($url, $path = '../metadata-storage/') {
    $url = parse_url($url, PHP_URL_HOST);
    $file = $path . $url . '.png';
    if (!file_exists($file)) {
        $fp = fopen($file, 'w+');
        $ch = curl_init('http://www.google.com/s2/favicons?domain=' . $url);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_FILE, $fp); 
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_exec($ch);
        curl_close($ch);
        fclose($fp);
    }
    return $file;
}

?>
