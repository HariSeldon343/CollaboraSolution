<?php
/**
 * Italian Municipalities Data
 *
 * Organized by province code with most common municipalities.
 * This is a lightweight subset for validation purposes.
 *
 * For production use, consider:
 * - Full ISTAT database integration
 * - Database table with all 7,904 Italian municipalities
 * - Regular updates via ISTAT API
 *
 * Current data: Major cities and municipalities (sample subset)
 *
 * @source ISTAT - Istituto Nazionale di Statistica
 * @version 1.0.0
 */

function getItalianMunicipalities(): array {
    return [
        'AG' => ['Agrigento', 'Canicattì', 'Licata', 'Sciacca', 'Favara'],
        'AL' => ['Alessandria', 'Casale Monferrato', 'Novi Ligure', 'Tortona', 'Acqui Terme'],
        'AN' => ['Ancona', 'Jesi', 'Senigallia', 'Fabriano', 'Osimo'],
        'AO' => ['Aosta'],
        'AR' => ['Arezzo', 'Cortona', 'San Giovanni Valdarno', 'Montevarchi', 'Bibbiena'],
        'AP' => ['Ascoli Piceno', 'San Benedetto del Tronto', 'Grottammare', 'Montegranaro'],
        'AT' => ['Asti', 'Canelli', 'Nizza Monferrato', 'Villanova d\'Asti'],
        'AV' => ['Avellino', 'Ariano Irpino', 'Montoro', 'Mercogliano', 'Atripalda'],
        'BA' => ['Bari', 'Altamura', 'Molfetta', 'Bitonto', 'Monopoli', 'Modugno', 'Gravina in Puglia'],
        'BT' => ['Barletta', 'Andria', 'Trani', 'Bisceglie', 'Canosa di Puglia'],
        'BL' => ['Belluno', 'Feltre', 'Cortina d\'Ampezzo', 'Agordo'],
        'BN' => ['Benevento', 'San Giorgio del Sannio', 'Montesarchio', 'Telese Terme'],
        'BG' => ['Bergamo', 'Treviglio', 'Seriate', 'Dalmine', 'Romano di Lombardia', 'Caravaggio'],
        'BI' => ['Biella', 'Cossato', 'Candelo', 'Valdilana'],
        'BO' => ['Bologna', 'Imola', 'Casalecchio di Reno', 'San Lazzaro di Savena', 'Castel Maggiore'],
        'BZ' => ['Bolzano', 'Merano', 'Bressanone', 'Brunico', 'Laives'],
        'BS' => ['Brescia', 'Desenzano del Garda', 'Montichiari', 'Lumezzane', 'Gardone Val Trompia'],
        'BR' => ['Brindisi', 'Fasano', 'Ostuni', 'Francavilla Fontana', 'Mesagne'],
        'CA' => ['Cagliari', 'Quartu Sant\'Elena', 'Assemini', 'Selargius', 'Capoterra'],
        'CL' => ['Caltanissetta', 'Gela', 'Niscemi', 'Mussomeli'],
        'CB' => ['Campobasso', 'Termoli', 'Isernia', 'Larino'],
        'CE' => ['Caserta', 'Aversa', 'Marcianise', 'Maddaloni', 'Santa Maria Capua Vetere'],
        'CT' => ['Catania', 'Acireale', 'Misterbianco', 'Paternò', 'Adrano', 'Gravina di Catania'],
        'CZ' => ['Catanzaro', 'Lamezia Terme', 'Soverato', 'Chiaravalle Centrale'],
        'CH' => ['Chieti', 'Lanciano', 'Vasto', 'Francavilla al Mare', 'Ortona'],
        'CO' => ['Como', 'Cantù', 'Erba', 'Mariano Comense', 'Lomazzo'],
        'CS' => ['Cosenza', 'Rossano', 'Corigliano Calabro', 'Castrovillari', 'Rende'],
        'CR' => ['Cremona', 'Crema', 'Casalmaggiore', 'Castelleone'],
        'KR' => ['Crotone', 'Cirò Marina', 'Cutro', 'Isola di Capo Rizzuto'],
        'CN' => ['Cuneo', 'Alba', 'Bra', 'Fossano', 'Mondovì', 'Savigliano'],
        'EN' => ['Enna', 'Piazza Armerina', 'Nicosia', 'Leonforte'],
        'FM' => ['Fermo', 'Porto Sant\'Elpidio', 'Porto San Giorgio', 'Sant\'Elpidio a Mare'],
        'FE' => ['Ferrara', 'Cento', 'Comacchio', 'Argenta', 'Bondeno'],
        'FI' => ['Firenze', 'Scandicci', 'Sesto Fiorentino', 'Campi Bisenzio', 'Empoli', 'Prato'],
        'FG' => ['Foggia', 'Cerignola', 'Manfredonia', 'San Severo', 'Lucera'],
        'FC' => ['Forlì', 'Cesena', 'Cesenatico', 'Forlimpopoli', 'Savignano sul Rubicone'],
        'FR' => ['Frosinone', 'Cassino', 'Alatri', 'Sora', 'Ferentino', 'Ceccano'],
        'GE' => ['Genova', 'Rapallo', 'Chiavari', 'Sestri Levante', 'Lavagna'],
        'GO' => ['Gorizia', 'Monfalcone', 'Gradisca d\'Isonzo', 'Grado'],
        'GR' => ['Grosseto', 'Follonica', 'Orbetello', 'Monte Argentario'],
        'IM' => ['Imperia', 'Sanremo', 'Ventimiglia', 'Bordighera', 'Diano Marina'],
        'IS' => ['Isernia', 'Venafro', 'Agnone'],
        'AQ' => ['L\'Aquila', 'Avezzano', 'Sulmona', 'Celano'],
        'SP' => ['La Spezia', 'Sarzana', 'Lerici', 'Levanto'],
        'LT' => ['Latina', 'Aprilia', 'Terracina', 'Formia', 'Cisterna di Latina', 'Fondi'],
        'LE' => ['Lecce', 'Nardò', 'Copertino', 'Galatina', 'Maglie'],
        'LC' => ['Lecco', 'Merate', 'Calolziocorte', 'Bellano'],
        'LI' => ['Livorno', 'Piombino', 'Cecina', 'Rosignano Marittimo'],
        'LO' => ['Lodi', 'Codogno', 'Sant\'Angelo Lodigiano', 'Casalpusterlengo'],
        'LU' => ['Lucca', 'Viareggio', 'Forte dei Marmi', 'Pietrasanta', 'Capannori'],
        'MC' => ['Macerata', 'Civitanova Marche', 'Corridonia', 'Recanati', 'Tolentino'],
        'MN' => ['Mantova', 'Castiglione delle Stiviere', 'Suzzara', 'Viadana'],
        'MS' => ['Massa', 'Carrara', 'Montignoso', 'Aulla'],
        'MT' => ['Matera', 'Pisticci', 'Policoro', 'Montalbano Jonico'],
        'ME' => ['Messina', 'Barcellona Pozzo di Gotto', 'Milazzo', 'Taormina'],
        'MI' => ['Milano', 'Sesto San Giovanni', 'Cinisello Balsamo', 'Rho', 'Monza', 'Legnano'],
        'MO' => ['Modena', 'Carpi', 'Sassuolo', 'Formigine', 'Vignola'],
        'MB' => ['Monza', 'Desio', 'Seregno', 'Lissone', 'Limbiate'],
        'NA' => ['Napoli', 'Giugliano in Campania', 'Torre del Greco', 'Pozzuoli', 'Casoria', 'Afragola'],
        'NO' => ['Novara', 'Borgomanero', 'Arona', 'Trecate', 'Galliate'],
        'NU' => ['Nuoro', 'Siniscola', 'Macomer', 'Dorgali'],
        'OR' => ['Oristano', 'Terralba', 'Cabras', 'Ghilarza'],
        'PD' => ['Padova', 'Cittadella', 'Abano Terme', 'Selvazzano Dentro', 'Monselice'],
        'PA' => ['Palermo', 'Bagheria', 'Carini', 'Partinico', 'Termini Imerese', 'Cefalù'],
        'PR' => ['Parma', 'Fidenza', 'Salsomaggiore Terme', 'Colorno'],
        'PV' => ['Pavia', 'Vigevano', 'Voghera', 'Stradella'],
        'PG' => ['Perugia', 'Terni', 'Foligno', 'Città di Castello', 'Spoleto', 'Assisi'],
        'PU' => ['Pesaro', 'Urbino', 'Fano', 'Fossombrone'],
        'PE' => ['Pescara', 'Montesilvano', 'Spoltore', 'Città Sant\'Angelo'],
        'PC' => ['Piacenza', 'Castel San Giovanni', 'Fiorenzuola d\'Arda', 'Rottofreno'],
        'PI' => ['Pisa', 'Pontedera', 'Volterra', 'San Miniato', 'Cascina'],
        'PT' => ['Pistoia', 'Montecatini Terme', 'Quarrata', 'Agliana'],
        'PN' => ['Pordenone', 'Sacile', 'Azzano Decimo', 'Maniago'],
        'PZ' => ['Potenza', 'Melfi', 'Lavello', 'Rionero in Vulture'],
        'PO' => ['Prato', 'Montemurlo', 'Carmignano', 'Poggio a Caiano'],
        'RG' => ['Ragusa', 'Vittoria', 'Modica', 'Comiso'],
        'RA' => ['Ravenna', 'Faenza', 'Lugo', 'Cervia', 'Russi'],
        'RC' => ['Reggio Calabria', 'Gioia Tauro', 'Palmi', 'Villa San Giovanni'],
        'RE' => ['Reggio Emilia', 'Correggio', 'Scandiano', 'Guastalla'],
        'RI' => ['Rieti', 'Cittaducale', 'Fara in Sabina', 'Poggio Mirteto'],
        'RN' => ['Rimini', 'Riccione', 'Cattolica', 'Santarcangelo di Romagna'],
        'RM' => ['Roma', 'Fiumicino', 'Guidonia Montecelio', 'Anzio', 'Tivoli', 'Pomezia', 'Velletri'],
        'RO' => ['Rovigo', 'Adria', 'Porto Viro', 'Badia Polesine'],
        'SA' => ['Salerno', 'Battipaglia', 'Cava de\' Tirreni', 'Nocera Inferiore', 'Eboli'],
        'SS' => ['Sassari', 'Alghero', 'Olbia', 'Porto Torres'],
        'SV' => ['Savona', 'Albenga', 'Varazze', 'Cairo Montenotte'],
        'SI' => ['Siena', 'Poggibonsi', 'Colle di Val d\'Elsa', 'Montepulciano'],
        'SR' => ['Siracusa', 'Augusta', 'Avola', 'Noto'],
        'SO' => ['Sondrio', 'Tirano', 'Morbegno', 'Livigno'],
        'SU' => ['Carbonia', 'Iglesias', 'Portoscuso', 'Sant\'Antioco'],
        'TA' => ['Taranto', 'Martina Franca', 'Grottaglie', 'Manduria', 'Massafra'],
        'TE' => ['Teramo', 'Giulianova', 'Roseto degli Abruzzi', 'Pineto'],
        'TR' => ['Terni', 'Orvieto', 'Narni', 'Amelia'],
        'TO' => ['Torino', 'Moncalieri', 'Rivoli', 'Collegno', 'Settimo Torinese', 'Nichelino'],
        'TP' => ['Trapani', 'Marsala', 'Mazara del Vallo', 'Alcamo', 'Castelvetrano'],
        'TN' => ['Trento', 'Rovereto', 'Pergine Valsugana', 'Arco', 'Riva del Garda'],
        'TV' => ['Treviso', 'Vittorio Veneto', 'Conegliano', 'Castelfranco Veneto', 'Montebelluna'],
        'TS' => ['Trieste', 'Muggia', 'San Dorligo della Valle', 'Duino-Aurisina'],
        'UD' => ['Udine', 'Cividale del Friuli', 'Codroipo', 'Gemona del Friuli'],
        'VA' => ['Varese', 'Busto Arsizio', 'Gallarate', 'Saronno', 'Tradate'],
        'VE' => ['Venezia', 'Mestre', 'Chioggia', 'Mira', 'San Donà di Piave'],
        'VB' => ['Verbania', 'Domodossola', 'Omegna', 'Stresa'],
        'VC' => ['Vercelli', 'Borgosesia', 'Santhià', 'Gattinara'],
        'VR' => ['Verona', 'San Bonifacio', 'Legnago', 'Villafranca di Verona', 'Bussolengo'],
        'VV' => ['Vibo Valentia', 'Tropea', 'Mileto', 'Serra San Bruno'],
        'VI' => ['Vicenza', 'Bassano del Grappa', 'Schio', 'Arzignano', 'Thiene'],
        'VT' => ['Viterbo', 'Civita Castellana', 'Tarquinia', 'Montefiascone']
    ];
}

/**
 * Get all municipalities for a specific province
 *
 * @param string $provinceCode Province code (e.g., 'RM', 'MI')
 * @return array Array of municipality names
 */
function getMunicipalitiesByProvince(string $provinceCode): array {
    $municipalities = getItalianMunicipalities();
    return $municipalities[$provinceCode] ?? [];
}

/**
 * Search municipalities across all provinces
 *
 * @param string $query Search query (partial match)
 * @return array Array of municipalities with province info
 */
function searchMunicipalities(string $query): array {
    $query = strtolower(trim($query));
    $results = [];
    $municipalities = getItalianMunicipalities();

    foreach ($municipalities as $provinceCode => $muniList) {
        foreach ($muniList as $municipality) {
            if (stripos($municipality, $query) !== false) {
                $results[] = [
                    'name' => $municipality,
                    'province' => $provinceCode
                ];
            }
        }
    }

    return $results;
}
