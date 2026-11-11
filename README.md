# Iscrizioni — PHP + React

Dashboard privata multi-evento, con una riga per evento: totale Endu, iscrizioni del giorno precedente, storico dell’anno precedente, differenza e percentuale. **Non richiede Node.js, npm, Python, Composer o una build.**

## Requisiti

- PHP **8.2 o superiore**, estensioni **curl** e **pdo_sqlite**, sessioni abilitate.
- HTTPS sull’hosting e una cartella persistente scrivibile fuori dalla directory pubblica.
- Accesso HTTPS a `egs-eventi.s3.eu-west-1.amazonaws.com`.
- Un cron PHP ogni 5 minuti per raccogliere i dati anche a browser chiuso e alle 04:00 italiane.

Le istruzioni per hosting e credenziali sono in [DEPLOY.md](DEPLOY.md). Non serve Render se l’hosting esistente supporta questi requisiti.

## Avvio

1. Eseguire `php bin/setup.php --local` e scegliere nome utente e password (almeno 12 caratteri, da ripetere). Il file `config.local.php` viene creato automaticamente con il solo hash, senza copiarlo a mano. Un’eventuale cartella archivio già configurata viene mantenuta.
2. Per l’hosting HTTPS eseguire invece `php bin/setup.php` senza `--local`, oppure configurare manualmente il file seguendo DEPLOY.md.
3. Avviare la prova locale dalla cartella del progetto:

```sh
php -S 0.0.0.0:5173 -t public
```

In Codespaces aprire la porta 5173 dalla scheda **Ports / Porte**. Per l’hosting pubblico usare Apache/PHP-FPM fornito dal provider, non il server di sviluppo PHP. In produzione mantenere `cookie_secure => true` e HTTPS.

## Eventi e storici

La dashboard conserva eventi, storici e rilevazioni in SQLite sul server. Ogni evento può essere creato senza JSON: la riga mostra che le iscrizioni non sono ancora aperte. Quando il link è disponibile, incollarlo nella configurazione dell’evento. Sono ammessi soltanto i percorsi standard `entrants.json` sul bucket Endu; i redirect non vengono seguiti.

Gli storici si importano come CSV UTF-8:

```csv
data;iscrizioni
2025-11-02;100
2025-11-09;120
```

Selezionare **settimanali**, **giornalieri** o **cumulati** prima di importarli. Ogni importazione sostituisce solo lo storico dell’evento selezionato. Le date possono essere `AAAA-MM-GG` o `GG/MM/AAAA`. Si accettano virgola e punto e virgola come separatore. Includere i periodi a zero.

Il confronto settimanale somma le iscrizioni fino alla settimana più vicina al giorno/mese dell’anno precedente (distanza massima 3 giorni); mostra sia la data scelta sia quella obiettivo. Per giornalieri e cumulati serve la data esatta. Il 29 febbraio usa il 28 febbraio precedente. La variazione è `(attuali − storici) / storici × 100`; con base zero non viene mostrata una percentuale.

Il pulsante **Usa storico Pisa 2025** usa i dati convertiti dall’Excel fornito, con date di riferimento calcolate come 21/12/2025 meno N settimane. Sono interpretati come nuove iscrizioni di ogni settimana. Verificare che lo storico includa lo stesso insieme di gare del totale Endu, in particolare Christmas Run.

## Iscrizioni di ieri

La data visualizzata è selezionabile; “ieri” è sempre il giorno precedente a quella data nel fuso **Europe/Rome**, anche nei passaggi tra ora solare e legale.

Il JSON `entrants.json` espone un totale, non le date delle singole iscrizioni. La dashboard non può ricostruire retroattivamente il numero esatto di iscrizioni di ieri da quel solo totale.

- **Importato**: caricare un CSV `data;iscrizioni` in **Importa iscrizioni giornaliere CSV**. Ogni riga deve contenere le nuove iscrizioni di quel giorno, non il cumulato. Questi dati hanno precedenza sulle stime e vengono associati solo all’evento selezionato. Date già importate vengono aggiornate.
- **Stima**: differenza tra il totale delle **04:00 del giorno indicato** e quello delle **04:00 del giorno successivo**, con tolleranza di 5 minuti. È un saldo netto e può includere cancellazioni/rettifiche. Richiede il cron e due letture consecutive delle 04:00 raccolte correttamente.
- **Non disponibile**: mancano i dati necessari o il totale è diminuito; non viene mostrato uno zero inventato.

La stima non rappresenta il giorno solare 00:00–24:00: ad esempio il saldo mostrato per il 10 novembre usa le letture del 10 novembre alle 04:00 e dell’11 novembre alle 04:00. Le date sono sempre italiane; nei cambi di ora legale/solare l’intervallo effettivo può durare 23 o 25 ore. Le letture della precedente versione a mezzanotte vengono conservate ma non riutilizzate per il nuovo calcolo. Non occorre cancellare o ricreare il database. Un dato storico non disponibile rimane tale finché non viene importato un conteggio effettivo. Cambiando il link di un evento, le rilevazioni del vecchio link non vengono riutilizzate come se appartenessero alla nuova fonte.

La tabella mantiene tutti i dati sulla stessa riga; su schermi stretti si scorre orizzontalmente. I totali storici selezionabili per data esistono solo per i giorni effettivamente rilevati, non vengono stimati all’indietro.

## Raccolta automatica

```sh
php bin/collect.php
```

Il comando legge solo gli eventi con JSON configurato, al massimo ogni 6 ore, più la lettura delle 04:00 italiane. Configurarlo **ogni 5 minuti** nel cron dell’hosting: decide da solo quando occorre una richiesta Endu. `--force` consente una raccolta immediata per diagnosi. Il pulsante **Aggiorna Endu** resta disponibile. Se il cron non è configurato, le pagine aperte aggiornano ogni 6 ore ma non garantiscono il dato di ieri.

## Migrazione dalla vecchia versione

Node.js, Python, Flask, Gunicorn e i relativi file sono stati rimossi dal progetto. React 18.3.1 è incluso in build di produzione già pronta, con licenze: nessun download da CDN durante l’uso e nessuna compilazione.

- Per recuperare gli eventi della vecchia anteprima nello stesso browser e sullo stesso dominio usare **Recupera vecchia anteprima**, poi esportare eventi e storici in JSON e importarli sul nuovo hosting.
- Per un database della precedente versione privata Python, arrestare il vecchio servizio e il cron, fare un backup e copiare `dashboard.sqlite3` nella nuova `data_dir`. La tabella di configurazione esistente viene conservata e le nuove tabelle aggiunte. Non copiare la vecchia cartella delle sessioni: occorre effettuare nuovamente l’accesso.
- Le rilevazioni giornaliere iniziano con questa versione. Gli anni precedenti non possono essere ricostruiti dal JSON attuale.

**Esporta eventi e storici** salva la configurazione, non l’intero archivio di rilevazioni e conteggi giornalieri. Per un backup completo salvare il database in modo consistente tramite il servizio hosting, con raccolta/scritture sospese durante la copia.

## Test

```sh
php tests/run.php
```

La suite verifica confronti, validazione CSV, persistenza, isolamento eventi, letture delle 04:00, cambio ora italiano, stime di ieri, dati effettivi importati e intervallo di raccolta. Non richiede librerie esterne.

Sono inclusi anche `tests/http.php` e `tests/browser.php` per prove su **un server locale con database di test e credenziali di prova**, non sul servizio con dati reali. Le prove browser usano Chromium e un client Chrome DevTools scritto in PHP; non usano Node.js. Dettagli in [tests/README.md](tests/README.md).
