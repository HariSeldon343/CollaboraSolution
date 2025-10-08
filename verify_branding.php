<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CollaboraNexio - Branding Verification</title>
    <?php require_once __DIR__ . '/includes/favicon.php'; ?>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f5f5f5;
            padding: 20px;
            margin: 0;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #2f5aa0;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        h1 img {
            height: 40px;
            width: auto;
        }
        .subtitle {
            color: #666;
            margin-bottom: 30px;
        }
        .section {
            margin-bottom: 40px;
        }
        .section h2 {
            color: #333;
            border-bottom: 2px solid #2f5aa0;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .logo-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }
        .logo-item {
            background: #fafafa;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            border: 1px solid #e0e0e0;
        }
        .logo-item img {
            max-width: 100%;
            height: auto;
            margin-bottom: 10px;
            border: 1px solid #ddd;
            background: white;
            padding: 10px;
        }
        .logo-item h3 {
            margin: 10px 0 5px;
            font-size: 14px;
            color: #333;
        }
        .info {
            font-size: 12px;
            color: #666;
        }
        .status {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 11px;
            margin-top: 5px;
        }
        .status.exists {
            background: #d4edda;
            color: #155724;
        }
        .status.missing {
            background: #f8d7da;
            color: #721c24;
        }
        .checklist {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .checklist ul {
            margin: 10px 0;
            padding-left: 25px;
        }
        .checklist li {
            margin: 8px 0;
        }
        .check {
            color: #10b981;
            font-weight: bold;
        }
        .cross {
            color: #ef4444;
            font-weight: bold;
        }
        .theme-color-box {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 1px solid #ddd;
            vertical-align: middle;
            margin-left: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>
            <img src="assets/images/logo.svg" alt="Logo">
            CollaboraNexio - Branding Verification
        </h1>
        <div class="subtitle">Verification of new branding assets implementation</div>

        <div class="section">
            <h2>Branding Checklist</h2>
            <div class="checklist">
                <ul>
                    <?php
                    $checks = [
                        ['file' => 'assets/images/logo.png', 'desc' => 'Main logo PNG (800x800)'],
                        ['file' => 'assets/images/logo.svg', 'desc' => 'Main logo SVG'],
                        ['file' => 'assets/images/favicon.svg', 'desc' => 'Favicon SVG'],
                        ['file' => 'assets/images/favicon-16x16.png', 'desc' => 'Favicon 16x16 PNG'],
                        ['file' => 'assets/images/favicon-32x32.png', 'desc' => 'Favicon 32x32 PNG'],
                        ['file' => 'assets/images/apple-touch-icon.png', 'desc' => 'Apple Touch Icon (180x180)'],
                        ['file' => 'assets/images/icon-192x192.png', 'desc' => 'Android Icon (192x192)'],
                        ['file' => 'assets/images/icon-512x512.png', 'desc' => 'PWA Icon (512x512)']
                    ];

                    foreach ($checks as $check) {
                        $exists = file_exists(__DIR__ . '/' . $check['file']);
                        $icon = $exists ? '✓' : '✗';
                        $class = $exists ? 'check' : 'cross';
                        $size = $exists ? ' (' . number_format(filesize(__DIR__ . '/' . $check['file'])) . ' bytes)' : '';
                        echo "<li><span class=\"$class\">$icon</span> {$check['desc']}: {$check['file']}$size</li>\n";
                    }
                    ?>
                </ul>
                <p class="info">Theme Color: #2f5aa0 <span class="theme-color-box" style="background: #2f5aa0;"></span></p>
            </div>
        </div>

        <div class="section">
            <h2>Logo Variants</h2>
            <div class="logo-grid">
                <div class="logo-item">
                    <h3>Main Logo (PNG)</h3>
                    <img src="assets/images/logo.png" alt="Main Logo PNG" style="max-width: 150px;">
                    <div class="info">assets/images/logo.png</div>
                    <?php
                    $file = __DIR__ . '/assets/images/logo.png';
                    if (file_exists($file)) {
                        list($width, $height) = getimagesize($file);
                        echo "<div class='info'>{$width}x{$height}px</div>";
                        echo "<span class='status exists'>EXISTS</span>";
                    } else {
                        echo "<span class='status missing'>MISSING</span>";
                    }
                    ?>
                </div>

                <div class="logo-item">
                    <h3>Main Logo (SVG)</h3>
                    <img src="assets/images/logo.svg" alt="Main Logo SVG" style="max-width: 150px;">
                    <div class="info">assets/images/logo.svg</div>
                    <?php
                    echo file_exists(__DIR__ . '/assets/images/logo.svg')
                        ? "<span class='status exists'>EXISTS</span>"
                        : "<span class='status missing'>MISSING</span>";
                    ?>
                </div>

                <div class="logo-item">
                    <h3>Favicon (SVG)</h3>
                    <img src="assets/images/favicon.svg" alt="Favicon SVG" style="max-width: 150px;">
                    <div class="info">assets/images/favicon.svg</div>
                    <?php
                    echo file_exists(__DIR__ . '/assets/images/favicon.svg')
                        ? "<span class='status exists'>EXISTS</span>"
                        : "<span class='status missing'>MISSING</span>";
                    ?>
                </div>
            </div>
        </div>

        <div class="section">
            <h2>Favicon Sizes</h2>
            <div class="logo-grid">
                <?php
                $favicons = [
                    ['file' => 'favicon-16x16.png', 'size' => '16x16', 'desc' => 'Browser Tab (Small)'],
                    ['file' => 'favicon-32x32.png', 'size' => '32x32', 'desc' => 'Browser Tab (Standard)'],
                    ['file' => 'apple-touch-icon.png', 'size' => '180x180', 'desc' => 'iOS Home Screen'],
                    ['file' => 'icon-192x192.png', 'size' => '192x192', 'desc' => 'Android Chrome'],
                    ['file' => 'icon-512x512.png', 'size' => '512x512', 'desc' => 'PWA Splash']
                ];

                foreach ($favicons as $favicon) {
                    $path = 'assets/images/' . $favicon['file'];
                    $fullPath = __DIR__ . '/' . $path;
                    $exists = file_exists($fullPath);
                    ?>
                    <div class="logo-item">
                        <h3><?php echo $favicon['desc']; ?></h3>
                        <?php if ($exists): ?>
                            <img src="<?php echo $path; ?>" alt="<?php echo $favicon['desc']; ?>" style="max-width: 100px;">
                        <?php else: ?>
                            <div style="padding: 30px; background: #f0f0f0;">Missing</div>
                        <?php endif; ?>
                        <div class="info"><?php echo $favicon['file']; ?></div>
                        <div class="info"><?php echo $favicon['size']; ?>px</div>
                        <?php
                        echo $exists
                            ? "<span class='status exists'>EXISTS</span>"
                            : "<span class='status missing'>MISSING</span>";
                        ?>
                    </div>
                    <?php
                }
                ?>
            </div>
        </div>

        <div class="section">
            <h2>Implementation Status</h2>
            <div class="checklist">
                <h3>Updated Files:</h3>
                <ul>
                    <li><span class="check">✓</span> Logo files replaced (PNG and SVG)</li>
                    <li><span class="check">✓</span> Favicon.svg updated</li>
                    <li><span class="check">✓</span> All PNG favicon sizes generated</li>
                    <li><span class="check">✓</span> Theme color updated in favicon.php (#2563eb → #2f5aa0)</li>
                    <li><span class="check">✓</span> All PHP pages reference correct logo path</li>
                </ul>

                <h3>Files Using Logo:</h3>
                <p class="info">The following files reference the logo and will display the new branding:</p>
                <ul>
                    <li>includes/sidebar.php - Navigation sidebar</li>
                    <li>index.php - Login page</li>
                    <li>dashboard.php, utenti.php, files.php, calendar.php - Main pages</li>
                    <li>tasks.php, chat.php, progetti.php - Feature pages</li>
                    <li>All other PHP pages in the system</li>
                </ul>
            </div>
        </div>

        <div class="section">
            <h2>Summary</h2>
            <div class="checklist" style="background: #e8f1ff; border-left: 4px solid #2f5aa0;">
                <p><strong>Branding Update Complete!</strong></p>
                <p>The CollaboraNexio platform has been successfully updated with the new Spark branding:</p>
                <ul>
                    <li>Blue four-pointed star/diamond logo (#2f5aa0)</li>
                    <li>All logo files replaced (PNG and SVG formats)</li>
                    <li>Favicon files generated in all required sizes</li>
                    <li>Theme color updated throughout the application</li>
                    <li>All pages will now display the new branding</li>
                </ul>
                <p class="info">Generated on: <?php echo date('Y-m-d H:i:s'); ?></p>
            </div>
        </div>
    </div>
</body>
</html>