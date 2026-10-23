<?php
// Finto server dell'API di Anthropic per i test (tests/run.sh lo avvia con php -S e ci punta ANTHROPIC_API_URL).
// Primo turno: chiede uno strumento (leggi_file, oppure proponi_modifica se il messaggio contiene "PROPOSE");
// secondo turno: risponde con il testo "RESULT:" + il risultato dello strumento, così il test vede cosa ha letto Claude.
$in = json_decode((string)file_get_contents('php://input'), true);
$msgs = $in['messages'] ?? [];
$last = end($msgs);
header('Content-Type: application/json');
if (is_array($last['content'] ?? null) && ($last['content'][0]['type'] ?? '') === 'tool_result') {
    echo json_encode(['model' => 'fake', 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        'content' => [['type' => 'text', 'text' => 'RESULT:' . $last['content'][0]['content']]]]);
    exit;
}
$q = is_string($last['content'] ?? null) ? $last['content'] : '';
// "GOLEMCTX": dice se il contesto contiene golem/GOLEM.md e il blueprint (root collegata a Golem Lite)
if (strpos($q, 'GOLEMCTX') !== false) {
    $sys = implode("\n", array_map(function ($b) { return (string)($b['text'] ?? ''); }, (array)($in['system'] ?? [])));
    $has = strpos($sys, 'golem/GOLEM.md') !== false && strpos($sys, 'Golem Lite — front-end guide') !== false && strpos($sys, '"type":"blueprint"') !== false;
    echo json_encode(['model' => 'fake', 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        'content' => [['type' => 'text', 'text' => 'CTX:' . ($has ? 'yes' : 'no')]]]);
    exit;
}
$tool = strpos($q, 'PROPOSE') !== false
    ? ['name' => 'proponi_modifica', 'input' => ['percorso' => 'inc/config.php', 'testo_da_sostituire' => '$b = 2;', 'nuovo_testo' => '$b = 3;']]
    : ['name' => 'leggi_file', 'input' => ['percorso' => 'inc/config.php']];
echo json_encode(['model' => 'fake', 'stop_reason' => 'tool_use', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
    'content' => [['type' => 'tool_use', 'id' => 'tu_1'] + $tool]]);
