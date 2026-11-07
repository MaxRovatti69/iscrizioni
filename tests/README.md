# Test PHP e browser

Tutti i test usano PHP, senza Node.js o Python. Requisiti: PHP 8.2+, cURL e PDO SQLite. Per le prove visuali serve anche Chromium.

## Suite funzionale

```
php tests/run.php
```

Usa un database temporaneo e lo elimina alla fine. Non legge o modifica l’archivio dell’applicazione.

## Prove HTTP su un database separato

Creare una fixture con credenziali casuali, fuori dal repository (usare una cartella nuova):

```
php tests/fixture.php /tmp/iscrizioni-browser
php -d auto_prepend_file=/tmp/iscrizioni-browser/start.php -S 127.0.0.1:5173 -t public
```

In un altro terminale:

```
php tests/http.php http://127.0.0.1:5173 /tmp/iscrizioni-browser/credentials.json
```

Le prove richiedono accesso al JSON Endu reale e inseriscono un evento di prova e un conteggio giornaliero nel database temporaneo. Non usare il database con dati reali. Non copiare o condividere il file delle credenziali.

## Browser reale

Dopo le prove HTTP, avviare Chromium con un profilo di prova e debug su loopback:

```
chromium --headless --disable-gpu --remote-debugging-port=9222 --user-data-dir=/tmp/iscrizioni-chromium about:blank
php tests/browser.php /tmp/iscrizioni-browser/credentials.json
```

In un container che non permette la sandbox Chromium potrebbe essere necessario il flag `--no-sandbox` solo per questa prova isolata. Il test effettua il login, crea un evento, carica lo storico Pisa, verifica la colonna di ieri, cambia la data visualizzata e verifica la persistenza. Il client Chrome DevTools è implementato in `tests/Cdp.php`, senza librerie esterne. Gli screenshot desktop/mobile vengono salvati nella cartella `exports/` accanto al checkout.

Il test browser usa il 4 novembre 2026 come riferimento fisso per il CSV Pisa 2025 e calcola dinamicamente oggi/ieri per i conteggi giornalieri. Prima di una nuova esecuzione usare un database e un profilo Chromium nuovi.

Al termine fermare i due processi e rimuovere solo la fixture di prova e il profilo Chromium creati per il test.
