<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifica Nuovo Logo - CollaboraNexio</title>
    <?php include 'includes/favicon.php'; ?>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 900px;
            width: 100%;
            padding: 40px;
        }
        h1 {
            color: #2f5aa0;
            margin-bottom: 10px;
            font-size: 32px;
        }
        .subtitle {
            color: #666;
            margin-bottom: 30px;
            font-size: 16px;
        }
        .status-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .status-card {
            background: #f8f9fa;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
        }
        .status-card.success {
            border-color: #10b981;
            background: #f0fdf4;
        }
        .status-card img {
            max-width: 100px;
            height: auto;
            margin-bottom: 10px;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 5px;
            background: white;
        }
        .status-card h3 {
            color: #333;
            font-size: 16px;
            margin-bottom: 5px;
        }
        .status-card p {
            color: #666;
            font-size: 14px;
            margin-bottom: 10px;
        }
        .file-info {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 5px;
            padding: 5px 10px;
            font-size: 12px;
            color: #6b7280;
            font-family: monospace;
        }
        .check-icon {
            color: #10b981;
            font-size: 24px;
            margin-bottom: 10px;
        }
        .warning-box {
            background: #fef3c7;
            border: 2px solid #f59e0b;
            border-radius: 10px;
            padding: 20px;
            margin-top: 20px;
        }
        .warning-box h3 {
            color: #f59e0b;
            margin-bottom: 10px;
        }
        .warning-box ul {
            margin-left: 20px;
            color: #92400e;
        }
        .cache-clear {
            background: #eff6ff;
            border: 2px solid #2563eb;
            border-radius: 10px;
            padding: 20px;
            margin-top: 20px;
        }
        .cache-clear h3 {
            color: #2563eb;
            margin-bottom: 10px;
        }
        .timestamp {
            text-align: center;
            color: #9ca3af;
            font-size: 14px;
            margin-top: 30px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Verifica Nuovo Branding CollaboraNexio</h1>
        <p class="subtitle">Controllo automatico dei file del logo aggiornati</p>

        <div class="status-grid">
            <?php
            $logoFiles = [
                ['file' => 'assets/images/logo.png', 'desc' => 'Logo principale PNG', 'size' => '800x800'],
                ['file' => 'assets/images/logo.svg', 'desc' => 'Logo principale SVG', 'size' => 'Vettoriale'],
                ['file' => 'assets/images/favicon.svg', 'desc' => 'Favicon SVG', 'size' => 'Vettoriale'],
                ['file' => 'assets/images/favicon-16x16.png', 'desc' => 'Favicon piccolo', 'size' => '16x16'],
                ['file' => 'assets/images/favicon-32x32.png', 'desc' => 'Favicon medio', 'size' => '32x32'],
                ['file' => 'assets/images/apple-touch-icon.png', 'desc' => 'Apple Touch Icon', 'size' => '180x180']
            ];

            foreach ($logoFiles as $logo) {
                $filePath = __DIR__ . '/' . $logo['file'];
                $exists = file_exists($filePath);
                $modified = $exists ? date('Y-m-d H:i:s', filemtime($filePath)) : 'N/A';
                $isToday = $exists && date('Y-m-d', filemtime($filePath)) == date('Y-m-d');

                echo '<div class="status-card ' . ($isToday ? 'success' : '') . '">';

                if ($exists) {
                    echo '<div class="check-icon">✓</div>';
                    echo '<img src="' . $logo['file'] . '" alt="' . $logo['desc'] . '">';
                    echo '<h3>' . $logo['desc'] . '</h3>';
                    echo '<p>Dimensione: ' . $logo['size'] . '</p>';
                    echo '<div class="file-info">Aggiornato: ' . $modified . '</div>';

                    if ($isToday) {
                        echo '<div style="color: #10b981; margin-top: 5px; font-size: 12px;">✓ Aggiornato oggi</div>';
                    }
                } else {
                    echo '<div style="color: #ef4444; font-size: 24px;">✗</div>';
                    echo '<h3>' . $logo['desc'] . '</h3>';
                    echo '<p style="color: #ef4444;">File non trovato!</p>';
                }

                echo '</div>';
            }
            ?>
        </div>

        <div class="cache-clear">
            <h3>⚠️ IMPORTANTE: Svuota la Cache del Browser</h3>
            <p>Se vedi ancora il vecchio logo, devi svuotare la cache:</p>
            <ul>
                <li><strong>Chrome/Edge:</strong> Premi Ctrl+Shift+Delete → Seleziona "Immagini e file memorizzati nella cache" → Cancella dati → Ricarica con Ctrl+F5</li>
                <li><strong>Firefox:</strong> Premi Ctrl+Shift+Delete → Seleziona "Cache" → Cancella adesso → Ricarica con Ctrl+F5</li>
                <li><strong>Safari:</strong> Sviluppo → Svuota cache → Ricarica con Cmd+R</li>
            </ul>
        </div>

        <?php
        // Check if all files were updated today
        $allUpdatedToday = true;
        foreach ($logoFiles as $logo) {
            $filePath = __DIR__ . '/' . $logo['file'];
            if (!file_exists($filePath) || date('Y-m-d', filemtime($filePath)) != date('Y-m-d')) {
                $allUpdatedToday = false;
                break;
            }
        }

        if ($allUpdatedToday) {
            echo '<div style="background: #d1fae5; border: 2px solid #10b981; border-radius: 10px; padding: 20px; margin-top: 20px; text-align: center;">';
            echo '<h3 style="color: #065f46; margin-bottom: 10px;">✓ Tutti i file sono stati aggiornati correttamente!</h3>';
            echo '<p style="color: #047857;">Il nuovo branding con la stella blu è stato installato con successo.</p>';
            echo '</div>';
        }
        ?>

        <div class="timestamp">
            Verifica eseguita: <?php echo date('Y-m-d H:i:s'); ?>
        </div>
    </div>
</body>
</html>