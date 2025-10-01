<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Interface Scaling Test - CollaboraNexio</title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <style>
        .test-container {
            max-width: 1200px;
            margin: 50px auto;
            padding: 30px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .scale-controls {
            position: fixed;
            top: 20px;
            right: 20px;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 1000;
        }
        .scale-button {
            display: block;
            width: 100%;
            padding: 10px 15px;
            margin: 5px 0;
            border: 2px solid #2563EB;
            background: white;
            color: #2563EB;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.2s;
        }
        .scale-button:hover {
            background: #2563EB;
            color: white;
        }
        .scale-button.active {
            background: #2563EB;
            color: white;
        }
        .demo-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin: 30px 0;
        }
        .demo-card {
            background: #f9fafb;
            padding: 20px;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
        }
        .demo-card h3 {
            color: #1f2937;
            margin-bottom: 10px;
        }
        .demo-card p {
            color: #6b7280;
            line-height: 1.6;
        }
        .demo-table {
            width: 100%;
            border-collapse: collapse;
            margin: 30px 0;
        }
        .demo-table th,
        .demo-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        .demo-table th {
            background: #f9fafb;
            font-weight: 600;
            color: #374151;
        }
        .current-scale {
            display: inline-block;
            background: #10b981;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            margin-left: 10px;
        }
    </style>
</head>
<body>
    <!-- Scale Control Panel -->
    <div class="scale-controls">
        <h3>Interface Scale Control</h3>
        <p style="margin: 10px 0; color: #6b7280; font-size: 14px;">
            Click to change the interface size
        </p>
        <button class="scale-button" data-scale="0.8">80% - Smaller</button>
        <button class="scale-button active" data-scale="0.9">90% - Current</button>
        <button class="scale-button" data-scale="1.0">100% - Original</button>
        <button class="scale-button" data-scale="1.1">110% - Larger</button>
        <p style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #e5e7eb; color: #6b7280; font-size: 12px;">
            Current Scale: <span id="currentScaleValue" class="current-scale">90%</span>
        </p>
    </div>

    <!-- Main Content -->
    <div class="test-container">
        <h1>CollaboraNexio Interface Scaling Test</h1>
        <p style="font-size: 18px; color: #6b7280; margin: 20px 0;">
            The interface is currently scaled to <strong>90%</strong> to provide better visibility and a more compact layout.
            Use the control panel on the right to test different scaling options.
        </p>

        <!-- Demo Cards -->
        <div class="demo-grid">
            <div class="demo-card">
                <h3>Enhanced Visibility</h3>
                <p>The 90% scale makes the interface more visible by reducing the overall size while maintaining readability.</p>
            </div>
            <div class="demo-card">
                <h3>Better Screen Usage</h3>
                <p>More content fits on the screen without scrolling, improving the overall user experience.</p>
            </div>
            <div class="demo-card">
                <h3>Consistent Scaling</h3>
                <p>All interface elements scale uniformly, maintaining design proportions and visual hierarchy.</p>
            </div>
        </div>

        <!-- Demo Table -->
        <h2 style="margin-top: 40px;">Sample Data Table</h2>
        <table class="demo-table">
            <thead>
                <tr>
                    <th>Feature</th>
                    <th>Status</th>
                    <th>Scale Impact</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Text Readability</td>
                    <td>✓ Maintained</td>
                    <td>All text remains clear and readable</td>
                </tr>
                <tr>
                    <td>Layout Integrity</td>
                    <td>✓ Preserved</td>
                    <td>Responsive design adapts correctly</td>
                </tr>
                <tr>
                    <td>Interactive Elements</td>
                    <td>✓ Functional</td>
                    <td>Buttons and forms work normally</td>
                </tr>
                <tr>
                    <td>Visual Hierarchy</td>
                    <td>✓ Consistent</td>
                    <td>Design relationships maintained</td>
                </tr>
            </tbody>
        </table>

        <!-- Demo Buttons -->
        <h2>Interactive Elements</h2>
        <div style="margin: 20px 0;">
            <button style="padding: 10px 20px; margin: 5px; background: #2563EB; color: white; border: none; border-radius: 4px; cursor: pointer;">
                Primary Button
            </button>
            <button style="padding: 10px 20px; margin: 5px; background: white; color: #2563EB; border: 2px solid #2563EB; border-radius: 4px; cursor: pointer;">
                Secondary Button
            </button>
            <button style="padding: 10px 20px; margin: 5px; background: #10B981; color: white; border: none; border-radius: 4px; cursor: pointer;">
                Success Button
            </button>
        </div>

        <!-- Instructions -->
        <div style="margin-top: 40px; padding: 20px; background: #f0f9ff; border-left: 4px solid #2563EB; border-radius: 4px;">
            <h3 style="color: #1e40af; margin-bottom: 10px;">How to Adjust Interface Scale</h3>
            <ol style="color: #6b7280; line-height: 1.8; padding-left: 20px;">
                <li>To change the scale globally, edit <code style="background: #e5e7eb; padding: 2px 6px; border-radius: 3px;">assets/css/styles.css</code></li>
                <li>Find the <code style="background: #e5e7eb; padding: 2px 6px; border-radius: 3px;">--global-scale</code> variable in the :root section</li>
                <li>Change the value from 0.9 to your desired scale (e.g., 1.0 for 100%)</li>
                <li>The change will apply to all pages automatically</li>
            </ol>
        </div>
    </div>

    <script>
        // Dynamic scale switching for testing
        document.querySelectorAll('.scale-button').forEach(button => {
            button.addEventListener('click', function() {
                const scale = this.dataset.scale;
                const root = document.documentElement;

                // Update the CSS variable
                root.style.setProperty('--global-scale', scale);

                // For browsers that support zoom
                root.style.zoom = scale;

                // Update active state
                document.querySelectorAll('.scale-button').forEach(btn => {
                    btn.classList.remove('active');
                });
                this.classList.add('active');

                // Update display
                document.getElementById('currentScaleValue').textContent = Math.round(scale * 100) + '%';
            });
        });
    </script>
</body>
</html>