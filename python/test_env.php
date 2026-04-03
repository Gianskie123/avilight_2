<?php
/**
 * Temporary diagnostic script — DELETE after testing.
 * Access at: http://localhost/avilight/python/test_env.php
 */
$python  = 'python';
$sa_key  = 'C:\\xampp\\gee-keys\\avilight-key.json';
$project = 'avilight-483312';

echo "<h3>PHP → Python environment check</h3>";

// 1. Check Python is callable
exec('python --version 2>&1', $out, $code);
echo "<b>Python version:</b> " . htmlspecialchars(implode(' ', $out)) . " (exit: {$code})<br>";

// 2. Check earthengine-api is installed
exec('python -c "import ee; print(ee.__version__)" 2>&1', $out2, $code2);
echo "<b>earthengine-api:</b> " . htmlspecialchars(implode(' ', $out2)) . " (exit: {$code2})<br>";

// 3. Check key file exists
echo "<b>Key file exists:</b> " . (file_exists($sa_key) ? 'YES' : '<span style="color:red">NO — check path</span>') . "<br>";

// 4. Check scripts dir
$scripts_dir = realpath(__DIR__);
echo "<b>Scripts dir:</b> " . htmlspecialchars($scripts_dir) . "<br>";
foreach (['extract_viirs.py','extract_ndvi.py','extract_lst.py','extract_precip.py'] as $s) {
    $exists = file_exists($scripts_dir . DIRECTORY_SEPARATOR . $s) ? '✓' : '✗ MISSING';
    echo "&nbsp;&nbsp;{$s}: {$exists}<br>";
}

// 5. Test GEE auth via Python
$cmd = "set GOOGLE_APPLICATION_CREDENTIALS={$sa_key} && set GEE_PROJECT={$project} && "
     . "python -c \"import ee,os; creds=ee.ServiceAccountCredentials(email=None,key_file=os.environ['GOOGLE_APPLICATION_CREDENTIALS']); ee.Initialize(creds,project=os.environ['GEE_PROJECT']); print('GEE_OK')\" 2>&1";
exec($cmd, $out3, $code3);
$gee_result = implode(' ', $out3);
$color = str_contains($gee_result, 'GEE_OK') ? 'green' : 'red';
echo "<b>GEE auth test:</b> <span style='color:{$color}'>" . htmlspecialchars($gee_result) . "</span> (exit: {$code3})<br>";

echo "<br><i style='color:gray'>Delete python/test_env.php after you are done.</i>";
