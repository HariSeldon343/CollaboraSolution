<?php
/**
 * Italian Provinces Data
 * List of all 110 Italian provinces with their codes
 * Used for company registration forms
 */

function getItalianProvinces() {
    return [
        'AG' => 'Agrigento',
        'AL' => 'Alessandria',
        'AN' => 'Ancona',
        'AO' => 'Aosta',
        'AR' => 'Arezzo',
        'AP' => 'Ascoli Piceno',
        'AT' => 'Asti',
        'AV' => 'Avellino',
        'BA' => 'Bari',
        'BT' => 'Barletta-Andria-Trani',
        'BL' => 'Belluno',
        'BN' => 'Benevento',
        'BG' => 'Bergamo',
        'BI' => 'Biella',
        'BO' => 'Bologna',
        'BZ' => 'Bolzano',
        'BS' => 'Brescia',
        'BR' => 'Brindisi',
        'CA' => 'Cagliari',
        'CL' => 'Caltanissetta',
        'CB' => 'Campobasso',
        'CE' => 'Caserta',
        'CT' => 'Catania',
        'CZ' => 'Catanzaro',
        'CH' => 'Chieti',
        'CO' => 'Como',
        'CS' => 'Cosenza',
        'CR' => 'Cremona',
        'KR' => 'Crotone',
        'CN' => 'Cuneo',
        'EN' => 'Enna',
        'FM' => 'Fermo',
        'FE' => 'Ferrara',
        'FI' => 'Firenze',
        'FG' => 'Foggia',
        'FC' => 'Forlì-Cesena',
        'FR' => 'Frosinone',
        'GE' => 'Genova',
        'GO' => 'Gorizia',
        'GR' => 'Grosseto',
        'IM' => 'Imperia',
        'IS' => 'Isernia',
        'AQ' => "L'Aquila",
        'SP' => 'La Spezia',
        'LT' => 'Latina',
        'LE' => 'Lecce',
        'LC' => 'Lecco',
        'LI' => 'Livorno',
        'LO' => 'Lodi',
        'LU' => 'Lucca',
        'MC' => 'Macerata',
        'MN' => 'Mantova',
        'MS' => 'Massa-Carrara',
        'MT' => 'Matera',
        'ME' => 'Messina',
        'MI' => 'Milano',
        'MO' => 'Modena',
        'MB' => 'Monza e Brianza',
        'NA' => 'Napoli',
        'NO' => 'Novara',
        'NU' => 'Nuoro',
        'OR' => 'Oristano',
        'PD' => 'Padova',
        'PA' => 'Palermo',
        'PR' => 'Parma',
        'PV' => 'Pavia',
        'PG' => 'Perugia',
        'PU' => 'Pesaro e Urbino',
        'PE' => 'Pescara',
        'PC' => 'Piacenza',
        'PI' => 'Pisa',
        'PT' => 'Pistoia',
        'PN' => 'Pordenone',
        'PZ' => 'Potenza',
        'PO' => 'Prato',
        'RG' => 'Ragusa',
        'RA' => 'Ravenna',
        'RC' => 'Reggio Calabria',
        'RE' => 'Reggio Emilia',
        'RI' => 'Rieti',
        'RN' => 'Rimini',
        'RM' => 'Roma',
        'RO' => 'Rovigo',
        'SA' => 'Salerno',
        'SS' => 'Sassari',
        'SV' => 'Savona',
        'SI' => 'Siena',
        'SR' => 'Siracusa',
        'SO' => 'Sondrio',
        'SU' => 'Sud Sardegna',
        'TA' => 'Taranto',
        'TE' => 'Teramo',
        'TR' => 'Terni',
        'TO' => 'Torino',
        'TP' => 'Trapani',
        'TN' => 'Trento',
        'TV' => 'Treviso',
        'TS' => 'Trieste',
        'UD' => 'Udine',
        'VA' => 'Varese',
        'VE' => 'Venezia',
        'VB' => 'Verbano-Cusio-Ossola',
        'VC' => 'Vercelli',
        'VR' => 'Verona',
        'VV' => 'Vibo Valentia',
        'VI' => 'Vicenza',
        'VT' => 'Viterbo'
    ];
}

/**
 * Generate HTML options for province select dropdown
 */
function getProvinceOptions($selected = '') {
    $provinces = getItalianProvinces();
    $html = '<option value="">Seleziona provincia</option>';

    foreach ($provinces as $code => $name) {
        $isSelected = ($code === $selected) ? 'selected' : '';
        $html .= sprintf('<option value="%s" %s>%s (%s)</option>',
            htmlspecialchars($code),
            $isSelected,
            htmlspecialchars($name),
            htmlspecialchars($code)
        );
    }

    return $html;
}

/**
 * Generate JavaScript array for provinces
 */
function getProvincesJS() {
    $provinces = getItalianProvinces();
    return json_encode($provinces);
}