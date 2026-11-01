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
`bot/esempio_bot.py` è un esempio completo (discord.py) con sincronizzazione ogni 30 minuti e comando `!resetpw`.

Le modifiche fatte a mano dalla pagina Personale (ruolo, disattivazione) mettono la persona in stato **Manuale**:
il bot non le sovrascrive finché non premi "Affida al bot".

## 3b. Centrale Operativa (centralino) e mezzi

- **Gestione mezzi** (`mezzi.php`, solo ruoli con gestione personale): aggiungi/rinomina/disattiva i mezzi. Al primo avvio vengono creati Bravo 01-03, India 01-02, Sierra 01, Mike 01-02, Alfa 01, Victor 01-02.
- **Gestione personale** (`personale.php` → *Modifica*): gli amministrativi impostano nome, cognome, matricola, grado, ruolo e l'abilitazione CO. Il bot NON sovrascrive questi dati: aggiorna solo ruolo, stato e flag CO.
- **Inizio turno**: la persona sceglie mezzo e ruolo (Autista, Capo Squadra, Soccorritore, Infermiere, Medico) oppure "Centrale Operativa". Il sistema impedisce ruoli doppi sullo stesso mezzo e ruoli non consentiti dal proprio ruolo.
- **Centralino** (`centralino.php`, `chiamata_nuova.php`): il CO registra la chiamata (CH-ANNO-NNNNN), la assegna ai mezzi *liberi* o la lascia in attesa, può richiamare un mezzo o annullare la chiamata. La bacheca si aggiorna da sola.
- **Equipaggio**: vede l'intervento in dashboard e avanza gli stati (Partiti → Sul posto → Trasporto → Arrivo PS → Libero). La scheda di soccorso si apre già precompilata (equipaggio, mezzo, codice, orari, tipo chiamata).
- Nel bot il ruolo Discord con valore `'co'` in `ROLE_MAP` abilita il centralino (`ID_CENTRALINISTA`).

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
