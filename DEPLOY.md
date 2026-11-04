# Dashboard privata su Render

Questa configurazione prepara un sito HTTPS con login riservato e archivio SQLite condiviso. Non è stata creata alcuna risorsa hosting né attivato alcun pagamento.

## Pubblicazione

1. Accedi a https://dashboard.render.com/ e collega l’account GitHub che può leggere `MaxRovatti69/iscrizioni`.
2. Scegli **New → Blueprint**, seleziona quel repository e il branch `main`. Render legge `render.yaml`.
3. Nella configurazione imposta **DASHBOARD_PASSWORD**: scegli una password robusta di almeno 12 caratteri, da conservare in un gestore password. Non scriverla nel repository o in chat. Il nome utente iniziale è **admin**; puoi cambiarlo con **DASHBOARD_USER**.
4. Controlla i costi indicati da Render prima di confermare: il file richiede un servizio **Starter** e un **disco persistente da 1 GB**. Il piano gratuito senza disco non conserva in modo affidabile questi dati fra i deploy.
5. Conferma la creazione. Dopo il deploy apri l’URL HTTPS fornito da Render ed esegui l’accesso. **APP_SECRET** viene generata da Render; non modificarla durante i normali deploy, per non invalidare le sessioni.

Il comando di produzione è `gunicorn wsgi:app --bind 0.0.0.0:$PORT --workers 1 --threads 4 --timeout 60`. Il disco è montato in `/var/data` e l’archivio è `/var/data/dashboard.sqlite3`. Mantieni una sola istanza con questa configurazione SQLite. Render deve poter raggiungere il dominio `egs-eventi.s3.eu-west-1.amazonaws.com`.

## Uso e trasferimento dei dati

La schermata di accesso protegge dashboard, JSON, storici e file del frontend. Un singolo account gestisce tutti gli eventi; le sessioni scadono dopo 12 ore. Il pulsante **Esci** termina la sessione del browser. Per avere account distinti per più persone serve un’ulteriore gestione utenti.

Nel sito online eventi e storici vengono salvati sul disco del server e sono disponibili entrando da altri dispositivi. In caso di salvataggi concorrenti, l’app richiede di ricaricare anziché sovrascrivere modifiche di un’altra pagina.

I dati precedentemente inseriti in Codespaces erano nel browser, legati all’indirizzo dell’anteprima: non si trasferiscono automaticamente al nuovo dominio. Nel nuovo sito ricrea gli eventi e importa i relativi CSV. Per Pisa è disponibile **Usa storico Pisa 2025**. Conserva i CSV originali e organizza il backup del database tramite gli strumenti dell’hosting: il disco persistente non sostituisce un backup.

L’aggiornamento ogni 6 ore avviene mentre una pagina è aperta. Non è un servizio pianificato a browser chiuso.

## Verifica prima dell’uso

Aprendo l’URL in una finestra anonima deve apparire il login. Dopo l’accesso verifica la lettura Endu, crea un evento senza JSON e importa uno storico. Accedi da un altro browser per verificare la presenza dei dati salvati. Riavviando il servizio, eventi e storici devono rimanere disponibili sul disco.

## Test in ambiente di sviluppo

```
python3 -m venv .local/venv
.local/venv/bin/pip install -r requirements.txt
PATH="$PWD/.local/venv/bin:$PATH" npm test
```

`npm run dev` mantiene l’anteprima locale di Codespaces, senza login e con archivio browser: **non usarlo come comando del sito pubblico**. Per il sito riservato si usa esclusivamente il comando Gunicorn sopra; l’avvio fallisce se APP_SECRET o DASHBOARD_PASSWORD non sono configurate.
