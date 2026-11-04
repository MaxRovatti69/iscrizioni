# Iscrizioni Endu

Dashboard del numero totale di iscritti alla **Maratona di Pisa** (evento Endu `00102708`).

## Avvio

Richiede Python 3; Node.js 20+ serve per eseguire i test. Nessuna dipendenza esterna da installare.
Dalla cartella del progetto: `npm run dev`. Il server usa la porta 5173. Test: `npm test`.

In GitHub Codespaces, aprire la scheda **Ports / Porte** e selezionare **Apri nel browser** per la porta **5173**, mantenendo il terminale del server in esecuzione. Se la porta non compare, aggiungerla manualmente. Il link dell’anteprima si apre nel browser, non nel terminale.

## Fonte dati

Il server legge esclusivamente:
https://egs-eventi.s3.eu-west-1.amazonaws.com/00000000/00100000/00102700/00102708/data/entrants.json

Il formato atteso è `{"data":[{"count":"3403"}]}`. Il numero viene verificato e formattato in italiano. Nessuna lettura degli altri JSON.

Il browser richiede `/api/endu/counters` all’apertura, automaticamente ogni 6 ore mentre la pagina è aperta e premendo Aggiorna Endu. Se la scheda viene sospesa dal browser, una lettura scaduta viene recuperata quando torna visibile. Il server Codespaces deve rimanere attivo; non sono pianificate letture a pagina chiusa. L’orario indica la lettura, non l’aggiornamento alla fonte. In caso di errore rimane il valore precedente con avviso. Le richieste hanno un timeout; il server accetta solo l’URL fisso e mantiene la verifica TLS.

Il dominio della fonte deve essere consentito dalla rete. Non sono richieste credenziali. I precedenti moduli CSV rimangono nel repository ma non sono caricati dall’interfaccia attuale.
