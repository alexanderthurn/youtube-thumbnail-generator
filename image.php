<?php


# Check for url parameter, and prevent file transfer
if (isset($_GET['url']) and preg_match('#^https?://#', $_GET['url']) === 1) {
	$url = $_GET['url'];
} else {
	header('HTTP/1.1 404 Not Found');
	exit;
}

# Load secrets from .env (KEY=VALUE per line)
$envPath = __DIR__ . '/.env';
$env = [];
if (is_readable($envPath)) {
	$lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	foreach ($lines as $line) {
		$line = trim($line);
		if ($line === '' || $line[0] === '#' || $line[0] === ';') continue;
		$parts = explode('=', $line, 2);
		if (count($parts) === 2) {
			$key = trim($parts[0]);
			$val = trim($parts[1]);
			if (strlen($val) >= 2 && (($val[0] === '"' && substr($val, -1) === '"') || ($val[0] === "'" && substr($val, -1) === "'"))) {
				$val = substr($val, 1, -1);
			}
			$env[$key] = $val;
		}
	}
}
$unsplashClientId = isset($env['UNSPLASH_CLIENT_ID']) ? $env['UNSPLASH_CLIENT_ID'] : getenv('UNSPLASH_CLIENT_ID');
if (!empty($unsplashClientId)) {
	$url .= (strpos($url, '?') !== false ? '&' : '?') . 'client_id=' . urlencode($unsplashClientId);
}

# Check if the client already has the requested item
if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) or
	isset($_SERVER['HTTP_IF_NONE_MATCH'])) {
	header('HTTP/1.1 304 Not Modified');
	exit;
}

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
curl_setopt($ch, CURLOPT_BUFFERSIZE, 12800);
curl_setopt($ch, CURLOPT_NOPROGRESS, false);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/96.0.4664.110 Safari/537.36');
curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function($DownloadSize, $Downloaded, $UploadSize, $Uploaded) { return ($Downloaded > 1024 * 4096) ? 1 : 0; } ); # max 4096kb

$version = curl_version();
if ($version !==FALSE && ($version['features'] & CURL_VERSION_SSL)) { // Curl do support SSL
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
  curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
}

$response = curl_exec ($ch);
$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);



curl_close ($ch);

$header_blocks =  array_filter(preg_split('#\n\s*\n#Uis' , substr($response, 0, $header_size)));
$header_array = explode("\n", array_pop($header_blocks));

$body = substr($response, $header_size);

$headers = [];
foreach($header_array as $header_value) {
	$header_pieces = explode(':', $header_value);
	if(count($header_pieces) == 2) {
		$headers[strtolower($header_pieces[0])] = trim($header_pieces[1]);
	}
}

if (array_key_exists('content-type', $headers)) {
	$ct = $headers['content-type'];
	if (preg_match('#image/png|image/.*icon|image/jpe?g|image/gif|image/webp|image/svg\+xml#', $ct) !== 1) {
		header('HTTP/1.1 404 Not Found');
		exit;
	}
	header('Content-Type: ' . $ct);
} else {
	header('HTTP/1.1 404 Not Found');
	exit;
}

if (array_key_exists('content-length', $headers))
	header('Content-Length: ' . $headers['content-length']);
if (array_key_exists('expires', $headers))
	header('Expires: ' . $headers['expires']);
if (array_key_exists('cache-control', $headers))
	header('Cache-Control: ' . $headers['cache-control']);
if (array_key_exists('last-modified', $headers))
	header('Last-Modified: ' . $headers['last-modified']);
echo $body;
exit;

?>