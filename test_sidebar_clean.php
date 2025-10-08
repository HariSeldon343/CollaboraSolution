<?php
session_start();
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Sidebar - Stili Puliti</title>

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">

    <!-- CSS Ottimizzati (senza stili inline) -->
    <link href="/CollaboraNexio/assets/css/styles.css" rel="stylesheet">
    <link href="/CollaboraNexio/assets/css/sidebar-responsive.css" rel="stylesheet">
</head>
<body>
    <!-- Include la sidebar senza stili inline -->
    <?php include 'includes/sidebar.php'; ?>

    <!-- Contenuto principale -->
    <div class="content-wrapper">
        <div class="container-fluid">
            <div class="row mb-4">
                <div class="col-12">
                    <h1 class="page-title">Test Sidebar Pulita</h1>
                    <p class="text-muted">Questa pagina verifica che la sidebar funzioni correttamente dopo la rimozione degli stili inline.</p>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header bg-success text-white">
                            <h5 class="mb-0"><i class="bi bi-check-circle"></i> Modifiche Completate</h5>
                        </div>
                        <div class="card-body">
                            <ul class="list-unstyled">
                                <li class="mb-2">
                                    <i class="bi bi-check text-success"></i>
                                    <strong>Rimosso blocco &lt;style&gt;</strong> dal file sidebar.php
                                </li>
                                <li class="mb-2">
                                    <i class="bi bi-check text-success"></i>
                                    <strong>Stili CSS esterni</strong> in assets/css/styles.css
                                </li>
                                <li class="mb-2">
                                    <i class="bi bi-check text-success"></i>
                                    <strong>Stili responsive</strong> in assets/css/sidebar-responsive.css
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header bg-info text-white">
                            <h5 class="mb-0"><i class="bi bi-info-circle"></i> Test da Effettuare</h5>
                        </div>
                        <div class="card-body">
                            <ol>
                                <li class="mb-2">Verifica che la sidebar sia visibile</li>
                                <li class="mb-2">Controlla il toggle (hamburger menu)</li>
                                <li class="mb-2">Testa la responsività su mobile</li>
                                <li class="mb-2">Verifica gli stati hover sui link</li>
                                <li class="mb-2">Controlla l'evidenziazione della pagina attiva</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row mt-4">
                <div class="col-12">
                    <div class="alert alert-primary">
                        <h5><i class="bi bi-lightbulb"></i> Risultato</h5>
                        <p>Gli stili inline sono stati rimossi con successo. Ora la sidebar utilizza esclusivamente i CSS esterni ottimizzati, permettendo:</p>
                        <ul>
                            <li>Migliore performance (CSS cacheable)</li>
                            <li>Manutenzione più semplice</li>
                            <li>Override degli stili più facile</li>
                            <li>Separazione pulita tra struttura HTML e presentazione CSS</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>