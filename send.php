<?php
/**
 * Приём заявок с формы бронирования «Центр Поля».
 * Отправляет заявку на почту и (если настроено) сообщением от группы ВКонтакте.
 * Настройки — в config.php (в git не хранится, образец: config.example.php).
 * Отвечает JSON: {"ok":true} или {"ok":false}.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');

function respond(bool $ok, int $code = 200): void {
    http_response_code($code);
    echo json_encode(['ok' => $ok]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(false, 405);
}

$cfgFile = __DIR__ . '/config.php';
if (!is_file($cfgFile)) {
    error_log('centr-polya send.php: нет config.php');
    respond(false, 500);
}
$cfg = require $cfgFile;

/** Значение поля формы: без управляющих символов, обрезанное по длине. */
function field(string $key, int $max = 300): string {
    $v = trim((string)($_POST[$key] ?? ''));
    $v = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v);
    return mb_substr($v, 0, $max);
}

// Ловушка для ботов: скрытое поле люди не заполняют. Боту отвечаем «ок», чтобы не подбирал.
if (field('website') !== '') {
    respond(true);
}

// Простое ограничение частоты: не больше 5 заявок за 10 минут с одного IP.
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rlFile = sys_get_temp_dir() . '/cp_rl_' . md5($ip);
$now = time();
$hits = array_filter(
    is_file($rlFile) ? (array)json_decode((string)file_get_contents($rlFile), true) : [],
    static fn($t) => is_int($t) && $t > $now - 600
);
if (count($hits) >= 5) {
    respond(false, 429);
}
$hits[] = $now;
@file_put_contents($rlFile, json_encode(array_values($hits)), LOCK_EX);

$name  = field('name', 100);
$phone = field('phone', 40);
if ($name === '' || strlen((string)preg_replace('/\D/', '', $phone)) < 10) {
    respond(false, 422);
}

$rows = [
    'Имя'             => $name,
    'Телефон'         => $phone,
    'Дата и время'    => field('datetime', 100),
    'Поле / формат'   => field('field', 100),
    'Размер ворот'    => field('goals', 50),
    'Аренда мяча'     => field('ball', 50),
    'Аренда манишек'  => field('bibs', 50),
    'Комментарий'     => field('comment', 2000),
];
$text = "Новая заявка с сайта centrpolya.ru\n\n";
foreach ($rows as $label => $value) {
    if ($value !== '') {
        $text .= $label . ': ' . $value . "\n";
    }
}
date_default_timezone_set('Europe/Moscow');
$text .= "\nОтправлено: " . date('d.m.Y H:i') . ' (МСК)';

$sent = false;

// --- Почта ---
if (!empty($cfg['mail_to'])) {
    $from    = (string)($cfg['mail_from'] ?? '');
    $subject = '=?UTF-8?B?' . base64_encode('Заявка с сайта: ' . $name . ', ' . $phone) . '?=';
    $headers = implode("\r\n", array_filter([
        $from !== '' ? 'From: =?UTF-8?B?' . base64_encode('Сайт «Центр Поля»') . '?= <' . $from . '>' : '',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ]));
    $params = $from !== '' ? '-f' . $from : '';
    foreach ((array)$cfg['mail_to'] as $to) {
        if (@mail((string)$to, $subject, $text, $headers, $params)) {
            $sent = true;
        } else {
            error_log('centr-polya send.php: mail() не отправил письмо на ' . $to);
        }
    }
}

// --- ВКонтакте: сообщение от имени группы ---
if (!empty($cfg['vk_token']) && !empty($cfg['vk_peer_ids'])) {
    foreach ((array)$cfg['vk_peer_ids'] as $peer) {
        $payload = http_build_query([
            'access_token' => $cfg['vk_token'],
            'v'            => '5.199',
            'peer_id'      => (int)$peer,
            'random_id'    => random_int(1, PHP_INT_MAX),
            'message'      => $text,
        ]);
        $ctx = stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $payload,
            'timeout' => 8,
        ]]);
        $res  = @file_get_contents('https://api.vk.com/method/messages.send', false, $ctx);
        $data = $res ? json_decode($res, true) : null;
        if (isset($data['response'])) {
            $sent = true;
        } else {
            $err = $data['error']['error_msg'] ?? 'нет ответа';
            error_log('centr-polya send.php: ВК не отправил сообщение ' . $peer . ': ' . $err);
        }
    }
}

respond($sent, $sent ? 200 : 502);
