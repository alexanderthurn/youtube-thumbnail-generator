<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$root = __DIR__;

// Handle POST actions (delete, save_result)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    if ($action === 'delete') {
        $type = isset($_POST['type']) ? $_POST['type'] : '';
        $file = isset($_POST['file']) ? $_POST['file'] : '';

        // Only allow specific types
        if (!in_array($type, ['pose', 'background', 'object', 'result'], true)) {
            echo json_encode(['success' => false, 'error' => 'Invalid type']);
            exit;
        }

        // Only allow safe basenames within expected folders
        $basename = basename($file);
        if ($type === 'result') {
            if (!preg_match('/^[A-Za-z0-9._-]+\.(png|jpg|jpeg)$/i', $basename)) {
                echo json_encode(['success' => false, 'error' => 'Invalid filename']);
                exit;
            }
        } else {
            if (!preg_match('/^[A-Za-z0-9._-]+\.png$/i', $basename)) {
                echo json_encode(['success' => false, 'error' => 'Invalid filename']);
                exit;
            }
        }

        if ($type === 'pose') {
            $original = $root . '/img/gemini/poses/' . $basename;
            $thumb = $root . '/img/gemini/poses_thumbs/' . $basename;
            $json = null;
        } else if ($type === 'background') {
            $original = $root . '/img/gemini/backgrounds/' . $basename;
            $thumb = $root . '/img/gemini/backgrounds_thumbs/' . $basename;
            $json = null;
        } else if ($type === 'object') {
            $original = $root . '/img/gemini/objects/' . $basename;
            $thumb = $root . '/img/gemini/objects_thumbs/' . $basename;
            $json = null;
        } else { // result
            $original = $root . '/img/results/' . $basename;
            $thumb = $root . '/img/results_thumbs/' . $basename;
            $baseNoExt = preg_replace('/\.[A-Za-z0-9]+$/', '', $basename);
            $json = $root . '/img/results/' . $baseNoExt . '.json';
        }

        $ok = true;
        // Attempt to delete, ignoring if missing
        $paths = [$original, $thumb];
        if (!empty($json)) { $paths[] = $json; }
        foreach ($paths as $p) {
            if (file_exists($p)) {
                if (!@unlink($p)) { $ok = false; }
            }
        }

        echo json_encode(['success' => $ok]);
        exit;
    } else if ($action === 'save_result') {
        // Save a composed canvas image and its settings JSON
        $root = __DIR__;
        $resultsDir = $root . '/img/results';
        $resultsThumbsDir = $root . '/img/results_thumbs';
        if (!is_dir($resultsDir)) { @mkdir($resultsDir, 0777, true); }
        if (!is_dir($resultsThumbsDir)) { @mkdir($resultsThumbsDir, 0777, true); }

        $filename = isset($_POST['filename']) ? trim($_POST['filename']) : '';
        $settings = isset($_POST['settings']) ? $_POST['settings'] : '';

        // Validate settings JSON (optional)
        $settingsObj = null;
        if ($settings !== '') {
            $tmp = json_decode($settings, true);
            if (json_last_error() === JSON_ERROR_NONE) { $settingsObj = $tmp; }
        }

        // Expect an uploaded file field named 'image'
        if (!isset($_FILES['image']) || !is_uploaded_file($_FILES['image']['tmp_name'])) {
            echo json_encode(['success' => false, 'error' => 'Missing image upload']);
            exit;
        }
        $tmpPath = $_FILES['image']['tmp_name'];
        $mime = @mime_content_type($tmpPath) ?: 'image/jpeg';
        $ext = '.jpg';
        if (stripos($mime, 'png') !== false) { $ext = '.png'; }
        else if (stripos($mime, 'jpeg') !== false || stripos($mime, 'jpg') !== false) { $ext = '.jpg'; }
        else if (stripos($mime, 'webp') !== false) { $ext = '.jpg'; }

        // Build unique base name
        $base = preg_replace('/\.[A-Za-z0-9]+$/', '', basename($filename));
        if ($base === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $base)) {
            $base = 'result';
        }
        $bytes = @file_get_contents($tmpPath) ?: '';
        $hash = substr(sha1($bytes . '|' . $settings), 0, 10);
        $ts = date('Ymd-His');
        $finalBase = $base . '--' . $ts . '--' . $hash;
        $finalImage = $resultsDir . '/' . $finalBase . $ext;
        $finalJson = $resultsDir . '/' . $finalBase . '.json';
        $finalThumb = $resultsThumbsDir . '/' . $finalBase . $ext;

        if (!@move_uploaded_file($tmpPath, $finalImage)) {
            // fallback copy
            @copy($tmpPath, $finalImage);
        }
        // Write JSON settings
        $meta = [
            'createdAt' => gmdate('c'),
            'filename' => basename($finalImage),
            'mime' => $mime,
            'settings' => ($settingsObj !== null ? $settingsObj : $settings)
        ];
        @file_put_contents($finalJson, json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // Create thumbnail (max width 256)
        $bin = @file_get_contents($finalImage);
        if ($bin !== false && function_exists('imagecreatefromstring')) {
            $src = @imagecreatefromstring($bin);
            if ($src !== false) {
                $w = imagesx($src); $h = imagesy($src);
                if ($w > 0 && $h > 0) {
                    $tw = min(256, $w);
                    $th = (int)round($tw * $h / $w);
                    $dst = imagecreatetruecolor($tw, $th);
                    imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
                    if ($ext === '.png') { @imagepng($dst, $finalThumb); }
                    else { @imagejpeg($dst, $finalThumb, 90); }
                    imagedestroy($dst);
                }
                imagedestroy($src);
            }
        }

        echo json_encode([
            'success' => true,
            'url' => to_web_path($finalImage),
            'thumb' => to_web_path($finalThumb),
            'json' => to_web_path($finalJson)
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function list_files($pattern) {
    $files = glob($pattern);
    if (!$files) return [];
    // Sort newest first
    usort($files, function($a,$b){ return filemtime($b) <=> filemtime($a); });
    return $files;
}

function to_web_path($abs) {
    // Paths are served relative to this script location
    $rel = str_replace('\\', '/', $abs);
    return str_replace(str_replace('\\','/', __DIR__) . '/', '', $rel);
}

// Normal poses thumbnails
$normalThumbsDir = $root . '/img/poses_thumbs';
$normalThumbs = list_files($normalThumbsDir . '/*.png');
$normalPoses = [];
foreach ($normalThumbs as $t) {
    $bn = basename($t);
    if (preg_match('/^(\d+)\.png$/', $bn, $m)) {
        $idx = (int)$m[1];
        $normalPoses[] = [
            'index' => $idx,
            'thumb' => to_web_path($t),
            'full'  => 'img/poses/' . $bn
        ];
    }
}
// Sort by index ascending
usort($normalPoses, function($a,$b){ return $a['index'] <=> $b['index']; });

// Gemini generated poses
$gemPosesDir = $root . '/img/gemini/poses';
$gemPosesThumbsDir = $root . '/img/gemini/poses_thumbs';
@mkdir($gemPosesDir, 0777, true);
@mkdir($gemPosesThumbsDir, 0777, true);
$gemPoseFiles = list_files($gemPosesDir . '/*.png');
$generatedPoses = [];
foreach ($gemPoseFiles as $f) {
    $bn = basename($f); // e.g., pose12-a-nice-hat--abc1234567.png
    $prompt = $bn;
    $hash = '';
    if (preg_match('/^(?:pose\d+-)?(.+?)--([a-f0-9]{10})\.png$/i', $bn, $m)) {
        $prompt = str_replace('-', ' ', $m[1]);
        $hash = $m[2];
    } else {
        $prompt = preg_replace('/\.png$/i', '', $prompt);
        $prompt = str_replace('-', ' ', $prompt);
    }
    $generatedPoses[] = [
        'url'   => to_web_path($f),
        'thumb' => file_exists($gemPosesThumbsDir . '/' . $bn) ? to_web_path($gemPosesThumbsDir . '/' . $bn) : to_web_path($f),
        'prompt'=> $prompt,
        'hash'  => $hash,
    ];
}

// Gemini generated backgrounds
$gemBgDir = $root . '/img/gemini/backgrounds';
$gemBgThumbsDir = $root . '/img/gemini/backgrounds_thumbs';
@mkdir($gemBgDir, 0777, true);
@mkdir($gemBgThumbsDir, 0777, true);
$gemBgFiles = list_files($gemBgDir . '/*.png');
$generatedBackgrounds = [];
foreach ($gemBgFiles as $f) {
    $bn = basename($f); // e.g., starry-night--abc1234567.png
    $prompt = $bn;
    $hash = '';
    if (preg_match('/^(.+?)--([a-f0-9]{10})\.png$/i', $bn, $m)) {
        $prompt = str_replace('-', ' ', $m[1]);
        $hash = $m[2];
    } else {
        $prompt = preg_replace('/\.png$/i', '', $prompt);
        $prompt = str_replace('-', ' ', $prompt);
    }
    $generatedBackgrounds[] = [
        'url'   => to_web_path($f),
        'thumb' => file_exists($gemBgThumbsDir . '/' . $bn) ? to_web_path($gemBgThumbsDir . '/' . $bn) : to_web_path($f),
        'prompt'=> $prompt,
        'hash'  => $hash,
    ];
}

// Gemini generated objects
$gemObjDir = $root . '/img/gemini/objects';
$gemObjThumbsDir = $root . '/img/gemini/objects_thumbs';
@mkdir($gemObjDir, 0777, true);
@mkdir($gemObjThumbsDir, 0777, true);
$gemObjFiles = list_files($gemObjDir . '/*.png');
$generatedObjects = [];
foreach ($gemObjFiles as $f) {
    $bn = basename($f);
    $prompt = $bn;
    $hash = '';
    if (preg_match('/^(.+?)--([a-f0-9]{10})\.png$/i', $bn, $m)) {
        $prompt = str_replace('-', ' ', $m[1]);
        $hash = $m[2];
    } else {
        $prompt = preg_replace('/\.png$/i', '', $prompt);
        $prompt = str_replace('-', ' ', $prompt);
    }
    $generatedObjects[] = [
        'url'   => to_web_path($f),
        'thumb' => file_exists($gemObjThumbsDir . '/' . $bn) ? to_web_path($gemObjThumbsDir . '/' . $bn) : to_web_path($f),
        'prompt'=> $prompt,
        'hash'  => $hash,
    ];
}

// Saved results (downloaded composites)
$resDir = $root . '/img/results';
$resThumbsDir = $root . '/img/results_thumbs';
@mkdir($resDir, 0777, true);
@mkdir($resThumbsDir, 0777, true);
$resFiles = list_files($resDir . '/*.{png,PNG,jpg,JPG,jpeg,JPEG}');
// glob with brace not enabled by default; fallback manual merge
if (!$resFiles) {
    $resFiles = array_merge(
        list_files($resDir . '/*.png'),
        list_files($resDir . '/*.PNG'),
        list_files($resDir . '/*.jpg'),
        list_files($resDir . '/*.JPG'),
        list_files($resDir . '/*.jpeg'),
        list_files($resDir . '/*.JPEG')
    );
}
$results = [];
foreach ($resFiles as $f) {
    $bn = basename($f);
    $baseNoExt = preg_replace('/\.[A-Za-z0-9]+$/', '', $bn);
    $jsonPath = $resDir . '/' . $baseNoExt . '.json';
    $thumbPath = $resThumbsDir . '/' . $bn;
    $results[] = [
        'url' => to_web_path($f),
        'thumb' => file_exists($thumbPath) ? to_web_path($thumbPath) : to_web_path($f),
        'json' => file_exists($jsonPath) ? to_web_path($jsonPath) : ''
    ];
}

echo json_encode([
    'normalPoses' => $normalPoses,
    'generatedPoses' => $generatedPoses,
    'generatedBackgrounds' => $generatedBackgrounds,
    'generatedObjects' => $generatedObjects,
    'results' => $results,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
exit;

?>


