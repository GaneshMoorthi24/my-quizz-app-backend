<?php
// Quick check for Imagick extension in web context
header('Content-Type: text/plain');

echo "=== Imagick Extension Check ===\n\n";

// Check if extension is loaded
if (extension_loaded('imagick')) {
    echo "✅ Extension is LOADED\n";
} else {
    echo "❌ Extension is NOT loaded\n";
}

// Check if class exists
if (class_exists('Imagick')) {
    echo "✅ Imagick class EXISTS\n";
} else {
    echo "❌ Imagick class NOT found\n";
}

// Show loaded extensions
echo "\n=== Loaded Extensions ===\n";
$extensions = get_loaded_extensions();
if (in_array('imagick', $extensions)) {
    echo "✅ imagick is in loaded extensions list\n";
} else {
    echo "❌ imagick is NOT in loaded extensions list\n";
    echo "\nAll loaded extensions:\n";
    sort($extensions);
    foreach ($extensions as $ext) {
        echo "  - $ext\n";
    }
}

// Show PHP configuration
echo "\n=== PHP Configuration ===\n";
echo "PHP Version: " . PHP_VERSION . "\n";
echo "php.ini location: " . php_ini_loaded_file() . "\n";
echo "Extension directory: " . ini_get('extension_dir') . "\n";

// Check if DLL exists
$dllPath = ini_get('extension_dir') . '/php_imagick.dll';
echo "DLL path: $dllPath\n";
if (file_exists($dllPath)) {
    echo "✅ DLL file EXISTS\n";
} else {
    echo "❌ DLL file NOT found\n";
}

// Check PATH
echo "\n=== Environment PATH ===\n";
$path = getenv('PATH');
$pathParts = explode(';', $path);
$hasImageMagick = false;
foreach ($pathParts as $part) {
    if (stripos($part, 'ImageMagick') !== false) {
        echo "✅ Found in PATH: $part\n";
        $hasImageMagick = true;
    }
}
if (!$hasImageMagick) {
    echo "❌ ImageMagick NOT found in PATH\n";
}

