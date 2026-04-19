<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$species_id   = (int)($_POST['species_id']   ?? 0);
$species_name = trim($_POST['species_name'] ?? '');

if ($species_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid species ID']);
    exit;
}

// Sanitize species name into a safe filename slug
// e.g. "Black-crowned Night Heron" → "black-crowned_night_heron"
function species_slug(string $name): string {
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9\-]+/', '_', $slug); // spaces/special → _
    $slug = preg_replace('/_+/', '_', $slug);            // collapse multiple _
    return trim($slug, '_') ?: 'species';
}

$allowed_tolerances = ['sensitive', 'tolerant', ''];
$allowed_migrations = ['migratory', 'resident', ''];

$light_tolerance  = trim($_POST['light_tolerance']  ?? '');
$migratory_status = trim($_POST['migratory_status'] ?? '');
$description      = trim($_POST['description']      ?? '');

if (!in_array($light_tolerance, $allowed_tolerances, true)) {
    echo json_encode(['success' => false, 'error' => 'Invalid light tolerance value']);
    exit;
}
if (!in_array($migratory_status, $allowed_migrations, true)) {
    echo json_encode(['success' => false, 'error' => 'Invalid migratory status value']);
    exit;
}

// ── Image upload ──────────────────────────────────────────────────────────────
// Standard size: 400 × 300 px (landscape), saved as JPEG.
define('IMG_W', 400);
define('IMG_H', 300);
define('IMG_DIR', __DIR__ . '/../assets/species_images/');
define('IMG_WEB', 'assets/species_images/');

$remove_image = ($_POST['remove_image'] ?? '0') === '1';
$image_path   = null;   // null = no change; string = new path; '' = remove

if ($remove_image) {
    // Fetch and delete the existing file, then clear the DB column
    try {
        $existing = get_mysql_db()
            ->prepare('SELECT image_path FROM species_masterlist WHERE species_id = ?');
        $existing->execute([$species_id]);
        $old_path = $existing->fetchColumn();
        if ($old_path) {
            $abs         = realpath(__DIR__ . '/../' . $old_path);
            $allowed_dir = realpath(IMG_DIR);
            if ($abs && $allowed_dir && str_starts_with($abs, $allowed_dir)) {
                @unlink($abs);
            }
        }
    } catch (Throwable $e) { /* non-fatal */ }
    $image_path = '';  // empty string signals "set NULL in DB"
} elseif (!empty($_FILES['image_file']['tmp_name']) && $_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
    $tmp  = $_FILES['image_file']['tmp_name'];
    $mime = mime_content_type($tmp);

    $allowed_mime = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    if (!in_array($mime, $allowed_mime, true)) {
        echo json_encode(['success' => false, 'error' => 'Unsupported image type. Use JPG, PNG, or WEBP.']);
        exit;
    }

    // Load source image via GD
    $src = match ($mime) {
        'image/jpeg'        => imagecreatefromjpeg($tmp),
        'image/png'         => imagecreatefrompng($tmp),
        'image/webp'        => imagecreatefromwebp($tmp),
        'image/gif'         => imagecreatefromgif($tmp),
        default             => false,
    };

    if (!$src) {
        echo json_encode(['success' => false, 'error' => 'Could not read image file.']);
        exit;
    }

    $src_w = imagesx($src);
    $src_h = imagesy($src);

    // Cover-crop: scale to fill 400×300, then crop centre
    $scale = max(IMG_W / $src_w, IMG_H / $src_h);
    $scaled_w = (int)round($src_w * $scale);
    $scaled_h = (int)round($src_h * $scale);
    $off_x = (int)(($scaled_w - IMG_W) / 2);
    $off_y = (int)(($scaled_h - IMG_H) / 2);

    $dst = imagecreatetruecolor(IMG_W, IMG_H);
    // Preserve transparency for PNG source
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagecopyresampled($dst, $src, 0, 0, (int)($off_x / $scale), (int)($off_y / $scale), IMG_W, IMG_H, IMG_W, IMG_H);
    // Redo properly: resample from src at original coords
    $dst = imagecreatetruecolor(IMG_W, IMG_H);
    imagecopyresampled(
        $dst, $src,
        0, 0,
        (int)round($off_x / $scale), (int)round($off_y / $scale),
        IMG_W, IMG_H,
        (int)round(IMG_W / $scale), (int)round(IMG_H / $scale)
    );
    imagedestroy($src);

    if (!is_dir(IMG_DIR)) {
        mkdir(IMG_DIR, 0755, true);
    }

    $filename = species_slug($species_name ?: (string)$species_id) . '.jpg';
    $filepath = IMG_DIR . $filename;

    if (!imagejpeg($dst, $filepath, 90)) {
        imagedestroy($dst);
        echo json_encode(['success' => false, 'error' => 'Failed to save image.']);
        exit;
    }
    imagedestroy($dst);

    $image_path = IMG_WEB . $filename;
} // end elseif upload

// ── Database update ───────────────────────────────────────────────────────────
$sets   = [
    'light_tolerance  = :tol',
    'migratory_status = :mig',
    'description      = :desc',
];
$params = [
    ':tol'  => $light_tolerance,
    ':mig'  => $migratory_status,
    ':desc' => $description,
    ':id'   => $species_id,
];

if ($image_path !== null) {
    $sets[]         = 'image_path = :img';
    $params[':img'] = $image_path === '' ? null : $image_path;
}

try {
    $pdo  = get_mysql_db();
    $sql  = 'UPDATE species_masterlist SET ' . implode(', ', $sets) . ' WHERE species_id = :id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    echo json_encode([
        'success'    => true,
        'rows'       => $stmt->rowCount(),
        'image_path' => $image_path,
    ]);
} catch (PDOException $ex) {
    echo json_encode(['success' => false, 'error' => $ex->getMessage()]);
}
