<?php
/**
 * Elenchi usati nella scheda di soccorso.
 * Puoi aggiungere/togliere voci liberamente: basta tenere la sintassi.
 */

const LUOGHI = [
    'C' => 'C - Casa',
    'S' => 'S - Strada',
    'L' => 'L - Lavoro',
    'P' => 'P - Luogo Pubblico',
    'K' => 'K - Scuola',
    'I' => 'I - Impianto Sportivo',
    'A' => 'A - Altro',
];

const CODICI_PARTENZA = [
    'bianco' => 'Bianco', 'giallo' => 'Giallo', 'verde' => 'Verde', 'rosso' => 'Rosso',
];

const TIPI_CHIAMATA = [
    'nue112' => 'Centralino NUE 112', 'forze_ordine' => "Forze dell'ordine",
    'vvf' => 'Vigili del fuoco', 'aci' => 'ACI Global', 'altro' => 'Altro',
];

const MEZZI = [
    'msb' => 'Mezzo di soccorso base (MSB)',
    'msi' => 'Mezzo di soccorso infermieristico (MSI)',
    'msa' => 'Mezzo di soccorso avanzato (MSA)',
    'am'  => 'Automedica (AM)',
    'ai'  => 'Autoinfermieristica (AI)',
];

const POSIZIONI = [
    'supina' => 'Supina', 'prona' => 'Prona', 'seduta' => 'Seduta',
    'semi_seduta' => 'Semi-seduta', 'eretta' => 'Eretta',
    'laterale' => 'Laterale di sicurezza', 'altro' => 'Altro',
];

const AVPU = [
    'A' => 'A (Alert)', 'V' => 'V (Verbal)', 'P' => 'P (Pain)', 'U' => 'U (Unresponsive)',
];

const GCS_E = [4 => 'E4 - SPONTANEA', 3 => 'E3 - ALLA VOCE', 2 => 'E2 - AL DOLORE', 1 => 'E1 - NESSUNA RISPOSTA'];
const GCS_V = [5 => 'V5 - ORIENTATO', 4 => 'V4 - CONFUSO', 3 => 'V3 - PAROLE INAPPROPRIATE', 2 => 'V2 - SUONI INCOMPRENSIBILI', 1 => 'V1 - NESSUNA RISPOSTA'];
const GCS_M = [6 => 'M6 - OBBEDISCE AI COMANDI', 5 => 'M5 - LOCALIZZA IL DOLORE', 4 => 'M4 - RITIRA ARTO AL DOLORE', 3 => 'M3 - FLESSORIA ANOMALA', 2 => 'M2 - ESTENSORIA ANOMALA', 1 => 'M1 - NESSUNA RISPOSTA'];

const CODICI_RIENTRO = [
    'bianco' => 'Bianco', 'verde' => 'Verde', 'giallo' => 'Giallo', 'rosso' => 'Rosso', 'nero' => 'Nero',
];

// Colori usati per i badge dei codici
const CODICI_COLORI = [
    'bianco' => '#e5e7eb', 'verde' => '#16a34a', 'giallo' => '#eab308', 'rosso' => '#dc2626', 'nero' => '#111827',
];

// Elenchi con filtro "scrivi per filtrare" (si puo' comunque scrivere a mano).
// Sono elenchi di partenza: mandami quelli completi e li sostituisco.
const PATOLOGIE = [
    'Cardiocircolatoria', 'Respiratoria', 'Neurologica', 'Traumatica', 'Metabolica',
    'Intossicazione', 'Ustione', 'Ostetrico-ginecologica', 'Psichiatrica', 'Allergica',
    'Ambientale (caldo/freddo)', 'Altra patologia', 'Non rilevata',
];
const DINAMICHE = [
    'Incidente stradale', 'Incidente sul lavoro', 'Caduta', 'Aggressione', 'Ferita da arma',
    'Incidente domestico', 'Incidente sportivo', 'Malore', 'Annegamento', 'Altra dinamica',
];
const CODICI_PATOLOGIA = [
    'Dolore toracico', 'Arresto cardiaco', 'STEMI', 'Trauma maggiore', 'Trauma minore',
    'Difficolta respiratoria', 'Alterazione stato di coscienza', 'Convulsioni', 'Ictus',
    'Emorragia', 'Ipoglicemia', 'Intossicazione', 'Parto', 'Altro',
];

// Trattamenti (codice + descrizione). Elenco ricostruito dagli screenshot: puo' essere incompleto.
const TRATTAMENTI = [
    'EM04.81 Iniezione di anestetico in nervo periferico per analgesia',
    'EM21.01 Tamponamento anteriore per epistassi',
    'EM31.1 Cricotiroidotomia',
    'EM34.04 Drenaggio toracico',
    'EM34.09 Decompressione pneumotorace (pnx)',
    'EM37.0 Pericardiocentesi',
    'EM38.93 Accesso venoso centrale',
    'EM38.98 Prelievo di sangue arterioso',
    'EM38.99 Prelievo di sangue venoso',
    'EM39.98 Controllo emorragia esterna',
    'EM57.94 Cateterismo vescicale',
    'EM73.99 Clampaggio e taglio cordone ombelicale dopo il parto',
    'EM79.70 Riduzione chiusa di lussazione',
    'EM83.14 Fasciotomia',
    'EM86.59 Sutura di ferita',
    'EM89.37.1 Spirometria',
    'EM89.52 Elettrocardiogramma',
    'EM89.61 Monitoraggio della pressione arteriosa sistemica',
    'EM89.65.5 Controllo saturazione O2',
    'EM89.66 Prelievo sangue venoso per determinazione CO',
    'EM89.7 Visita generale',
    "EM90.18.3 Ricerca sostanze d'abuso (droghe) su liquidi biologici",
    'EM93.57.1 Medicazione di ustioni',
    'EM93.59 Controllo pneumotorace (pnx) aperto',
    'EM93.59.1 Immobilizzazione colonna con estricatore',
    'EM93.59.2 Immobilizzazione colonna con materassino a depressione',
    'EM93.59.3 Immobilizzazione colonna con tavola spinale',
    'EM93.93 Ventilazione con pallone auto espansibile',
    'EM93.94 Somministrazione farmaci con aerosol',
    'EM93.96 Somministrazione ossigeno',
    'EM96.01 Inserzione tubo naso tracheale o nasofaringeo',
    'EM96.01.1 Aspirazione secreti',
    'EM96.02 Inserzione tubo orotracheale',
    'EM96.02.1 Inserzione tubo o maschera laringea (o altro presidio sovraglottico)',
    'EM96.04 Inserzione di tubo endotracheale',
    'EM96.70 Ventilazione meccanica',
    'EM98.01 Disostruzione vie aeree',
    'EM98.14 Rimozione corpo estraneo laringe',
    'EM98.15 Rimozione corpo estraneo trachea',
    'EM99.18 Accesso venoso periferico',
    'EM99.19 Somministrazione Anticoagulanti',
    'EM99.2 Iniezione o infusione di farmaci e liquidi',
    'EM99.22 Rimozione corpo estraneo occhio',
    'EM99.29.0 Infusione intraossea per liquidi e farmaci',
    'EM99.29.1 Accesso Infraosseo',
    'EM99.60 Rianimazione cardiopolmonare di base',
    'EM99.62 Terapie cardiache elettriche (cardioversione, pacing, defibrillazione)',
];

// Equipaggio: campo => etichetta
const EQUIPAGGIO = [
    'soccorritore1' => 'A. Soccorritore', 'soccorritore2' => 'Soccorritore 2', 'soccorritore3' => 'Soccorritore 3',
    'infermiere1'   => 'Infermiere',      'infermiere2'   => 'Infermiere 2',   'infermiere3'   => 'Infermiere 3',
    'medico1'       => 'Medico',          'medico2'       => 'Medico 2',       'medico3'       => 'Medico 3',
];

// ---------- Centrale Operativa ----------
// Gradi (titolo mostrato nel profilo, assegnato dagli amministrativi). Elenco suggerito: si puo' scrivere anche altro.
const GRADI = [
    'Dirigente ARES 118', 'Vice Dirigente ARES 118',
    'Ispettore Operativo', 'Capo Turno',
    'Referente Medici', 'Referente Infermieri', 'Referente Operatori', 'Referente Emergenze', 'Referente Formazione', 'Referente Materiali',
    'Direttore Generale USAR', 'Direttore Sanitario', 'Direttore U.O.C. Grandi eventi', 'Coordinatore Infermieristico',
    'Medico Emergenza Urgenza 118', 'Infermiere Emergenza Urgenza 118',
    'Soccorritore Avanzato', 'Soccorritore Esperto / Operatore', 'Soccorritore 118', 'Soccorritore in Prova', 'Allievo Soccorritore',
    'Operatore USAR Expert', 'Operatore USAR Effective', 'Operatore in Affiancamento', 'Discente', 'Candidato',
    'Operatore della Settimana',
];

const TIPI_MEZZO = ['MSB', 'MSA1', 'MSA2', 'AI', 'AM', 'USAR'];
const GRUPPI_MEZZO = ['MSB', 'MSA1', 'MSA2', 'USAR'];

// Mezzi iniziali (nome, tipo, gruppo): vengono creati la prima volta; poi si gestiscono dalla pagina "Gestione mezzi".
const MEZZI_INIT = [
    ['Bravo 01', 'MSB', 'MSB'], ['Bravo 02', 'MSB', 'MSB'], ['Bravo 03', 'MSB', 'MSB'],
    ['India 01', 'MSA1', 'MSA1'], ['India 02', 'MSA1', 'MSA1'], ['Sierra 01', 'AI', 'MSA1'],
    ['Mike 01', 'MSA2', 'MSA2'], ['Mike 02', 'MSA2', 'MSA2'], ['Alfa 01', 'AM', 'MSA2'],
    ['Victor 01', 'USAR', 'USAR'], ['Victor 02', 'USAR', 'USAR'],
];

// Ruoli a bordo e posti nella scheda di soccorso (sezione 11). Il numero di posti e' il limite per mezzo.
const RUOLI_EQUIPAGGIO = [
    'Autista'      => ['soccorritore1'],
    'Capo Equipaggio' => ['soccorritore2'],
    'Soccorritore' => ['soccorritore3'],
    'Infermiere'   => ['infermiere1', 'infermiere2', 'infermiere3'],
    'Medico'       => ['medico1', 'medico2', 'medico3'],
];

// Tipo di mezzo -> casella "Mezzi attivati" della scheda (da verificare: MSA1 = infermieristico, MSA2 = avanzato)
const TIPO_MEZZO_SCHEDA = ['MSB' => 'msb', 'MSA1' => 'msi', 'MSA2' => 'msa', 'AM' => 'am', 'AI' => 'ai'];

const STATI_MEZZO = [
    'libero' => 'Libero', 'occupato' => 'Occupato', 'in_pausa' => 'In pausa',
    'fuori_servizio' => 'Fuori servizio', 'disattivato' => 'Disattivato',
];
const BADGE_MEZZO = ['libero' => 'on', 'occupato' => 'busy', 'in_pausa' => 'man', 'fuori_servizio' => 'idle', 'disattivato' => 'off'];

const STATI_ASSEGNAZIONE = [
    'assegnata' => 'Assegnata', 'partita' => 'In viaggio', 'sul_posto' => 'Sul posto',
    'in_trasporto' => 'In trasporto', 'arrivata_ps' => 'Arrivata in PS', 'libera' => 'Conclusa', 'annullata' => 'Annullata',
];
// Da quale stato si puo' passare a quale
const TRANSIZIONI = [
    'assegnata'    => ['partita'],
    'partita'      => ['sul_posto'],
    'sul_posto'    => ['in_trasporto', 'libera'],
    'in_trasporto' => ['arrivata_ps'],
    'arrivata_ps'  => ['libera'],
];
const AZIONE_LABEL = [
    'partita' => 'Partiti per la chiamata', 'sul_posto' => 'Arrivati sul posto',
    'in_trasporto' => 'Partiti verso il PS', 'arrivata_ps' => 'Arrivati in PS',
    'libera' => 'Mezzo libero',
];
const STATI_CHIAMATA = ['in_attesa' => 'In attesa', 'in_corso' => 'In corso', 'chiusa' => 'Chiusa', 'annullata' => 'Annullata'];
