<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$root = __DIR__;

// Handle deletion requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    if ($action === 'delete') {
        $type = isset($_POST['type']) ? $_POST['type'] : '';
        $file = isset($_POST['file']) ? $_POST['file'] : '';

        // Only allow specific types
        if ($type !== 'pose' && $type !== 'background') {
            echo json_encode(['success' => false, 'error' => 'Invalid type']);
            exit;
        }

        // Only allow safe basenames within gemini folders
        $basename = basename($file);
        if (!preg_match('/^[A-Za-z0-9._-]+\.png$/', $basename)) {
            echo json_encode(['success' => false, 'error' => 'Invalid filename']);
            exit;
        }

        if ($type === 'pose') {
            $original = $root . '/img/gemini/poses/' . $basename;
            $thumb = $root . '/img/gemini/poses_thumbs/' . $basename;
        } else {
            $original = $root . '/img/gemini/backgrounds/' . $basename;
            $thumb = $root . '/img/gemini/backgrounds_thumbs/' . $basename;
        }

        $ok = true;
        // Attempt to delete, ignoring if missing
        foreach ([$original, $thumb] as $p) {
            if (file_exists($p)) {
                if (!@unlink($p)) { $ok = false; }
            }
        }

        echo json_encode(['success' => $ok]);
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

echo json_encode([
    'normalPoses' => $normalPoses,
    'generatedPoses' => $generatedPoses,
    'generatedBackgrounds' => $generatedBackgrounds,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
exit;

?>


