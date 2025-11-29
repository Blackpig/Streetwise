<?php
// api/upload-portrait.php - Secure portrait upload endpoint for Owlbear Streetwise
require __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../..');
$dotenv->load();

// CORS headers - Allow requests from Owlbear Rodeo and localhost (for development)
$allowedOrigins = [
    'https://www.owlbear.rodeo',
    'https://owlbear.rodeo',
    'http://localhost:5173',
    'http://localhost:4173',
    'http://127.0.0.1:5173',
    'http://127.0.0.1:4173'
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins)) {
    header('Access-Control-Allow-Origin: ' . $origin);
} else {
    // For production, only allow owlbear.rodeo
    header('Access-Control-Allow-Origin: https://www.owlbear.rodeo');
}

header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
header('Content-Type: application/json');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Rate limiting function
function checkRateLimit($ip) {
    $rateLimitDir = __DIR__ . '/../uploads/.rate-limits';
    if (!is_dir($rateLimitDir)) {
        mkdir($rateLimitDir, 0755, true);
    }

    $rateLimitFile = $rateLimitDir . '/' . md5($ip) . '.json';
    $maxRequests = 10; // Maximum requests per time window
    $timeWindow = 300; // Time window in seconds (5 minutes)
    $now = time();

    // Load existing rate limit data
    $data = [];
    if (file_exists($rateLimitFile)) {
        $content = file_get_contents($rateLimitFile);
        $data = json_decode($content, true) ?: [];
    }

    // Remove old requests outside the time window
    $data = array_filter($data, function($timestamp) use ($now, $timeWindow) {
        return ($now - $timestamp) < $timeWindow;
    });

    // Check if limit exceeded
    if (count($data) >= $maxRequests) {
        return false;
    }

    // Add current request
    $data[] = $now;

    // Save updated data
    file_put_contents($rateLimitFile, json_encode($data));

    return true;
}

// Check rate limit
$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (!checkRateLimit($clientIp)) {
    http_response_code(429);
    echo json_encode([
        'error' => 'Rate limit exceeded. Please try again later.',
        'retry_after' => 300
    ]);
    exit;
}

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Validate API key
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
if (empty($apiKey) || $apiKey !== $_ENV['PORTRAIT_UPLOAD_API_KEY']) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid API key']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['image']) || !isset($input['characterId'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields: image, characterId']);
    exit;
}

$imageData = $input['image'];
$characterId = preg_replace('/[^a-zA-Z0-9_-]/', '', $input['characterId']); // Sanitize
$characterName = $input['characterName'] ?? 'character';

// Validate base64 data URI
if (!preg_match('/^data:image\/(jpeg|jpg|png|gif|webp);base64,/', $imageData, $matches)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid image format. Must be base64 data URI']);
    exit;
}

$imageType = $matches[1];

// Extract base64 data
$base64Data = preg_replace('/^data:image\/\w+;base64,/', '', $imageData);
$imageBlob = base64_decode($base64Data);

if ($imageBlob === false) {
    http_response_code(400);
    echo json_encode(['error' => 'Failed to decode base64 image']);
    exit;
}

// Create image resource from string
$sourceImage = @imagecreatefromstring($imageBlob);
if ($sourceImage === false) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid image data']);
    exit;
}

// Get original dimensions
$originalWidth = imagesx($sourceImage);
$originalHeight = imagesy($sourceImage);

// Target size for portraits (Owlbear tokens are typically 256-512px)
$targetSize = 512;

// Calculate dimensions to maintain aspect ratio and crop to square
$size = min($originalWidth, $originalHeight);
$x = ($originalWidth - $size) / 2;
$y = ($originalHeight - $size) / 2;

// Create square canvas
$targetImage = imagecreatetruecolor($targetSize, $targetSize);

// Enable alpha blending for transparency
imagealphablending($targetImage, false);
imagesavealpha($targetImage, true);

// Copy and resize to square
imagecopyresampled(
    $targetImage,
    $sourceImage,
    0, 0,           // Destination x, y
    $x, $y,         // Source x, y
    $targetSize, $targetSize,  // Destination width, height
    $size, $size    // Source width, height
);

// Create directory if it doesn't exist
$uploadDir = __DIR__ . '/../uploads/portraits/' . $characterId;
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Generate filename with timestamp to handle updates
$timestamp = time();
$filename = 'portrait-' . $timestamp . '.jpg';
$filepath = $uploadDir . '/' . $filename;

// Save as optimized JPEG
$quality = 85;
$success = imagejpeg($targetImage, $filepath, $quality);

// Clean up
imagedestroy($sourceImage);
imagedestroy($targetImage);

if (!$success) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save image']);
    exit;
}

// Clean up old portraits for this character (keep only the latest)
$files = glob($uploadDir . '/portrait-*.jpg');
if ($files) {
    foreach ($files as $file) {
        if ($file !== $filepath) {
            @unlink($file);
        }
    }
}

// Generate public URL
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$publicUrl = $protocol . '://' . $host . '/uploads/portraits/' . $characterId . '/' . $filename;

// Return success with URL
http_response_code(200);
echo json_encode([
    'success' => true,
    'url' => $publicUrl,
    'characterId' => $characterId,
    'size' => filesize($filepath),
    'dimensions' => [
        'width' => $targetSize,
        'height' => $targetSize
    ]
]);
