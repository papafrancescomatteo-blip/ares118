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
    'ID_DIRIGENTE_ARES_118'            => 'direttore',
    'ID_VICE_DIRIGENTE_ARES_118'       => 'vice_direttore',
    // Coordinamento ARES 118
    'ID_REFERENTE_MEDICI'              => 'coordinatore',
    'ID_REFERENTE_INFERMIERI'          => 'coordinatore',
    'ID_REFERENTE_OPERATORI'           => 'coordinatore',
    'ID_REFERENTE_EMERGENZE'           => 'coordinatore',
    'ID_REFERENTE_FORMAZIONE'          => 'coordinatore',
    'ID_REFERENTE_MATERIALI'           => 'coordinatore',
    // Gradi alti
    'ID_ISPETTORE_OPERATIVO'           => 'coordinatore',
    'ID_CAPO_TURNO'                    => 'coordinatore',
    // Reparto USAR
    'ID_DIRETTORE_GENERALE_USAR'       => 'coordinatore',
    'ID_DIRETTORE_SANITARIO'           => 'coordinatore',
    'ID_DIRETTORE_UOC_GRANDI_EVENTI'   => 'coordinatore',
    'ID_COORDINATORE_INFERMIERISTICO'  => 'coordinatore',
    'ID_OPERATORE_USAR_EXPERT'         => 'soccorritore',
    'ID_OPERATORE_USAR_EFFECTIVE'      => 'soccorritore',
    'ID_OPERATORE_IN_AFFIANCAMENTO'    => 'recluta',
    'ID_DISCENTE'                      => 'recluta',
    'ID_CANDIDATO'                     => 'recluta',
    // Sanitari
    'ID_MEDICO_EMERGENZA_URGENZA_118'      => 'medico',
    'ID_INFERMIERE_EMERGENZA_URGENZA_118'  => 'infermiere',
    // Centrale Operativa (ruolo Discord dei centralinisti: da indicare)
    'ID_CENTRALINISTA'                 => 'co',
];

// ---------- Ruoli e permessi ----------
// level      : gerarchia (piu' alto = piu' potere). Puoi gestire solo persone di livello pari o inferiore.
// personale  : vede "Gestione personale", "Gestione mezzi" e lo storico shift di tutti
// vedi_tutte : vede le schede di tutto il personale (altrimenti solo le proprie)
// centralino : puo' usare la Centrale Operativa (chiamate e assegnazione mezzi)
// equip      : ruoli a bordo che puo' scegliere quando avvia il servizio su un mezzo
const ROLES = [
    'direttore'      => ['label' => 'Direttore',      'level' => 100, 'personale' => true,  'vedi_tutte' => true,  'centralino' => true,  'equip' => ['Autista', 'Capo Squadra', 'Soccorritore', 'Infermiere', 'Medico']],
    'vice_direttore' => ['label' => 'Vice Direttore', 'level' => 90,  'personale' => true,  'vedi_tutte' => true,  'centralino' => true,  'equip' => ['Autista', 'Capo Squadra', 'Soccorritore', 'Infermiere', 'Medico']],
    'coordinatore'   => ['label' => 'Coordinatore',   'level' => 80,  'personale' => true,  'vedi_tutte' => true,  'centralino' => true,  'equip' => ['Autista', 'Capo Squadra', 'Soccorritore', 'Infermiere', 'Medico']],
    'medico'         => ['label' => 'Medico',         'level' => 60,  'personale' => false, 'vedi_tutte' => true,  'centralino' => false, 'equip' => ['Medico']],
    'infermiere'     => ['label' => 'Infermiere',     'level' => 50,  'personale' => false, 'vedi_tutte' => false, 'centralino' => false, 'equip' => ['Infermiere', 'Capo Squadra']],
    'centralinista'  => ['label' => 'Centralinista',  'level' => 45,  'personale' => false, 'vedi_tutte' => false, 'centralino' => true,  'equip' => []],
    'soccorritore'   => ['label' => 'Soccorritore',   'level' => 40,  'personale' => false, 'vedi_tutte' => false, 'centralino' => false, 'equip' => ['Autista', 'Capo Squadra', 'Soccorritore']],
    'autista'        => ['label' => 'Autista',        'level' => 30,  'personale' => false, 'vedi_tutte' => false, 'centralino' => false, 'equip' => ['Autista', 'Soccorritore']],
    'recluta'        => ['label' => 'Recluta',        'level' => 10,  'personale' => false, 'vedi_tutte' => false, 'centralino' => false, 'equip' => ['Soccorritore']],
];

define('SITE_NAME', 'ARES 118');
define('SESSION_TIMEOUT_MIN', 120);   // disconnessione automatica per inattivita'
define('PER_PAGE', 20);
define('NOTE_MIN_WORDS', 35);       // parole consigliate nelle note
define('NOTE_MIN_OBBLIGATORIO', false); // true = non salva se le note sono troppo corte
define('SHIFT_MAX_HOURS', 14);    // un turno dimenticato aperto viene chiuso da solo dopo queste ore
