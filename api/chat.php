<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

function reply_json(bool $ok, string $message = '', array $extra = [], int $status = 200): void {
    http_response_code($status);
    echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    reply_json(false, 'POST only', ['endpoint' => 'api/chat.php'], 405);
}

$raw = file_get_contents('php://input');
$input = json_decode($raw ?: '', true);
if (!is_array($input)) {
    $input = $_POST;
}

$conversation = $input['conversation'] ?? [];
if (is_string($conversation)) {
    $conversation = [['role' => 'user', 'content' => $conversation]];
}
if (!is_array($conversation) || count($conversation) === 0) {
    reply_json(false, 'Hakuna ujumbe wa mazungumzo uliotumwa.', ['error_code' => 'EMPTY_CONVERSATION'], 200);
}

$key = getenv('OPENAI_API_KEY');
if (!$key && is_file(__DIR__ . '/openai-config.php')) {
    require __DIR__ . '/openai-config.php';
    $key = $OPENAI_API_KEY ?? '';
}
$key = trim((string)$key);
if ($key === '') {
    reply_json(false, 'OpenAI API key haijawekwa kwenye server.', ['error_code' => 'MISSING_API_KEY'], 200);
}

$system = <<<'PROMPT'
You are Ramani Majengo, a serious conversational architectural space-planning assistant. You are not a form validator and you must not behave like a childish scripted chatbot.

Talk naturally with the user in the language they use (Swahili when they use Swahili, English when they use English). Read the ENTIRE conversation before replying. Maintain the facts already supplied. Never ask again for information that the user has already clearly supplied.

Your job in this stage is REQUIREMENTS DISCOVERY. Do not generate the floor plan yet. Instead:
1. Understand the user's architectural intention.
2. Extract plot dimensions when provided.
3. Distinguish TOTAL PRINCIPAL SPACES from BEDROOM COUNT. If the user says something ambiguous such as "rooms 6" while also saying "five bedrooms around a central living room", interpret the context carefully. In the known example, "6" means six principal spaces: five bedrooms plus one central living room; kitchen, dining, pantry, toilets, veranda and parking are supporting spaces unless the user explicitly says otherwise.
4. Understand named spaces: living room, kitchen, pantry, dining, toilets/bathrooms, veranda, courtyard, parking, laundry, store, office, etc.
5. Understand spatial relationships such as "living room in the centre surrounded by five bedrooms".
6. Understand style and special architectural requirements.
7. Ask for missing critical information progressively. Do not dump a long questionnaire. One or two focused questions at a time.
8. Once the brief is sufficiently complete, clearly say that the requirements are complete and tell the user to press Generate. Do NOT generate the floor plan yet.

Important: A number by itself can be an answer to the previous question, but use the previous conversation context to determine whether it means bedrooms, total principal spaces, plot dimensions, or something else. Never repeat the same question merely because the latest answer is numeric.

Return a JSON object with exactly these keys:
message (string), ready (boolean), plot_width (number|null), plot_depth (number|null), bedrooms (number|null), total_spaces (number|null), courtyard (boolean), style (string), requirements (array of concise strings), missing (array of concise strings).
PROMPT;

$messages = [['role' => 'system', 'content' => $system]];
foreach ($conversation as $m) {
    if (!is_array($m)) continue;
    $role = (($m['role'] ?? '') === 'assistant') ? 'assistant' : 'user';
    $content = trim((string)($m['content'] ?? ''));
    if ($content !== '') {
        $messages[] = ['role' => $role, 'content' => $content];
    }
}

if (count($messages) < 2) {
    reply_json(false, 'Ujumbe wa mtumiaji haukupokelewa vizuri.', ['error_code' => 'INVALID_CONVERSATION'], 200);
}

$body = json_encode([
    'model' => 'gpt-5.6-luna',
    'input' => $messages,
    'text' => [
        'format' => ['type' => 'json_object']
    ],
    'max_output_tokens' => 1800
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

if ($body === false) {
    reply_json(false, 'Server imeshindwa kuandaa ombi la AI.', ['error_code' => 'REQUEST_ENCODING'], 200);
}

function openai_request(string $url, string $body, string $key): array {
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'http' => 0, 'body' => '', 'error' => 'PHP cURL extension haipatikani.'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $key
        ],
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ]);

    $res = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    return [
        'ok' => ($res !== false && $http >= 200 && $http < 300),
        'http' => $http,
        'body' => ($res === false ? '' : (string)$res),
        'error' => $err
    ];
}

$result = openai_request('https://api.openai.com/v1/responses', $body, $key);

if (!$result['ok']) {
    $provider = json_decode($result['body'], true);
    $providerMessage = $provider['error']['message'] ?? '';
    $providerCode = $provider['error']['code'] ?? '';
    $http = (int)$result['http'];
    $safeMessage = $providerMessage ?: ($result['error'] ?: ('OpenAI request failed with HTTP ' . ($http ?: '0')));

    // Keep the HTTP response 200 so the frontend can display the real diagnostic
    // instead of replacing it with a generic network error.
    reply_json(false, 'AI service error: ' . $safeMessage, [
        'error_code' => $providerCode ?: 'OPENAI_REQUEST_FAILED',
        'http_status' => $http
    ], 200);
}

$j = json_decode($result['body'], true);
if (!is_array($j)) {
    reply_json(false, 'OpenAI ilirudisha majibu yasiyoeleweka.', [
        'error_code' => 'INVALID_PROVIDER_RESPONSE',
        'http_status' => (int)$result['http']
    ], 200);
}

$text = $j['output_text'] ?? '';
if (!$text && isset($j['output']) && is_array($j['output'])) {
    foreach ($j['output'] as $item) {
        foreach (($item['content'] ?? []) as $c) {
            if (isset($c['text'])) {
                $text .= $c['text'];
            }
        }
    }
}

$text = trim((string)$text);
$text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text);
$out = json_decode(trim($text), true);

if (!is_array($out)) {
    reply_json(false, 'AI ilirudisha JSON isiyoweza kusomwa.', [
        'error_code' => 'INVALID_AI_JSON',
        'http_status' => (int)$result['http'],
        'raw_preview' => mb_substr($text, 0, 300)
    ], 200);
}

$out['message'] = trim((string)($out['message'] ?? 'Nimepokea mahitaji yako.'));
$out['ready'] = (bool)($out['ready'] ?? false);
$out['plot_width'] = isset($out['plot_width']) && is_numeric($out['plot_width']) ? (float)$out['plot_width'] : null;
$out['plot_depth'] = isset($out['plot_depth']) && is_numeric($out['plot_depth']) ? (float)$out['plot_depth'] : null;
$out['bedrooms'] = isset($out['bedrooms']) && is_numeric($out['bedrooms']) ? (int)$out['bedrooms'] : null;
$out['total_spaces'] = isset($out['total_spaces']) && is_numeric($out['total_spaces']) ? (int)$out['total_spaces'] : null;
$out['courtyard'] = (bool)($out['courtyard'] ?? false);
out['style'] = (string)($out['style'] ?? '');
out['requirements'] = is_array($out['requirements'] ?? null) ? array_values($out['requirements']) : [];
out['missing'] = is_array($out['missing'] ?? null) ? array_values($out['missing']) : [];

reply_json(true, $out['message'], ['reply' => $out], 200);
