<?php
/**
 * Regression suite for the Telegram loan bot.
 *
 * Runs the real bot behind a real HTTP server (PHP's built-in server), with
 * the Telegram API replaced by a local stub. The bot is driven over HTTP so
 * that php://input, which only carries a body under a real request, is
 * exercised the same way production does it.
 *
 * Usage: php tests/bot_test.php [path/to/index.php]
 * Exit code 0 = all checks passed.
 */

$botPath = $argv[1] ?? dirname(__DIR__) . '/index.php';

$work = sys_get_temp_dir() . '/loanbot_tests';
if (!is_dir($work)) mkdir($work, 0777, true);
foreach (glob("$work/*") as $f) if (is_file($f)) unlink($f);

$stateFile = "$work/users.json";
$outLog = "$work/sent.log";
$stubPort = 8971;
$appPort = 8972;
file_put_contents($stateFile, '{}');

// The stub records every outbound Telegram call as one JSON line.
// The bot sends its calls as GET with a query string, so read QUERY_STRING
// and fall back to the request body for anything posted.
// The built-in server does not pass extra argv through, so the log path is
// baked into the script when it is written.
$stub = <<<'PHP'
<?php
$log = __DIR__ . '/sent.log';
$post = [];
$raw = file_get_contents("php://input");
if ($raw !== false && $raw !== '') {
    parse_str($raw, $post);
} else {
    parse_str($_SERVER['QUERY_STRING'] ?? '', $post);
}
file_put_contents($log, json_encode($post) . "\n", FILE_APPEND);
header("Content-Type: application/json");
echo '{"ok":true}';
PHP;
file_put_contents("$work/stub.php", $stub);

// The stub must answer on any path, since the bot calls /bot<token>/sendMessage.
$stubRouter = <<<'PHP'
<?php
require __DIR__ . '/stub.php';
PHP;
file_put_contents("$work/stub_router.php", $stubRouter);

// Route every request to the bot, mirroring the .htaccess rewrite.
$router = <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && is_file(__DIR__ . $path)) return false;
require __DIR__ . '/index.php';
PHP;
file_put_contents("$work/router.php", $router);

// Build the bot copy: point Telegram at the stub, and the state file at tmp.
$code = file_get_contents($botPath);
$code = str_replace(
    '"https://api.telegram.org/bot$botToken"',
    '"http://127.0.0.1:' . $stubPort . '/bot$botToken"',
    $code
);
$code = preg_replace(
    '/getenv\([\'"]STATE_FILE[\'"]\)\s*\?:\s*\(__DIR__\s*\.\s*[\'"]\/users\.json[\'"]\)/',
    var_export($stateFile, true),
    $code
);
file_put_contents("$work/index.php", $code);

$stubProc = proc_open(
    ['php', '-S', "127.0.0.1:$stubPort", '-t', $work, "$work/stub_router.php"],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $stubPipes
);
$appProc = proc_open(
    ['php', '-S', "127.0.0.1:$appPort", '-t', $work, "$work/router.php"],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $appPipes
);

/** POST a Telegram update to the bot and return the replies it sent. */
function send(int $appPort, string $outLog, array $update): array
{
    @unlink($outLog);
    $ch = curl_init("http://127.0.0.1:$appPort/");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($update),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $stderr = curl_error($ch);
    curl_close($ch);

    $replies = [];
    if (file_exists($outLog)) {
        foreach (array_filter(explode("\n", file_get_contents($outLog))) as $line) {
            $decoded = json_decode($line, true);
            if ($decoded) $replies[] = $decoded;
        }
    }
    return ['replies' => $replies, 'status' => $status, 'body' => (string)$body, 'stderr' => $stderr];
}

function msg(int $chatId, string $text): array
{
    return ['message' => ['chat' => ['id' => $chatId], 'text' => $text]];
}

// Wait for both servers to accept connections.
$ready = false;
for ($i = 0; $i < 60; $i++) {
    $c = @fsockopen("127.0.0.1", $appPort, $e, $s, 0.3);
    if ($c) { fclose($c); $ready = true; break; }
    usleep(150000);
}
if (!$ready) {
    fwrite(STDERR, "test server never came up\n");
    proc_terminate($stubProc); proc_terminate($appProc);
    exit(2);
}

$failures = [];
$passes = 0;
function check(bool $cond, string $label): void
{
    global $failures, $passes;
    if ($cond) { $passes++; echo "  PASS  $label\n"; }
    else { $failures[] = $label; echo "  FAIL  $label\n"; }
}

try {
    echo "== happy path ==\n";
    $r = send($appPort, $outLog, msg(1001, '/start'));
    check(!empty($r['replies']), '/start sends a reply');
    check(str_contains($r['replies'][0]['text'] ?? '', 'loan amount'), '/start asks for the loan amount');

    send($appPort, $outLog, msg(1001, '$500'));
    $r = send($appPort, $outLog, msg(1001, 'ETH'));
    check(str_contains($r['replies'][0]['text'] ?? '', 'wallet address'), 'after choosing ETH it asks for a wallet address');

    $r = send($appPort, $outLog, msg(1001, '0xabcdefabcdefabcdefabcdefabcdefabcdefab'));
    check(str_contains($r['replies'][0]['text'] ?? '', 'send a fee of $15'), 'it quotes the correct $15 fee for a $500 loan');
    check(str_contains($r['replies'][0]['text'] ?? '', '0xeaf5a6b7d91754534ec88a884297e5a2c05fcc11'), 'it sends the real ETH wallet address');

    $state = json_decode(file_get_contents($stateFile), true)['1001'] ?? [];
    check(($state['amount'] ?? null) === '$500', 'amount is persisted');
    check(($state['coin'] ?? null) === 'ETH', 'coin is persisted');
    check(($state['fee'] ?? null) === '$15', 'fee is persisted');
    check(($state['step'] ?? null) === 'await_payment', 'state advanced to await_payment');

    echo "\n== unknown cryptocurrency is rejected ==\n";
    send($appPort, $outLog, msg(1002, '/start'));
    send($appPort, $outLog, msg(1002, '$500'));
    $r = send($appPort, $outLog, msg(1002, 'DOGE'));
    $body = $r['replies'][0]['text'] ?? '';
    check(str_contains($body, 'Unsupported cryptocurrency'), 'it refuses an unsupported coin');
    check(!str_contains($body, '<code></code>'), 'it never sends an empty wallet address');
    $state2 = json_decode(file_get_contents($stateFile), true)['1002'] ?? [];
    check(($state2['coin'] ?? null) === null, 'the unsupported coin is not stored');

    echo "\n== unknown loan amount is rejected ==\n";
    send($appPort, $outLog, msg(1003, '/start'));
    $r = send($appPort, $outLog, msg(1003, 'free money'));
    check(str_contains($r['replies'][0]['text'] ?? '', 'valid loan amount'), 'it refuses an unknown amount');

    echo "\n== malformed wallet address is rejected ==\n";
    send($appPort, $outLog, msg(1004, '/start'));
    send($appPort, $outLog, msg(1004, '$100'));
    send($appPort, $outLog, msg(1004, 'BTC'));
    $r = send($appPort, $outLog, msg(1004, 'short'));
    check(str_contains($r['replies'][0]['text'] ?? '', 'valid wallet address'), 'it refuses a malformed wallet address');

    echo "\n== update without a message field ==\n";
    $r = send($appPort, $outLog, ['callback_query' => ['id' => 'x']]);
    check($r['status'] === 200, 'it answers 200 for a non-message update');
    check($r['replies'] === [], 'it does not reply to a non-message update');
    check(!preg_match('/Warning|Fatal/', $r['body']), 'it raises no PHP warnings');

    echo "\n== broadcast to other users ==\n";
    send($appPort, $outLog, msg(1001, 'TXID-12345'));
    $all = file_exists($outLog) ? array_filter(explode("\n", file_get_contents($outLog))) : [];
    $broadcasts = [];
    foreach ($all as $line) {
        $d = json_decode($line, true);
        if ($d && str_contains($d['text'], 'just paid')) $broadcasts[] = $d;
    }
    check(!empty($broadcasts), 'it broadcasts the payment to other users');
    $leak = [];
    foreach ($broadcasts as $b) {
        foreach (['0xabcdef', 'TXID'] as $secret) {
            if (stripos($b['text'], $secret) !== false) $leak[] = $secret;
        }
    }
    check($leak === [], 'the broadcast leaks no wallet address or TXID');

    echo "\n== state file integrity ==\n";
    check(json_decode(file_get_contents($stateFile), true) !== null, 'the state file is still valid JSON');
    check(!file_exists($stateFile . '.tmp'), 'no temp file is left behind');
} finally {
    proc_terminate($stubProc);
    proc_terminate($appProc);
}

echo "\n";
if ($failures) {
    echo "FAILED: " . count($failures) . " of " . ($passes + count($failures)) . " checks\n";
    foreach ($failures as $f) echo "  - $f\n";
    exit(1);
}
echo "PASSED: all $passes checks\n";
exit(0);
