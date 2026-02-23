<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS, POST');
header('Access-Control-Allow-Headers: *');
header('Content-Type: application/json');

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// CONFIG
$apiKey = '5381aa36f1834e57acc7e066ae1c51f0';
$apiUrl = 'https://api.fish.audio/v1/tts';

include 'conn.php'; // conexão $conn

if (!$conn) {
    http_response_code(500);
    echo json_encode(["status" => "erro", "mensagem" => "Erro de conexão DB: " . mysqli_connect_error()]);
    exit;
}

// Verifica ID
if (!isset($_REQUEST['id'])) {
    echo json_encode(["status" => "erro", "mensagem" => "Parâmetro 'id' não enviado"]);
    exit;
}

$id = intval($_REQUEST['id']);
if ($id <= 0) {
    echo json_encode(["status" => "erro", "mensagem" => "ID inválido"]);
    exit;
}

// Busca o texto e o áudio antigo no DB
$sql = "SELECT texto, audio, idioma FROM mensagens WHERE id = $id LIMIT 1";
$res = mysqli_query($conn, $sql);

if (!$res || mysqli_num_rows($res) === 0) {
    echo json_encode(["status" => "erro", "mensagem" => "Registro não encontrado"]);
    exit;
}

$row = mysqli_fetch_assoc($res);
$texto = $row['texto'];
$audioAntigo = $row['audio'];
$idioma = $row['idioma'];
////////////////////////////////////////////////////////////////////////////////
////////////////////////////////////////////////////////////////////////////////
////////////////////////////////////////////////////////////////////////////////
////////////////////////////////////////////////////////////////////////////////
////////////////////////////////////////////////////////////////////////////////
////////////////////////////////////////////////////////////////////////////////
// 🔤 CORREÇÃO DE NOMES ANTES DE GERAR O ÁUDIO
//if ($idioma !== 'pt') {
$substituicoes = [

    /* =========================
       SIGLAS – ORDEM IMPORTA
       (sempre da maior para a menor)
       ========================= */
    '<br><br>' => ' ',
    '<br>' => ' ',
    '\n\n' => ' ',
    '\n' => ' ',
    '→' => ':',
    '.)' => ')',
    'ID' => 'identificador',
    
    'Ex.:' => 'exemplo:',
    'EX.:' => 'exemplo:',
    'ex.:' => 'exemplo:',
    'Ex.'  => 'exemplo',
    'EX.'  => 'exemplo',
    'ex.'  => 'exemplo',
    'ex:'  => 'exemplo:',
    
    'ARO'   => 'Antecipação de Receita Orçamentária',

    'CCJC' => 'Cecejótacê',
    'CCJ'  => 'Cecejóta',

    'Regimento Interno da Câmara dos Deputados (RICD)' => 'Regimento Interno da Câmara dos Deputados',
    'RICD' => 'Regimento Interno da Câmara dos Deputados',
    'Regimento Comum do Congrésso Nacional (RCCN)' => 'Regimento Comum do Congrésso Nacional',
    'RCCN' => 'Regimento Comum do Congrésso Nacional',
    'Constituição Federal de 1988 (CF/88)'=> 'Constituição Federal de mil novecentos e oitenta e oito',
    'Constituição Federal (CF/88)'=> 'Constituição Federal de oitenta e oito',
    'CF/88'=> 'Constituição de oitenta e oito',
    'CF'   => 'Constituição Federal',
    

    'CPIs' => 'cepeís',
    'CPI'  => 'cepê-í',

    'PECs' => 'pékis',
    'PEC'  => 'péki',

    'MPs'  => 'êmypês',
    'MP'   => 'êmypê',

    'CMO'  => 'ceêmeó',
    'PPA'  => 'pepe-á',
    'LDO'  => 'élidê-ó',
    'LOA'  => 'lôua',
    
    'STF'  => 'éssitê-éfi',
    'PGR'  => 'pêgê-érry',
    'PR'  => 'presidente',
    'TCU'  => 'tecê-ú',

    'DF'   => 'Distrito Federal',
    'EC'   => 'Emenda Constitucional',
    'LC'   => 'Lei Complementar',
    'LO'   => 'Lei Ordinária',
    'AL'   => 'Assembléia Legislativa',
    'CM'   => 'Câmara Municipal',

    /* =========================
       EXPRESSÕES LATINAS
       ========================= */

    'ad hoc' => 'ad róc',

    /* =========================
       FRAÇÕES (mais específicas primeiro)
       ========================= */

    '3/5' => 'três quintos',
    '2/3' => 'dois terços',
    '1/2' => 'um meio',
    '1/3' => 'um terço',
    '1/4' => 'um quarto',
    '1/5' => 'um quinto',
    '1/6' => 'um sexto',
    '1/8' => 'um oitavo',
    '1/10'=> 'um décimo',

    /* =========================
       ARTIGOS, PARÁGRAFOS
       ========================= */
    'Arts.' => 'artigos',
    'arts.' => 'artigos',
    'Art.' => 'artigo',
    'art.' => 'artigo',
    '§'    => 'parágrafo ',
    
    'etc.' => 'eticétera',
    'séc.' => 'século',

    /* =========================
       ANOS E LEIS (mais longos primeiro)
       ========================= */
    '12.813/2013' => 'dôze mil oitucêntos e trêze de dois mil e trêze',
    
    '4289'  => 'quatro mil duzentos e oitênta e nóvi',
    '4.320/64' => 'quatro mil trezentos e vinte de sessenta e quatro',
    '4.320' => 'quatro mil trezentos e vinte',
    '4320'  => 'quatro mil trezentos e vinte',
    
    '2025' => 'dois mil e vinte e cinco',
    
    '1500'    => 'mil e quinhentos',
    '1.500'    => 'mil e quinhentos',
    '1530'    => 'mil e quinhentos e trinta',
    '1.530'    => 'mil e quinhentos e trinta',
    '1.534'    => 'mil e quinhentos e trinta e quatro',
    '1534'    => 'mil e quinhentos e trinta e quatro',

    '1.988'    => 'mil novecentos e oitenta e oito',
    '1988' => 'mil novecentos e oitenta e oito',
    '1.964'    => 'mil novecentos e sessenta e quatro',
    '1964' => 'mil novecentos e cecenta e quatro',
    
    '1945' => 'mil novecentos e quarenta e cinco',
    '1888' => 'mil oitocentos e oitenta e oito',
    '1972' => 'mil novecentos e setenta e dois',
    '1965' => 'mil novecentos e sessenta e cinco',

    /* =========================
       ARTIGOS CONSTITUCIONAIS
       ========================= */
    '250' => 'duzentos e cinquênta',
    '249' => 'duzentos e quarênta e nóvi',
    '248' => 'duzentos e quarênta e oito',
    '247' => 'duzentos e quarênta e séte',
    '246' => 'duzentos e quarênta e seis',
    '245' => 'duzentos e quarênta e cinco',
    '244' => 'duzentos e quarênta e quatro',
    '243' => 'duzentos e quarênta e três',
    '242' => 'duzentos e quarênta e dois',
    '241' => 'duzentos e quarênta e um',
    '240' => 'duzentos e quarênta',
    '239' => 'duzentos e trinta e nóvi',
    '238' => 'duzentos e trinta e oito',
    '237' => 'duzentos e trinta e séte',
    '236' => 'duzentos e trinta e seis',
    '235' => 'duzentos e trinta e cinco',
    '234' => 'duzentos e trinta e quatro',
    '233' => 'duzentos e trinta e três',
    '232' => 'duzentos e trinta e dois',
    '231' => 'duzentos e trinta e um',
    '230' => 'duzentos e trinta',
    '229' => 'duzentos e vinte e nóvi',
    '228' => 'duzentos e vinte e oito',
    '227' => 'duzentos e vinte e séte',
    '226' => 'duzentos e vinte e seis',
    '225' => 'duzentos e vinte e cinco',
    '224' => 'duzentos e vinte e quatro',
    '223' => 'duzentos e vinte e três',
    '222' => 'duzentos e vinte e dois',
    '221' => 'duzentos e vinte e um',
    '220' => 'duzentos e vinte',
    '219' => 'duzentos e dezenóvi',
    '218' => 'duzentos e dizôito',
    '217' => 'duzentos e dezesséte',
    '216' => 'duzentos e dezesseis',
    '215' => 'duzentos e quinze',
    '214' => 'duzentos e catorze',
    '213' => 'duzentos e trêze',
    '212' => 'duzentos e dôzi',
    '211' => 'duzentos e ônzi',
    '210' => 'duzentos e déz',
    '209' => 'duzentos e nóvi',
    '208' => 'duzentos e oito',
    '207' => 'duzentos e séte',
    '206' => 'duzentos e seis',
    '204' => 'duzentos e quatro',
    '205' => 'duzentos e cinco',
    '203' => 'duzentos e três',
    '202' => 'duzentos e dois',
    '201' => 'duzentos e um',
    '200' => 'duzentos',
    '199' => 'cento e novênta e nóvi',
    '198' => 'cento e novênta e oito',
    '197' => 'cento e novênta e séte',
    '196' => 'cento e novênta e seis',
    '195' => 'cento e novênta e cinco',
    '194' => 'cento e novênta e quatro',
    '193' => 'cento e novênta e três',
    '192' => 'cento e novênta e dois',
    '191' => 'cento e novênta e um',
    '190' => 'cento e novênta',
    '189' => 'cento e oitênta e nóvi',
    '188' => 'cento e oitênta e oito',
    '187' => 'cento e oitênta e séte',
    '186' => 'cento e oitênta e seis',
    '185' => 'cento e oitênta e cinco',
    '184' => 'cento e oitênta e quatro',
    '183' => 'cento e oitênta e três',
    '182' => 'cento e oitênta e dois',
    '181' => 'cento e oitênta e um',
    '180' => 'cento e oitênta',
    '179' => 'cento e setênta e nóvi',
    '170' => 'cento e setênta',
    '169' => 'cento e sessenta e nove',
    '168' => 'cento e sessenta e oito',
    '167' => 'cento e sessenta e sete',
    '166' => 'cento e sessenta e seis',
    '165' => 'cento e sessenta e cinco',
    '164' => 'cento e sessenta e quatro',
    '163' => 'cento e sessenta e três',
    '162' => 'cento e sessenta e dois',
    '161' => 'cento e sessenta e um',
    '160' => 'cento e sessenta',
    '159' => 'cento e cinquênta e nóvi',
    '158' => 'cento e cinquênta e ôito',
    '157' => 'cento e cinquênta e séte',
    '156' => 'cento e cinquênta e seis',
    '155' => 'cento e cinquênta e cinco',
    '154' => 'cento e cinquênta e quatro',
    '153' => 'cento e cinquênta e três',
    '152' => 'cento e cinquênta e dois',
    '151' => 'cento e cinquênta e um',
    '150' => 'cento e cinquênta',
    '149' => 'cento e quarênta e nóvi',
    '148' => 'cento e quarênta e oito',
    '147' => 'cento e quarênta e séte',
    '146' => 'cento e quarênta e seis',
    '145' => 'cento e quarênta e cinco',
    '144' => 'cento e quarênta e quatro',
    '143' => 'cento e quarênta e três',
    '142' => 'cento e quarênta e dois',
    '141' => 'cento e quarênta e um',
    '140' => 'cento e quarênta',
    '139' => 'cento e trinta e nóvi',
    '138' => 'cento e trinta e oito',
    '137' => 'cento e trinta e séte',
    '136' => 'cento e trinta e seis',
    '135' => 'cento e trinta e cinco',
    '134' => 'cento e trinta e quatro',
    '133' => 'cento e trinta e três',
    '132' => 'cento e trinta e dois',
    '131' => 'cento e trinta e um',
    '130' => 'cento e trinta',
    '129' => 'cento e vinte e nóvi',
    '128' => 'cento e vinte e oito',
    '127' => 'cento e vinte e séte',
    '126' => 'cento e vinte e seis',
    '125' => 'cento e vinte e cinco',
    '124' => 'cento e vinte e quatro',
    '123' => 'cento e vinte e três',
    '122' => 'cento e vinte e dois',
    '121' => 'cento e vinte e um',
    '120' => 'cento e vinte',
    '119' => 'cento e dezenóvi',
    '118' => 'cento e dizôito',
    '117' => 'cento e dezesséte',
    '116' => 'cento e dezesseis',
    '115' => 'cento e quinze',
    '114' => 'cento e catorze',
    '113' => 'cento e trêze',
    '112' => 'cento e dôzi',
    '111' => 'cento e ônzi',
    '110' => 'cento e déz',
    '109' => 'cento e nóvi',
    '108' => 'cento e oito',
    '107' => 'cento e séte',
    '106' => 'cento e seis',
    '104' => 'cento e quatro',
    '105' => 'cento e cinco',
    '103' => 'cento e três',
    '102' => 'cento e dois',
    '101' => 'cento e um',
    '100' => 'seim',

    /* =========================
       NÚMEROS CARDINAIS COMUNS
       ========================= */
    '93' => 'novênta e três',
    '80' => 'oitênta',
    '84' => 'oitênta e quatro',
    '69' => 'sessênta e nove',
    '68' => 'sessênta e oito',
    '67' => 'sessênta e céti',
    '66' => 'sessênta e seis',
    '65' => 'sessênta e cinco',
    '64' => 'sessênta e quatro',
    '63' => 'sessênta e três',
    '62' => 'sessênta e dois',
    '61' => 'sessênta e um',
    '69' => 'sessênta',
    '51' => 'cinquênta e um',
    '59' => 'cinquênta e nove',
    '50' => 'cinquênta',
    '48h' => 'quarênta e oito horas',
    '48' => 'quarênta e oito',
    '31/12' => 'trinta e um de dezembro',
    '31/08' => 'trinta e um de agosto',
    '30' => 'trinta',
    '22/12' => 'vinte e dois de dezembro',
    '20' => 'vinte',
    '19' => 'dezenove',
    '18' => 'dezoito',
    '17/07' => 'dezesséte de julho',
    '17' => 'dezesséte',
    '16' => 'dezesseis',
    '15/04' => 'quinze de abril',
    '15' => 'quinze',
    '14' => 'quatorze',
    '13' => 'treze',
    '12' => 'doze',
    '11' => 'onze',
    '10' => 'dez',
    '3º' => 'terceiro',
    '2º' => 'segundo',
    '1º' => 'primeiro',

    /* =========================
       NÚMEROS ROMANOS
       (sempre do maior para o menor)
       ========================= */

    'LXXX' => 'oitenta',
    'LXXIX'=> 'setenta e nove',
    'LXXVIII'=> 'setenta e oito',
    'LXXVII'=> 'setenta e sete',
    'LXXVI'=> 'setenta e seis',
    'LXXV' => 'setenta e cinco',
    'LXXIV'=> 'setenta e quatro',
    'LXXIII'=> 'setenta e três',
    'LXXII'=> 'setenta e dois',
    'LXXI' => 'setenta e um',
    'LXX'  => 'setenta',

    'LXIX' => 'sessenta e nove',
    'LXVIII'=> 'sessenta e oito',
    'LXVII'=> 'sessenta e sete',
    'LXVI' => 'sessenta e seis',
    'LXV'  => 'sessenta e cinco',
    'LXIV' => 'sessenta e quatro',
    'LXIII'=> 'sessenta e três',
    'LXII' => 'sessenta e dois',
    'LXI'  => 'sessenta e um',
    'LX'   => 'sessenta',

    'LIX'  => 'cinquenta e nove',
    'LVIII'=> 'cinquenta e oito',
    'LVII' => 'cinquenta e sete',
    'LVI'  => 'cinquenta e seis',
    'LV'   => 'cinquenta e cinco',
    'LIV'  => 'cinquenta e quatro',
    'LIII' => 'cinquenta e três',
    'LII'  => 'cinquenta e dois',
    'LI'   => 'cinquenta e um',
    'L'    => 'cinquenta',

    'XLIX' => 'quarenta e nove',
    'XLVIII'=> 'quarenta e oito',
    'XLVII'=> 'quarenta e sete',
    'XLVI' => 'quarenta e seis',
    'XLV'  => 'quarenta e cinco',
    'XLIV' => 'quarenta e quatro',
    'XLIII'=> 'quarenta e três',
    'XLII' => 'quarenta e dois',
    'XLI'  => 'quarenta e um',
    'XL'   => 'quarenta',

    'XXXIX'=> 'trinta e nove',
    'XXXVIII'=> 'trinta e oito',
    'XXXVII'=> 'trinta e sete',
    'XXXVI'=> 'trinta e seis',
    'XXXV' => 'trinta e cinco',
    'XXXIV'=> 'trinta e quatro',
    'XXXIII'=> 'trinta e três',
    'XXXII'=> 'trinta e dois',
    'XXXI' => 'trinta e um',
    'XXX'  => 'trinta',

    'XXIX' => 'vinte e nove',
    'XXVIII'=> 'vinte e oito',
    'XXVII'=> 'vinte e sete',
    'XXVI' => 'vinte e seis',
    'XXV'  => 'vinte e cinco',
    'XXIV' => 'vinte e quatro',
    'XXIII'=> 'vinte e três',
    'XXII' => 'vinte e dois',
    'XXI'  => 'vinte e um',
    'XX'   => 'vinte',

    'XIX'  => 'dezenove',
    'XVIII'=> 'dezoito',
    'XVII' => 'dezessete',
    'XVI'  => 'dezesseis',
    'XV'   => 'quinze',
    'XIV'  => 'quatorze',
    'XIII' => 'treze',
    'XII'  => 'doze',
    'XI'   => 'onze',
    'X'    => 'dez',
    'IX'   => 'nove',
    'VIII' => 'oito',
    'VII'  => 'sete',
    'VI'   => 'seis',
    'V'    => 'cinco',
    'IV'   => 'quatro',
    'III'  => 'três',
    'II'   => 'dois',
    'I'    => 'um',

    /* =========================
       AJUSTES DE PRONÚNCIA
       ========================= */

    'superavit' => 'superávit',
    'sede'      => 'cédi',
    'relatoria'  => 'relatoría',
    'sobrestam'  => 'sobréstam',
    'teologia'  => 'têologia',
    'Maquiavel'  => 'Maquiavél',
    'behavioralismo'  => 'birreivioralismo',
    'behaviorismo'  => 'birreiviorismo',
    'accountability'  => 'acauntability',

    /* =========================
       CARACTERES
       ========================= */

    '/' => ' ',
    ':' => '.',
];

foreach ($substituicoes as $buscar => $substituir) {
    $texto = preg_replace(
        '/(?<!\p{L})' . preg_quote($buscar, '/') . '(?!\p{L})/u',
        $substituir,
        $texto
    );
}
//}

// ➕ GARANTE PONTUAÇÃO FINAL
// remove espaços no final
$texto = rtrim($texto);

//Remove os <br><br>
$texto = preg_replace('/\.(?:<br>)+\s*([A-Z])/u', '. $1', $texto);

// remove aspas simples e duplas
$texto = str_replace(['"', "'"], '', $texto);

$texto = str_replace(['*', "*"], '', $texto);

// substitui parênteses por vírgulas
$texto = str_replace(['(', ')'], '-', $texto);

// remove "- " apenas no início do parágrafo / linha
$texto = preg_replace('/^[\h]*-\h+(?=\p{L})/mu', '', $texto);

// garante ponto final se não houver pontuação
if (!preg_match('/[.!?…]$/u', $texto)) {
    $texto .= '.';
}

// 1️⃣ Substituições semânticas
foreach ($substituicoes as $buscar => $substituir) {
    if ($buscar === '/') continue;

    $texto = preg_replace(
        '/(?<!\p{L})' . preg_quote($buscar, '/') . '(?!\p{L})/u',
        $substituir,
        $texto
    );
}

// 2️⃣ Normalizações estruturais
$texto = str_replace('/', ' ', $texto);
////////////////////////////////////////////////////////////////////////////////
////////////////////////////////////////////////////////////////////////////////
////////////////////////////////////////////////////////////////////////////////
////////////////////////////////////////////////////////////////////////////////
////////////////////////////////////////////////////////////////////////////////
// Se já existir áudio diferente de 'no', apaga o antigo
if (!empty($audioAntigo) && strtolower(trim($audioAntigo)) !== 'no') {
    //echo "Entrou no bloco de remoção do antigo<br>";
    
    // Remove espaços extras
    $audioAntigo = trim($audioAntigo);

    // Caminho absoluto correto
    $arquivoAntigoPath = "audios/" . $audioAntigo;

    // Debug: verifica se o arquivo existe
    if (file_exists($arquivoAntigoPath)) {
        //echo "Arquivo encontrado: $arquivoAntigoPath<br>";
        if (is_writable($arquivoAntigoPath)) {
            unlink($arquivoAntigoPath);
            //echo "Arquivo apagado com sucesso<br>";
        } else {
            //echo "Arquivo existe mas não é gravável: $arquivoAntigoPath<br>";
        }
    } else {
        //echo "Arquivo antigo não encontrado: $arquivoAntigoPath<br>";
    }
}

// Voz locutor da globo 0cd49ff56c1a42c1bca05bb1fe6c1dee
// Voz grave robotica b2f48ebaf7b644539abc7dea4cf7d28c

// Define voz por idioma
if ($idioma === 'en') {
    // Inglês – voz de exemplo
    $reference_id = '414ff9ed9a80438ea98e21fbf6719dbe'; 
} else {
    // Português – voz da Globo
     $reference_id = '67814b0453c741f1beb01bbbc01c17e3'; //*fininho o linguado (terceiro)
     //$reference_id = '1c73d82495d64120ac7f6da0de00698e'; // globo narrado (terceiro)
     //$reference_id = 'b977fd39709c4f9081961f85cf152b72'; //Power Rangers (copia clone)
     //$reference_id = '0931435e95d5432e9384a4975e4b382e'; //bonner
     //$reference_id = 'cfd8b26a1be648b89c65fe060d85fda7'; //capitão madagascar
     //$reference_id = 'ebe6b824b285454ab1d7ffed5251acf8'; //kowalski- MADAGASCAR
     //$reference_id = '11fe6934a4f14a83a632d618df993dc9'; //pai do bob esponja
     //$reference_id = '7d142b9ea76c45e386631268eb3a2747'; //*pai do chris
     //$reference_id = 'a0b61f5e12664cfb977b795683d48b58'; //locutor normal
     //$reference_id = 'f10700a1a6fb400880df70b9d176ccb2'; //locutor normal
     //$reference_id = '9ef3c5a356a04f6fbc9d379090151b85'; //locutor normal
}

// Requisição API
$body = [
    'text' => $texto,
    'reference_id' => $reference_id,
    'chunk_length' => 200,
    'normalize' => true,
    'format' => 'mp3',
    'mp3_bitrate' => 128,
    'latency' => 'normal',
    'model' => 's1'
];

$payload = json_encode($body);

$ch = curl_init($apiUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $apiKey,
    'Content-Type: application/json'
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    echo json_encode([
        "status" => "erro",
        "mensagem" => "Erro ao gerar áudio (HTTP $httpCode)",
        "resposta_api" => $response
    ]);
    exit;
}

// Pasta audios
$target_dir = "audios/";
if (!file_exists($target_dir)) {
    mkdir($target_dir, 0777, true);
}

$filename = "audio_" . time() . ".mp3";
$fullPath = $target_dir . $filename;

// Salva arquivo
if (file_put_contents($fullPath, $response) === false) {
    echo json_encode(["status" => "erro", "mensagem" => "Erro ao salvar arquivo"]);
    exit;
}

// Atualiza DB
$pathDB = "audios/" . $filename;
$update = "UPDATE mensagens SET audio = '" . mysqli_real_escape_string($conn, $pathDB) . "' WHERE id = $id";
if (!mysqli_query($conn, $update)) {
    echo json_encode([
        "status" => "erro",
        "mensagem" => "Erro ao atualizar DB: " . mysqli_error($conn)
    ]);
    exit;
}

// ✅ Sucesso
echo json_encode([
    "status" => "ok",
    "mensagem" => "Áudio gerado e salvo",
    "arquivo" => $pathDB
]);
exit;
