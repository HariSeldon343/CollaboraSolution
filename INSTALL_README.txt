================================================================================
                    CollaboraNexio - GUIDA INSTALLAZIONE AUTOMATICA
================================================================================

REQUISITI:
-----------
• Windows con XAMPP installato in C:\xampp
• MySQL e Apache (verranno avviati automaticamente se necessario)
• PHP 8.0 o superiore

FILE DI INSTALLAZIONE:
----------------------
1. install.bat         - Script principale di installazione
2. setup_config.php    - Configuratore automatico (chiamato da install.bat)
3. test_connection.php - Script di verifica (accessibile via browser)

COME INSTALLARE:
----------------
1. Assicurarsi che XAMPP sia installato in C:\xampp
2. Aprire il Prompt dei Comandi (cmd) come Amministratore (raccomandato)
3. Navigare alla cartella del progetto:
   cd C:\xampp\htdocs\CollaboraNexio
4. Eseguire:
   install.bat
5. Seguire le istruzioni a schermo

OPZIONI MENU INSTALLAZIONE:
----------------------------
[1] Nuova installazione      - Prima installazione del sistema
[2] Reinstallazione          - Reset completo (CANCELLA tutti i dati!)
[3] Verifica installazione   - Controlla lo stato dei componenti
[4] Esci                     - Chiude lo script

CREDENZIALI DI ACCESSO:
------------------------
Amministratore:
• Email: asamodeo@fortibyte.it
• Password: Ricord@1991

Utente Demo:
• Email: user@demo.com
• Password: Demo123!

Utente Special:
• Email: special@demo.com
• Password: Special123!

URL DI ACCESSO:
---------------
• Applicazione: http://localhost/CollaboraNexio
• Test sistema: http://localhost/CollaboraNexio/test_connection.php

VERIFICA POST-INSTALLAZIONE:
-----------------------------
1. Via Browser:
   Aprire http://localhost/CollaboraNexio/test_connection.php
   Verrà mostrato un report JSON con tutti i test

2. Via Command Line:
   php test_connection.php
   Mostra un report dettagliato in formato testo

TROUBLESHOOTING:
----------------
• Se Apache/MySQL non si avviano:
  - Verificare che le porte 80 e 3306 siano libere
  - Controllare il pannello di controllo XAMPP

• Se il database non viene creato:
  - Verificare che MySQL sia in esecuzione
  - Controllare che l'utente root non abbia password

• Se config.php non viene creato:
  - Verificare i permessi di scrittura nella cartella
  - Eseguire cmd come Amministratore

• Per reset completo:
  - Usare opzione [2] dal menu
  - Oppure eliminare manualmente il database 'collaboranexio' da phpMyAdmin

FILE DI LOG:
------------
• install.log - Log dettagliato dell'installazione
• Contiene tutti i passaggi e eventuali errori

SUPPORTO:
---------
In caso di problemi, verificare:
1. Il file install.log per dettagli sugli errori
2. Il test_connection.php per diagnostica componenti
3. I requisiti di sistema sono soddisfatti

================================================================================
                          INSTALLAZIONE COMPLETATA CON SUCCESSO!
================================================================================