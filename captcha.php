<?php
/**
 * captcha.php
 * Generates a distorted text CAPTCHA image and stores the answer in the session.
 * Output: image/png (no HTML)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- Generate a random 6-character CAPTCHA string ---
$chars  = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // skip 0/O/1/I for clarity
$length = 6;
$text   = '';
for ($i = 0; $i < $length; $i++) {
    $text .= $chars[random_int(0, strlen($chars) - 1)];
}
$_SESSION['captcha_text'] = $text;
$_SESSION['captcha_time'] = time();

// --- Image dimensions ---
$width  = 200;
$height = 70;
$img    = imagecreatetruecolor($width, $height);

// --- Colour palette ---
$bg     = imagecolorallocate($img, 240, 240, 238);
$ink    = imagecolorallocate($img, 30,  30,  30);
$noise1 = imagecolorallocate($img, 130, 130, 130);
$noise2 = imagecolorallocate($img, 180, 160, 140);

imagefill($img, 0, 0, $bg);

// --- Background noise: random dots ---
for ($i = 0; $i < 600; $i++) {
    imagesetpixel($img, random_int(0, $width - 1), random_int(0, $height - 1), $noise1);
}

// --- Background noise: random lines ---
for ($i = 0; $i < 8; $i++) {
    $c = (random_int(0, 1) === 0) ? $noise1 : $noise2;
    imageline(
        $img,
        random_int(0, $width),  random_int(0, $height),
        random_int(0, $width),  random_int(0, $height),
        $c
    );
}

// --- Resolve a suitable TTF font (bundled first, then common system paths) ---
$font_candidates = [
    __DIR__ . '/assets/fonts/LiberationSans-BoldItalic.ttf',
    '/usr/share/fonts/truetype/liberation/LiberationSans-BoldItalic.ttf',
    '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
    '/usr/share/fonts/TTF/DejaVuSans-Bold.ttf',
];
$font = '';
foreach ($font_candidates as $candidate) {
    if (file_exists($candidate)) {
        $font = $candidate;
        break;
    }
}
$use_ttf = ($font !== '');
$fontSize = 28;
$startX   = 8;
$baseY    = 50;

for ($i = 0; $i < $length; $i++) {
    $angle  = random_int(-18, 18);
    $x      = $startX + $i * 30 + random_int(-3, 3);
    $y      = $baseY  + random_int(-6, 6);

    // Slight colour variation per character
    $r = random_int(15, 50);
    $g = random_int(15, 50);
    $b = random_int(15, 50);
    $charColor = imagecolorallocate($img, $r, $g, $b);

    if ($use_ttf) {
        imagettftext($img, $fontSize, $angle, $x, $y, $charColor, $font, $text[$i]);
    } else {
        // Fallback: GD built-in font (no distortion, but always available)
        imagestring($img, 5, $x, (int)($baseY - 14 + random_int(-6, 6)), $text[$i], $charColor);
    }
}

// --- Foreground noise: a few dark squiggles over the text ---
for ($i = 0; $i < 4; $i++) {
    $c = imagecolorallocate($img, random_int(60, 120), random_int(60, 120), random_int(60, 120));
    imagesetthickness($img, 1);
    // Arc-shaped squiggle
    imagearc(
        $img,
        random_int(0, $width),  random_int(0, $height),
        random_int(20, 80),     random_int(10, 40),
        random_int(0, 180),     random_int(180, 360),
        $c
    );
}

// --- Output ---
header('Content-Type: image/png');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
imagepng($img);
imagedestroy($img);
