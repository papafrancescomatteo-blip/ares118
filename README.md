# ARES 118 – Dashboard operativa (PHP + MongoDB Atlas, deploy su Render)

Login con ruoli, schede di soccorso (Relazione di Soccorso), storico verbali personale, firma servizio/shift,
pagina "Gestione personale" riservata ad alcuni ruoli, endpoint per il bot.

## 1. Deploy su Render

Render non esegue PHP in modo nativo: il progetto usa un container Docker (`Dockerfile`) con Apache + PHP 8.3
e l'estensione MongoDB. I dati stanno su **MongoDB Atlas**.

### A. MongoDB Atlas
1. **Database Access**: l'utente del database deve avere il permesso *Read and write to any database*.
2. **Network Access**: aggiungi `0.0.0.0/0` (accesso da ovunque), perche' Render gratuito non ha un IP fisso.
3. **Connect → Drivers**: copia l'indirizzo `mongodb+srv://UTENTE:<db_password>@cluster0.xxxxx.mongodb.net/`
   e sostituisci `<db_password>` con la password. Se la password contiene simboli (`@ : / ? # %`) vanno codificati
   (es. `@` diventa `%40`); piu' semplice usare una password solo con lettere e numeri.
4. Il database `ares118` e le collezioni (`users`, `shifts`, `schede`, `login_log`, `counters`) si creano da soli al primo avvio.

### B. GitHub
Carica il contenuto di questa cartella in un repository **privato** (la radice del repo deve contenere `Dockerfile`).

### C. Render
*New → Blueprint* (legge `render.yaml`) oppure *New → Web Service → Docker*, scegli il repo. Variabili d'ambiente:

| Variabile | Valore |
|---|---|
| `MONGODB_URI` | l'indirizzo di Atlas del punto A.3 (con la password) |
| `MONGODB_DB` | `ares118` |
| `TRUST_PROXY` | `1` |
| `ADMIN_PASSWORD` | password iniziale di "Admin ARES" (se non la metti ne viene generata una e scritta nei log) |
| `BOT_TOKEN` | chiave lunga casuale (con il Blueprint la genera Render); la stessa va data al bot |

Il primo deploy impiega alcuni minuti. All'avvio il sito crea gli indici e l'utente `Admin ARES`:
accedi con quelle credenziali, ti verra' chiesto di cambiare la password.
Il bot chiama `https://NOME-SERVIZIO.onrender.com/api/bot_sync.php` (vedi sezione 3).

Note sul piano gratuito: dopo 15 minuti senza visite il servizio si "addormenta" e la prima richiesta impiega circa un minuto;
le sessioni vengono perse e bisogna rifare il login. Con un piano a pagamento resta sempre attivo.

## 2. Ruoli

Definiti in `config.php` (`ROLES`). Per ogni ruolo:

| Campo | Significato |
|---|---|
| `level` | gerarchia: puoi gestire solo persone di livello pari o inferiore al tuo |
| `personale` | vede "Gestione personale" e lo storico shift di tutti |
| `vedi_tutte` | vede le schede di tutto il personale (altrimenti solo le proprie) |

Ogni persona vede sempre il proprio storico di verbali e di shift.

## 3. Bot

Nel file `config.php`, `ROLE_MAP` collega **l'ID del ruolo Discord** al ruolo del sito.
Sostituisci i segnaposto `ID_RUOLO_...` con gli ID veri (Discord → Impostazioni server → Ruoli → tasto destro → Copia ID,
con la modalità sviluppatore attiva). Se una persona ha più ruoli vince quello di livello più alto;
chi non ha nessun ruolo mappato non ottiene l'account (o viene disattivato).

Il bot chiama l'endpoint:

```
POST https://NOME-SERVIZIO.onrender.com/api/bot_sync.php
Authorization: Bearer <BOT_TOKEN>        (alternativa: header X-Bot-Token)
Content-Type: application/json

{
  "deactivate_missing": false,
  "users": [
    { "discord_id": "123456789", "nome": "Francesco", "cognome": "Papa",
      "matricola": "AS-12", "roles": ["ID_RUOLO_1", "ID_RUOLO_2"], "reset_password": false }
  ]
}
```

Risposta: `created` (nuovi account con username e password temporanea, da inviare in DM),
`passwords` (reset richiesti), `updated`, `deactivated`.
L'**username di accesso è "Nome Cognome"**. Le password sono salvate solo come hash: il bot è l'unico momento
in cui la password in chiaro è visibile, e al primo login viene chiesto di cambiarla.
I moduli pronti per il tuo bot sono in `bot/` (vedi sezione 3c): sincronizzazione ogni 30 minuti, invio credenziali dal pulsante del sito e comando `!resetpw`.

Le modifiche fatte a mano dalla pagina Personale (ruolo, disattivazione) mettono la persona in stato **Manuale**:
il bot non le sovrascrive finché non premi "Affida al bot".

## 3b. Centrale Operativa, tablet di bordo e mezzi

- **Gestione mezzi** (`mezzi.php`, solo ruoli con gestione personale): aggiungi/rinomina/disattiva i mezzi. Al primo avvio vengono creati Bravo 01-03, India 01-02, Sierra 01, Mike 01-02, Alfa 01, Victor 01-02.
- **Gestione personale** (`personale.php` → *Modifica*): gli amministrativi impostano nome, cognome, matricola, grado, ruolo e abilitazione CO. Il bot NON sovrascrive questi dati.
- **Inizio turno**: la persona sceglie mezzo e ruolo (Autista, Capo Equipaggio, Soccorritore, Infermiere, Medico) oppure "Centrale Operativa". Il sistema impedisce ruoli doppi sullo stesso mezzo.
- **Tablet di bordo**: dopo l'avvio su un mezzo la home diventa il tablet: equipaggio, interventi, schede da compilare, orologio. Quando il centralino assegna una chiamata il tablet **suona** (due toni ripetuti, barra rossa lampeggiante, vibrazione) finché non si silenzia o si avanza lo stato. I browser vogliono un tocco per attivare l'audio: se serve compare "Attiva audio".
- **Chi gestisce l'intervento**: se a bordo c'è una sola persona, fa tutto lei. Se sono più persone, solo il **Capo Equipaggio** avanza gli stati e compila la scheda (se manca: Medico, poi Infermiere, Autista, Soccorritore). Gli altri vedono tutto e sentono il suono, senza pulsanti.
- **Centralino** (`centralino.php`, `chiamata_nuova.php`): il CO registra la chiamata (CH-ANNO-NNNNN), la assegna ai mezzi *liberi* o la lascia in attesa, può richiamare un mezzo o annullare la chiamata.
- **Scheda di soccorso**: 7 schermate con barra di avanzamento (Missione, Paziente, Valutazione, Prestazioni, Eventi critici e rifiuti, Esito e note, Equipaggio e firme), precompilata dall'intervento.

## 3c. Account, credenziali e ruoli dal bot

- Il bot **crea e aggiorna gli account** ogni 30 minuti leggendo i ruoli Discord, ma **non scrive a nessuno**.
- In *Gestione personale* il pulsante **"Invia credenziali"** chiede al bot di mandare in DM a ciascuno username e **password temporanea** (da cambiare al primo accesso). Il bot lo esegue entro un minuto e il sito mostra quanti sono stati inviati e chi ha i DM chiusi (per loro: "Reset pw"). Ci sono anche "Reinvia a tutti" e "Invia DM" sulla singola persona.
- **Conferma ruoli**: Direttore, Vice Direttore e Coordinatori arrivano da Discord solo come *richiesta*. Finché l'amministratore del sito (ruolo Direttore) non preme **Conferma** in *Gestione personale*, la persona resta con il ruolo attuale (i nuovi account partono come Recluta). "Rifiuta" ricorda la scelta: il bot non richiede lo stesso ruolo.
- Per il bot: aggiungi `bot/ares_sync.js` (Node, discord.js v14) o `bot/ares_sync.py` (discord.py) al tuo bot e imposta `ARES_SITE_URL`, `ARES_BOT_TOKEN` (= `BOT_TOKEN` di Render), `ARES_GUILD_ID` (vedi `bot/.env.esempio`).

## 3d. Se il bot non invia i DM

1. In **Gestione personale** guarda il riquadro "Bot": deve dire *collegato* (ultimo contatto pochi secondi fa). Se non lo è, il modulo non è caricato o il token non coincide con `BOT_TOKEN` di Render.
2. Il modulo legge da solo il file `.env` accanto a `ares_sync.js` (`ARES_SITE_URL`, `ARES_BOT_TOKEN`, `ARES_GUILD_ID`).
3. Sul server Discord, come amministratore: `!ares-stato` mostra il collegamento, `!ares-invia` forza l'invio delle credenziali in sospeso.
4. Gli utenti devono avere i DM aperti ai membri del server; altrimenti compaiono come "non raggiunti" e si può riprovare.

## 3e. Aspetto, tablet e PDF

- Tema blu scuro con sfumature rosse (home, login, tablet, centrale operativa); le schede restano chiare per la lettura.
- All'avvio del servizio con un mezzo si apre il **tablet di bordo**: solo chiamate in arrivo, schede da compilare, equipaggio e i due pulsanti *Metti in pausa* / *Termina servizio*. Suono a campana (tre note + nota acuta), annuncio vocale in italiano, vibrazione, schermo sempre acceso; alla prima apertura serve un tocco su "Attiva audio e continua" (regola dei browser).
- Il PDF della scheda è una pagina unica nel modulo "Relazione di soccorso" (intestazioni blu/rosse, etichette azzurre, caselle bianche). Si genera nel browser (html2pdf da cdnjs): serve internet sul dispositivo.

## 3f. Gestioni, avvisi e shift

- **Gestione personale** ora è ordinata dal grado più alto al più basso (poi ruolo e nome).
- **Username** = solo nome e cognome (il soprannome Discord "Nome Cognome | AS-05" viene ripulito dal bot e dal sito; i vecchi account si sistemano da soli alla prima apertura di Gestione personale, e il vecchio username continua a funzionare per entrare).
- **Gestione settimanale** (pulsante rosso in Gestione personale): per ogni persona esito (nessun provvedimento / promozione / degrado / licenziamento), nuovo grado, avvertimento, nota di merito. Si può salvare come bozza; "Pubblica" è attivo solo quando tutte le persone sono state gestite. Alla pubblicazione: provvedimenti applicati, **tutti gli shift azzerati** (anche quelli attivi: chiusi e archiviati), schermata con OK a tutti (chi non è sul sito la vede alla prima apertura), messaggio automatico del bot nel canale.
- **Gestione singola** (pulsante "Gestisci" su ogni riga): avvertimento, nota di merito, promozione, degrado o licenziamento immediati, con schermata alla persona e messaggio del bot.
- **Licenziamento**: credenziali eliminate subito; nella schermata di login compare "Sei stato licenziato per il seguente motivo: …" finché la persona non preme OK. Resta nell'elenco "Licenziati" con il pulsante Riassumi.
- **Canale del bot**: il bot cerca un canale testuale il cui nome contiene "gestioni". Se il nome è diverso, metti nel `.env` del bot `ARES_CANALE_GESTIONI=<ID del canale>`. L'esito si vede nella pagina Gestione settimanale. Serve aggiornare `ares_sync.js` sul bot.
- **Shift degli altri** (Storico shift · tutto il personale): aggiungi o togli minuti a uno shift, eliminalo, o aggiungi uno shift a mano.

## 3g. Ruoli Discord (aggiornati il 6 novembre)

Direttore Generale ARES 118 → **Direttore** (amministratore del sito); Direttore Sanitario e Direttore Amministrativo → Vice Direttore; le cinque U.O.C., Ispettore Operativo e Capo Turno → Coordinatore; Medico e Infermiere Emergenza Urgenza 118 → Medico / Infermiere; Soccorritore Avanzato, Esperto e 118 → Soccorritore; Soccorritore in Formazione e Aspirante Soccorritore → Recluta. Gli ID sono in `config.php` (`ROLE_MAP`). I gradi hanno gli stessi nomi dei ruoli e i vecchi (Dirigente, Referente…) si aggiornano da soli.

## 4. Personalizzare

* **Logo**: metti `logo.png` (o `.jpg`/`.webp`/`.svg`) in `assets/img/`. Se c'è, sostituisce quello di esempio.
* **Elenchi della scheda** (patologie, dinamiche, codici patologia, trattamenti, luoghi, mezzi…): `includes/options.php`.
* **Note**: `NOTE_MIN_WORDS` (parole consigliate) e `NOTE_MIN_OBBLIGATORIO` (true = impedisce il salvataggio) in `config.php`.
* **Colori e stile**: `assets/css/style.css` (variabili in cima).

## 5. Sicurezza (già inclusa)

Password con hash bcrypt, filtri sui dati sempre convertiti in testo (nessuna iniezione NoSQL), token CSRF su ogni modulo, blocco dopo 8 tentativi falliti in 15 minuti,
cookie di sessione protetti, disconnessione per inattività (2 ore), `config.php` e cartelle interne non scaricabili.
Ogni scheda è visibile solo all'autore e ai ruoli con `vedi_tutte`. I dati del compilatore nella scheda vengono
sempre presi dall'account, mai dal modulo.

## Struttura

```
index.php            login               dashboard.php     menu + firma servizio
scheda_nuova.php     Relazione di Soccorso   scheda_view.php   lettura / PDF / stampa
schede.php           storico schede      shifts.php        storico shift
personale.php        gestione personale  password.php      cambio password
api/bot_sync.php     endpoint del bot    includes/         codice condiviso (non accessibile dal web)
tools/install_db.php indici + utente Admin all'avvio (solo da terminale)
tests/               prove automatiche con finto MongoDB (non finiscono nel container)
```
