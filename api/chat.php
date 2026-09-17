<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function reply_json(bool $ok, string $message = '', array $extra = []): void {
    http_response_code($ok ? 200 : 400);
    echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') reply_json(false, 'POST only');

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) $input = $_POST;

$conversation = $input['conversation'] ?? [];
if (is_string($conversation)) $conversation = [['role' => 'user', 'content' => $conversation]];
if (!is_array($conversation)) $conversation = [];

$key = getenv('OPENAI_API_KEY');
if (!$key && is_file(__DIR__ . '/openai-config.php')) {
    require __DIR__ . '/openai-config.php';
    $key = $OPENAI_API_KEY ?? '';
}
if (!$key) reply_json(false, 'AI service haijaunganishwa kwa sasa.');

$system = <<<'PROMPT'
You are Ramani Majengo, a serious conversational architectural space-planning assistant. You are not a form validator and you must not behave like a childish scripted chatbot.

Talk naturally with the user in the language they use (Swahili when they use Swahili, English when they use English). Read the ENTIRE conversation before replying. Maintain the facts already supplied. Never ask again for information that the user has already clearly supplied.

Your job in this stage is REQUIREMENTS DISCOVERY. Do not generate the floor plan yet. Instead:
1. Understand the user's architectural intention.
2. Extract plot dimensions when provided.
3. Distinguish TOTAL PRINCIPAL SPACES from BEDROOM COUNT. If the user says something ambiguous such as "rooms 6" while also saying "five bedrooms around a central living room", explain the interpretation briefly and ask only the one clarification that is genuinely needed.
4. Understand named spaces: living room, kitchen, pantry, dining, toilets/bathrooms, veranda, courtyard, parking, laundry, store, office, etc.
5. Understand spatial relationships such as "living room in the centre surrounded by five bedrooms".
6. Understand style and special architectural requirements.
7. Ask for missing critical information progressively. Do not dump a long questionnaire. One or two focused questions at a time.
8. Once the brief is sufficiently complete, clearly say that the requirements are complete and tell the user to press Generate. Do NOT generate JSON in this chat response.

Important: A number by itself can be an answer to the previous question. For example, if you asked how many bedrooms and the user replies "6", interpret that as six bedrooms unless the conversation context shows that the number referred to total spaces. Do not repeat the previous question.

Return ONLY valid JSON with these keys:
message (string), ready (boolean), plot_width (number|null), plot_depth (number|null), bedrooms (number|null), total_spaces (number|null), courtyard (boolean), style (string), requirements (array of concise strings), missing (array of concise strings).
PROMPT;

$messages = [['role' => 'system', 'content' => $system]];
foreach ($conversation as $m) {
    $role = (($m['role'] ?? '') === 'assistant') ? 'assistant' : 'user';
    $content = trim((string)($m['content'] ?? ''));
    if ($content !== '') $messages[] = ['role' => $role, 'content' => $content];
}

$body = json_encode([
    'model' => 'gpt-5.6-luna',
    'input' => $messages,
    'max_output_tokens' => 1800
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$ch = curl_init('https://api.openai.com/v1/responses');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 45,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
    CURLOPT_POSTFIELDS => $body
]);
$res = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if ($res === false || $http < 200 || $http >= 300) {
    reply_json(false, 'AI request failed: ' . ($err ?: 'HTTP ' . $http));
}

$j = json_decode($res, true);
$text = $j['output_text'] ?? '';
if (!$text && isset($j['output'])) {
    foreach ($j['output'] as $item) foreach (($item['content'] ?? []) as $c) if (isset($c['text'])) $text .= $c['text'];
}
$text = trim(preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text));
$out = json_decode($text, true);
if (!is_array($out)) reply_json(false, 'AI returned invalid conversation JSON.');

reply_json(true, $out['message'] ?? 'Nimepokea mahitaji yako.', ['reply' => $out]);
