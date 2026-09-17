<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out($ok, $message = '', $extra = []) {
    http_response_code($ok ? 200 : 400);
    echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(false, 'POST only');
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) $data = $_POST;
if (!is_array($data)) out(false, 'Invalid request.');

$plotW = (float)($data['plot_width'] ?? 0);
$plotD = (float)($data['plot_depth'] ?? 0);
$brief = trim((string)($data['brief'] ?? $data['notes'] ?? ''));
$totalSpaces = (int)($data['total_spaces'] ?? 0);
$hardBedrooms = (int)($data['bedrooms'] ?? 0);

if ($plotW <= 0 || $plotD <= 0) out(false, 'Weka upana na urefu wa kiwanja kwa mita, mfano 20 × 30.');
if ($brief === '') out(false, 'Andika mahitaji ya nyumba kwanza.');

$key = getenv('OPENAI_API_KEY');
if (!$key && is_file(__DIR__ . '/openai-config.php')) {
    require __DIR__ . '/openai-config.php';
    $key = $OPENAI_API_KEY ?? '';
}
if (!$key) out(false, 'AI service haijaunganishwa kwa sasa.');

// The client sends the interpreted conversation state. The server also checks the brief
// so that a browser-side mistake cannot silently change a hard room requirement.
if ($hardBedrooms <= 0) {
    $patterns = [
        '/\b(\d{1,2})\s*(?:bedrooms?|bed\s*rooms?)\b/iu',
        '/\b(?:bedrooms?|bed\s*rooms?|vyumba(?:\s+vya\s+kulala)?|vyumba)\s*(?:=|:|ni|ya)?\s*(\d{1,2})\b/iu'
    ];
    foreach ($patterns as $p) {
        if (preg_match($p, $brief, $m)) { $hardBedrooms = (int)$m[1]; break; }
    }
    $words = ['one'=>1,'two'=>2,'three'=>3,'four'=>4,'five'=>5,'six'=>6,'seven'=>7,'eight'=>8,'nine'=>9,'ten'=>10];
    if ($hardBedrooms <= 0 && preg_match('/\b(one|two|three|four|five|six|seven|eight|nine|ten)\s+bedrooms?\b/iu', $brief, $m)) {
        $hardBedrooms = $words[strtolower($m[1])];
    }
}

$courtyardRequired = (bool)preg_match('/courtyard|inner\s*courtyard|central\s*(?:yard|courtyard|open\s*space)|uwanja\s+(?:wa\s+)?(?:ndani|katikati)|uwanja\s+katikati/iu', $brief);
$constraints = [];
if ($hardBedrooms > 0) $constraints[] = 'EXACTLY ' . $hardBedrooms . ' BEDROOMS. Never add or remove a bedroom.';
if ($totalSpaces > 0) $constraints[] = 'The client requested ' . $totalSpaces . ' principal spaces. Treat this as a hard planning target, but supporting sanitary/circulation spaces may be represented separately when required for realism.';
if ($courtyardRequired) $constraints[] = 'MANDATORY CENTRAL COURTYARD/OPEN YARD. It is outdoor space, not a bedroom.';
$constraintText = $constraints ? implode(' ', $constraints) : 'Respect every explicit requirement in the conversation.';

$system = 'You are Ramani Majengo, a serious conversational architectural space-planning engine. The user is the designer/client and expects realistic architecture, not a toy response. Read the whole conversation brief as requirements. Ask only for genuinely critical missing information; never repeat a question that has already been answered. If the brief contains an exact bedroom count, output exactly that many objects with type bedroom. If it says there are five bedrooms around a central living room, preserve that arrangement conceptually. If a courtyard is requested, include it as outdoor space. Use metric dimensions, realistic circulation, sensible adjacency, and a footprint that fits the plot. Do not invent a second set of rooms just to make the answer longer. Supporting bathroom/toilet, kitchen, pantry, dining, circulation, veranda and parking spaces may be included when explicitly requested or necessary to make the concept realistic. Return ONLY valid JSON with keys: needs_clarification, questions, title, summary, house_width, house_depth, measurements, room_experience, outside_preview, rooms. Each room object must contain name,width,depth,type. Allowed types: bedroom,bathroom,living,kitchen,dining,circulation,courtyard,utility,parking,veranda,other.';

$user = 'PLOT: ' . $plotW . 'm × ' . $plotD . "m\n" .
        'REQUESTED PRINCIPAL SPACES: ' . ($totalSpaces > 0 ? $totalSpaces : 'not explicitly stated') . "\n" .
        'REQUESTED BEDROOMS: ' . ($hardBedrooms > 0 ? $hardBedrooms : 'not explicitly stated') . "\n" .
        'STYLE: ' . trim((string)($data['style'] ?? '')) . "\n" .
        'CLIENT CONVERSATION / BRIEF:\n' . $brief . "\n\nHARD CONSTRAINTS: " . $constraintText;

function ask_ai($key, $system, $user, $repair = '') {
    $input = [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => $user]
    ];
    if ($repair !== '') $input[] = ['role' => 'user', 'content' => $repair];
    $body = json_encode(['model' => 'gpt-5.6-luna', 'input' => $input, 'max_output_tokens' => 5000], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ch = curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key], CURLOPT_POSTFIELDS => $body]);
    $res = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false || $http < 200 || $http >= 300) return [null, 'AI request failed: ' . ($err ?: 'HTTP ' . $http)];
    $j = json_decode($res, true);
    $text = $j['output_text'] ?? '';
    if (!$text && isset($j['output'])) foreach ($j['output'] as $item) foreach (($item['content'] ?? []) as $c) if (isset($c['text'])) $text .= $c['text'];
    $text = trim(preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text));
    $plan = json_decode($text, true);
    return is_array($plan) ? [$plan, ''] : [null, 'AI returned invalid JSON.'];
}

function normalize_rooms($rooms) {
    $out = [];
    foreach ((array)$rooms as $r) {
        $name = trim((string)($r['name'] ?? ''));
        $w = (float)($r['width'] ?? 0);
        $d = (float)($r['depth'] ?? 0);
        $type = strtolower(trim((string)($r['type'] ?? 'other')));
        if ($name === '' || $w <= 0 || $d <= 0) continue;
        $out[] = ['name' => $name, 'width' => round($w, 2), 'depth' => round($d, 2), 'type' => $type];
    }
    return $out;
}

$last = '';
$plan = null;
for ($attempt = 1; $attempt <= 3; $attempt++) {
    [$plan, $err] = ask_ai($key, $system, $user, $attempt > 1 ? 'REPAIR: The previous result failed a hard constraint. Regenerate the COMPLETE JSON. ' . $constraintText . ' Do not repeat the failed constraint. Previous result: ' . json_encode($plan, JSON_UNESCAPED_UNICODE) : '');
    if (!$plan) { $last = $err; continue; }
    if (!empty($plan['needs_clarification'])) out(true, 'AI needs clarification.', ['plan' => $plan, 'ai' => true]);

    $rooms = normalize_rooms($plan['rooms'] ?? []);
    $beds = 0; $courtyard = 0;
    foreach ($rooms as $r) { if ($r['type'] === 'bedroom') $beds++; if ($r['type'] === 'courtyard') $courtyard++; }
    $houseW = (float)($plan['house_width'] ?? 0);
    $houseD = (float)($plan['house_depth'] ?? 0);
    if (!$rooms) { $last = 'No valid rooms returned'; continue; }
    if ($hardBedrooms > 0 && $beds !== $hardBedrooms) { $last = 'Requested ' . $hardBedrooms . ' bedrooms, received ' . $beds; continue; }
    if ($courtyardRequired && $courtyard < 1) { $last = 'Courtyard requested but missing'; continue; }
    if ($houseW <= 0 || $houseD <= 0 || $houseW > $plotW || $houseD > $plotD) { $last = 'House footprint exceeds plot'; continue; }

    $plan['rooms'] = $rooms;
    $plan['house_width'] = round($houseW, 2);
    $plan['house_depth'] = round($houseD, 2);
    $plan['validation'] = [
        'valid' => true,
        'requested_bedrooms' => $hardBedrooms,
        'generated_bedrooms' => $beds,
        'requested_principal_spaces' => $totalSpaces,
        'courtyard_required' => $courtyardRequired,
        'courtyard_present' => $courtyard > 0,
        'plot_width' => $plotW,
        'plot_depth' => $plotD
    ];
    out(true, 'AI concept generated and validated.', ['plan' => $plan, 'ai' => true]);
}

out(false, 'AI haikuweza kutengeneza plan inayofuata masharti yote. ' . $last);
