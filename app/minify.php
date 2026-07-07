<?php
$root = dirname(__DIR__);

function minifyCSS($code) {
    $code = preg_replace('/\/\*.*?\*\//s', '', $code);
    $code = preg_replace('/\s*([{};,>~+=|!():])\s*/', '$1', $code);
    $code = preg_replace('/\s+/', ' ', $code);
    $code = preg_replace('/;\s*}/', '}', $code);
    return trim($code);
}

function minifyJS($code) {
    $code = preg_replace('/(?<=[^:])\/\/.*/m', '', $code);
    $code = preg_replace('/\/\*.*?\*\//s', '', $code);
    $code = preg_replace('/\s*([{};,=+\-*\/%!<>|&?:().\[\]])\s*/', '$1', $code);
    $code = preg_replace('/\s+/', ' ', $code);
    $code = preg_replace('/;\s*([;}])/', '$1', $code);
    $code = preg_replace('/\s*;\s*$/', ';', $code);
    return trim($code);
}

$css = file_get_contents($root . '/style.css');
$cssMin = minifyCSS($css);
file_put_contents($root . '/style.min.css', $cssMin);

$js = file_get_contents($root . '/script.js');
$jsMin = minifyJS($js);
file_put_contents($root . '/script.min.js', $jsMin);

echo "Minified: style.css (" . strlen($css) . " -> " . strlen($cssMin) . " bytes)\n";
echo "Minified: script.js (" . strlen($js) . " -> " . strlen($jsMin) . " bytes)\n";
