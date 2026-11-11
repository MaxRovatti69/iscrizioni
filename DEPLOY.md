# Pubblicazione privata su hosting PHP

## Prima di caricare i file

Verificare nel pannello del provider:

- PHP 8.2+ con **cURL**, **PDO SQLite** e sessioni.
- Certificato HTTPS attivo.
- Una cartella persistente scrivibile per SQLite e sessioni, **fuori dalla cartella pubblica**.
- Accesso HTTPS al bucket Endu e cron PHP ogni 5 minuti.

Non servono Node.js, npm, Python, Composer, Docker o comandi di build. Se il piano supporta soltanto MySQL e non SQLite, va adattato l’archivio prima del deploy.

## Struttura consigliata

```
cartella-app/
  app/
  bin/
  data/
  public/           ← document root del dominio o sottodominio
  config.local.php  ← configurazione privata, mai in Git
  var/              ← database, sessioni e lock; non accessibile dal web
```

Caricare il progetto via Git o SFTP. Impostare la document root su **public/**. Se il provider non consente di modificarla, mettere `app/`, `bin/`, `data/`, configurazione e archivio in una cartella privata e adattare i percorsi di inclusione in `public/index.php`: non caricare tutto indistintamente nella directory web. Il file `.htaccess` alla radice nega l’accesso diretto ai sorgenti; non sostituisce una corretta document root e non ha effetto su Nginx.

## Credenziali

Procedura guidata consigliata: eseguire `php bin/setup.php` e scegliere nome utente e password. Il file privato e il relativo hash vengono salvati automaticamente, preservando l’eventuale percorso archivio esistente. Per la sola anteprima HTTP di Codespaces usare `php bin/setup.php --local`. La password va digitata due volte e non viene stampata.

Configurazione manuale alternativa:

1. Eseguire `php bin/password.php` sul proprio computer con PHP o nel terminale dell’hosting. Digitare una password di almeno 12 caratteri: viene restituito solo il relativo hash.
2. Copiare `config.example.php` come `config.local.php`.
3. Impostare `username`, `password_hash` con l’hash generato, `data_dir` con il percorso privato scrivibile e `cookie_secure => true`.
4. Conservare il file fuori da `public/`, con permessi di lettura limitati al proprio utente/processo PHP. Non inserire password o hash nel repository o in chat.

In alternativa si possono usare le variabili `DASHBOARD_USER`, `DASHBOARD_PASSWORD_HASH`, `DASHBOARD_DATA_DIR`, `DASHBOARD_COOKIE_SECURE`. Per questa versione non sono più usate `APP_SECRET` e `DASHBOARD_PASSWORD` della precedente configurazione Python.

L’avvio senza configurazione valida fallisce senza rendere pubblici eventi o dati. Un singolo account gestisce tutti gli eventi. La sessione dura al massimo 12 ore, ha cookie HttpOnly/SameSite e viene chiusa con **Esci**. Configurando più persone serve una successiva gestione utenti distinta.

## Cron per i dati di ieri

Nel pannello dell’hosting configurare, ogni cinque minuti, l’equivalente di:

```
*/5 * * * * /percorso/php /cartella-app/bin/collect.php
```

Usare la versione corretta di PHP indicata dal provider. Tutti i calcoli applicativi usano **Europe/Rome**. Il cron ogni 5 minuti funziona anche se il server usa UTC: il programma aggiorna ogni 6 ore e raccoglie una rilevazione tra 04:00 e 04:04:59 italiane. Non esporre il comando cron con un URL pubblico.

Sono necessarie due letture delle 04:00 di giorni consecutivi prima di ottenere una stima di ieri. Il dato mostrato per il 10 novembre, per esempio, sarà il totale dell’11 novembre alle 04:00 meno quello del 10 novembre alle 04:00. Prima della seconda lettura il dato rimane non disponibile. La stima segue questo intervallo, non il giorno solare. I conteggi effettivi giornalieri possono essere importati subito via CSV. I saldi da totali cumulati vengono etichettati come stime perché cancellazioni e rettifiche possono modificarli.

Senza cron le pagine aperte continuano ad aggiornare ogni 6 ore; il dato di ieri potrebbe non essere disponibile. Non viene retrocalcolato dal totale corrente.

## Verifiche dopo il caricamento

1. Aprire l’URL in una finestra privata: deve comparire il login, non la dashboard.
2. Entrare e verificare la lettura Endu.
3. Creare un evento senza JSON e caricare il relativo storico.
4. Accedere da un secondo browser: eventi e storici devono essere presenti.
5. Verificare che `config.local.php`, `app/` e `var/dashboard.sqlite3` non siano accessibili dal web.
6. Eseguire una volta il comando di raccolta, poi controllare le esecuzioni cron e i dati dopo due letture consecutive delle 04:00.

Conservare backup completi del database. Il solo file JSON esportato dalla dashboard contiene eventi e storici, non tutte le rilevazioni giornaliere. Il cron e le scritture vanno sospesi durante una copia diretta del database.

Queste modifiche sono state preparate nel repository. Nessun hosting viene creato o pubblicato automaticamente.

## Aggiornamento dal precedente calcolo a mezzanotte

Caricare i file aggiornati mantenendo `config.local.php` e la cartella archivio esistenti. La nuova tabella delle rilevazioni alle 04:00 viene creata automaticamente. Le letture precedenti a mezzanotte non vengono eliminate ma non possono sostituire quelle delle 04:00. Il cron ogni 5 minuti resta lo stesso; non occorre ricreare eventi o storici. Dopo l’aggiornamento attendere due rilevazioni consecutive delle 04:00 oppure importare un conteggio giornaliero effettivo.
