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

## Confronto annuale

Importare un CSV `data;iscrizioni` scegliendo prima fra iscrizioni giornaliere e totale cumulato. Sono ammesse date ISO o GG/MM/AAAA. Il confronto usa giorno e mese della lettura corrente Endu, nell’anno precedente (29 febbraio → 28 febbraio). La variazione è `(attuali - storici) / storici * 100`; con storico zero la percentuale non è definita.

È necessario il dato della data esatta. Per i giornalieri occorre includere ogni giorno dall’inizio della raccolta nell’anno precedente, anche con zero; le lacune intermedie vengono rifiutate. I cumulati devono essere non decrescenti. Lo storico viene salvato in localStorage nel browser corrente, può essere sostituito o rimosso e non è condiviso. L’ultimo confronto segue la data dell’ultima lettura Endu e può confrontare il dato attuale infragiornaliero con lo storico di fine giornata.

### Confronto settimanale

Selezionando iscrizioni settimanali, il sistema somma le nuove iscrizioni fino alla settimana più vicina al giorno/mese dell’anno precedente. La data scelta deve distare al massimo 3,5 giorni; in parità si preferisce la precedente. Le settimane devono essere consecutive. La dashboard mostra data obiettivo e data effettivamente usata.

Il pulsante **Usa storico Pisa 2025** carica `data/pisa-2025-settimane.csv`, convertito dalla riga 2025 del file utente `Iscritti_per_settimana.xls`: ogni riferimento è la data gara 21/12/2025 meno N settimane. I valori sono interpretati come nuove iscrizioni settimanali, senza stime giornaliere. La somma comprende solo le 21 settimane presenti nel file. Verificare che lo storico includa le stesse gare del totale Endu (in particolare Christmas Run). Al 04/11 il riferimento selezionato è 02/11/2025, totale 1.418.

## Layout eventi

Il riepilogo è una tabella con una riga per evento: nome, iscritti attuali, storico selezionato con data obiettivo, differenza e variazione percentuale. I dati restano su una sola riga; su schermi stretti la tabella scorre orizzontalmente. Maratona di Pisa (`00102708`) è l’evento predefinito. Nel pannello Gestisci eventi è possibile aggiungere altre righe senza JSON e associare a ognuna il proprio storico.

## Eventi prima dell’apertura iscrizioni

1. In **Gestisci eventi**, inserire il nome (anche l’edizione, es. Pisa 2027) e premere **Crea evento**. La riga mostra “Iscrizioni non ancora aperte”; non vengono effettuate richieste per eventi senza URL.
2. Selezionare l’evento da configurare e importare il suo CSV nel pannello storico. Il file Pisa 2025 predefinito è disponibile solo per la riga Pisa originale. Ogni importazione sostituisce lo storico solo dell’evento selezionato.
3. All’apertura iscrizioni, selezionare l’evento e aggiungere l’URL `entrants.json` nel campo facoltativo, quindi **Salva evento**. Sono ammessi soltanto URL HTTPS del bucket Endu con il percorso standard. Il backend legge la fonte corrispondente alla riga.

Senza dato attuale, differenza e percentuale sono indicate con “—”. Lo storico può essere conservato anche prima che sia disponibile l’anno da confrontare. Ogni riga ha il proprio stato di lettura: un errore su un evento non blocca gli altri. I dati di configurazione e lo storico sono conservati esclusivamente nel localStorage del browser corrente; il precedente storico Pisa viene migrato automaticamente al primo utilizzo.

Dopo l’aggiornamento di questa versione occorre **riavviare `npm run dev`** per caricare il backend con supporto a più URL.
