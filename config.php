<?php
/**
 * ARES 118 - Configurazione
 * Compila i valori marcati con "CAMBIA" prima di caricare il sito su Altervista.
 */

// ---------- Database MongoDB (Atlas) ----------
// Su Render si imposta come variabile d'ambiente MONGODB_URI (vedi README).
// Formato: mongodb+srv://UTENTE:PASSWORD@cluster0.xxxxx.mongodb.net/   (password con caratteri speciali = URL-encoded)
function env_or(string $k, string $default = ''): string
{
    $v = getenv($k);
    return ($v === false || $v === '') ? $default : $v;
}
define('MONGODB_URI', env_or('MONGODB_URI', 'mongodb://localhost:27017'));
define('MONGODB_DB', env_or('MONGODB_DB', 'ares118'));

// Dietro il proxy di Render l'IP reale arriva in X-Forwarded-For
define('TRUST_PROXY', env_or('TRUST_PROXY', '0') === '1');

// ---------- Bot ----------
// Chiave segreta condivisa con il bot (lunga e casuale, almeno 40 caratteri).
// Il bot la invia nell'header:  Authorization: Bearer <BOT_TOKEN>
define('BOT_TOKEN', env_or('BOT_TOKEN', 'CAMBIA_CON_UNA_CHIAVE_LUNGA_E_CASUALE'));

// Mappa  ID ruolo Discord  =>  ruolo del sito.
// Sostituisci ogni segnaposto ID_... con l'ID numerico del ruolo (Discord: modalita' sviluppatore ->
// Impostazioni server -> Ruoli -> tasto destro sul ruolo -> Copia ID).
// Se una persona ha piu' ruoli vince quello con livello piu' alto.
// Valore 'co' = non e' un ruolo principale ma abilita la Centrale Operativa (centralino).
// PROPOSTA da confermare: i ruoli dirigenziali/di coordinamento gestiscono il personale, gli altri no.
const ROLE_MAP = [
    // Dirigenza ARES 118
    '1494395260317597786' => 'direttore',        // Dirigente ARES 118
    '1494395387828633620' => 'vice_direttore',   // Vice Dirigente ARES 118
    // Coordinamento ARES 118
    '1565785287693566033' => 'coordinatore',     // Referente Medici
    '1565785398913933433' => 'coordinatore',     // Referente Infermieri
    '1494395589616468058' => 'coordinatore',     // Referente Operatori
    '1494395703563386989' => 'coordinatore',     // Referente Emergenze
    '1494382277118005249' => 'coordinatore',     // Referente Formazione
    '1494382276082143394' => 'coordinatore',     // Referente Materiali
    // Gradi alti
    '1494382292750303343' => 'coordinatore',     // Ispettore Operativo
    '1494382295384195153' => 'coordinatore',     // Capo Turno
    // Sanitari
    '1494382299452543176' => 'medico',           // Medico
    '1494382301952344228' => 'infermiere',       // Infermiere
    // Operatori
    '1494382304485703771' => 'soccorritore',     // Capo Equipaggio
    '1494382309733044386' => 'soccorritore',     // Soccorritore Avanzato
    '1494382312924778516' => 'soccorritore',     // Soccorritore Esperto / Operatore DAE
    '1494382314980118620' => 'soccorritore',     // Soccorritore 118
    // In formazione
    '1494382317978914836' => 'recluta',          // Soccorritore in Prova
    '1494382318935081182' => 'recluta',          // Allievo Soccorritore
    // Ancora da indicare (reparto USAR e Centrale Operativa): togli il commento e metti l'ID quando lo hai
    // 'ID_CENTRALINISTA'  => 'co',              // ruolo Discord dei centralinisti
];

// ---------- Ruoli e permessi ----------
// level      : gerarchia (piu' alto = piu' potere). Puoi gestire solo persone di livello pari o inferiore.
// personale  : vede "Gestione personale", "Gestione mezzi" e lo storico shift di tutti
// vedi_tutte : vede le schede di tutto il personale (altrimenti solo le proprie)
// conferma    : conferma i ruoli dirigenziali richiesti da Discord (solo l'amministratore del sito)
// centralino : puo' usare la Centrale Operativa (chiamate e assegnazione mezzi)
// equip      : ruoli a bordo che puo' scegliere quando avvia il servizio su un mezzo
const ROLES = [
    'direttore'      => ['label' => 'Direttore',      'level' => 100, 'conferma' => true, 'personale' => true,  'vedi_tutte' => true,  'centralino' => true,  'equip' => ['Autista', 'Capo Equipaggio', 'Soccorritore', 'Infermiere', 'Medico']],
    'vice_direttore' => ['label' => 'Vice Direttore', 'level' => 90,  'personale' => true,  'vedi_tutte' => true,  'centralino' => true,  'equip' => ['Autista', 'Capo Equipaggio', 'Soccorritore', 'Infermiere', 'Medico']],
    'coordinatore'   => ['label' => 'Coordinatore',   'level' => 80,  'personale' => true,  'vedi_tutte' => true,  'centralino' => true,  'equip' => ['Autista', 'Capo Equipaggio', 'Soccorritore', 'Infermiere', 'Medico']],
    'medico'         => ['label' => 'Medico',         'level' => 60,  'personale' => false, 'vedi_tutte' => true,  'centralino' => false, 'equip' => ['Medico']],
    'infermiere'     => ['label' => 'Infermiere',     'level' => 50,  'personale' => false, 'vedi_tutte' => false, 'centralino' => false, 'equip' => ['Infermiere', 'Capo Equipaggio']],
    'centralinista'  => ['label' => 'Centralinista',  'level' => 45,  'personale' => false, 'vedi_tutte' => false, 'centralino' => true,  'equip' => []],
    'soccorritore'   => ['label' => 'Soccorritore',   'level' => 40,  'personale' => false, 'vedi_tutte' => false, 'centralino' => false, 'equip' => ['Autista', 'Capo Equipaggio', 'Soccorritore']],
    'autista'        => ['label' => 'Autista',        'level' => 30,  'personale' => false, 'vedi_tutte' => false, 'centralino' => false, 'equip' => ['Autista', 'Soccorritore']],
    'recluta'        => ['label' => 'Recluta',        'level' => 10,  'personale' => false, 'vedi_tutte' => false, 'centralino' => false, 'equip' => ['Soccorritore']],
];

define('SITE_NAME', 'ARES 118');
define('SESSION_TIMEOUT_MIN', 120);   // disconnessione automatica per inattivita'
define('PER_PAGE', 20);
define('NOTE_MIN_WORDS', 35);       // parole consigliate nelle note
define('NOTE_MIN_OBBLIGATORIO', false); // true = non salva se le note sono troppo corte
define('SHIFT_MAX_HOURS', 14);    // un turno dimenticato aperto viene chiuso da solo dopo queste ore
