<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();
$dotenv->required(['BASE_URL', 'SMTP_HOST', 'SMTP_USER', 'SMTP_PASS', 'SMTP_PORT', 'SMTP_FROM_EMAIL', 'SMTP_FROM_NAME']);

define('BASE_URL', $_ENV['BASE_URL']);
define('STORAGE_FILE', __DIR__ . '/games.json');
define('LIMIT_FILE', __DIR__ . '/limits.json');
define('SESSION_TIMEOUT', 900);

define('SMTP_HOST', $_ENV['SMTP_HOST']);
define('SMTP_USER', $_ENV['SMTP_USER']);
define('SMTP_PASS', $_ENV['SMTP_PASS']);
define('SMTP_PORT', (int)$_ENV['SMTP_PORT']);
define('SMTP_FROM_EMAIL', $_ENV['SMTP_FROM_EMAIL']);
define('SMTP_FROM_NAME', $_ENV['SMTP_FROM_NAME']);

function loadGames(): array {
    if (!file_exists(STORAGE_FILE)) {
        return [];
    }
    $fp = fopen(STORAGE_FILE, 'r');
    if (!$fp) {
        return [];
    }
    flock($fp, LOCK_SH);
    $content = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $data = json_decode($content ?: '[]', true);
    return is_array($data) ? $data : [];
}

function saveGames(array $games): void {
    $fp = fopen(STORAGE_FILE, 'c+');
    if (!$fp) {
        return;
    }
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($games, JSON_PRETTY_PRINT));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

function cleanExpiredGames(array &$games): void {
    $now = time();
    $changed = false;
    foreach ($games as $id => $game) {
        if (($now - $game['updated_at']) > SESSION_TIMEOUT) {
            unset($games[$id]);
            $changed = true;
        }
    }
    if ($changed) {
        saveGames($games);
    }
}

function checkRateLimit(string $ip): bool {
    $limits = [];
    if (file_exists(LIMIT_FILE)) {
        $content = file_get_contents(LIMIT_FILE);
        $limits = json_decode($content ?: '{}', true) ?: [];
    }
    $now = time();
    foreach ($limits as $storedIp => $timestamp) {
        if ($now - $timestamp > 60) {
            unset($limits[$storedIp]);
        }
    }
    if (isset($limits[$ip]) && ($now - $limits[$ip]) < 10) {
        return false;
    }
    $limits[$ip] = $now;
    file_put_contents(LIMIT_FILE, json_encode($limits));
    return true;
}

function checkWinner(array $board): ?string {
    $lines = [
        [0, 1, 2], [3, 4, 5], [6, 7, 8],
        [0, 3, 6], [1, 4, 7], [2, 5, 8],
        [0, 4, 8], [2, 4, 6]
    ];
    foreach ($lines as $line) {
        [$a, $b, $c] = $line;
        if ($board[$a] !== '' && $board[$a] === $board[$b] && $board[$a] === $board[$c]) {
            return $board[$a];
        }
    }
    if (!in_array('', $board, true)) {
        return 'DRAW';
    }
    return null;
}

function buildEmailHtml(array $game, string $recipientRole, ?int $selectedCell = null): string {
    $board = $game['board'];
    $isRecipientTurn = ($game['status'] === 'ACTIVE' && $game['turn'] === $recipientRole);
    $token = $game['tokens'][$recipientRole];
    $gameId = $game['id'];

    $statusText = '';
    $statusBg = '#e6f4f1';
    $statusColor = '#1d5c5a';
    $statusBorder = '#b5ddd6';

    if ($game['status'] === 'WON') {
        if ($game['winner'] === $recipientRole) {
            $statusText = 'You won the match!';
            $statusBg = '#dcfce7';
            $statusColor = '#166534';
            $statusBorder = '#86efac';
        } else {
            $statusText = 'You lost! (' . $game['winner'] . ' won)';
            $statusBg = '#fee2e2';
            $statusColor = '#991b1b';
            $statusBorder = '#fca5a5';
        }
    } elseif ($game['status'] === 'DRAW') {
        $statusText = "It's a draw!";
        $statusBg = '#fef3c7';
        $statusColor = '#92400e';
        $statusBorder = '#fde68a';
    } else {
        if ($isRecipientTurn) {
            $statusText = "It's your turn as " . $recipientRole . "! Click any empty square below to make your move.";
            $statusBg = '#e6f4f1';
            $statusColor = '#1d5c5a';
            $statusBorder = '#b5ddd6';
        } else {
            $statusText = "Move sent! Waiting for opponent (" . $game['turn'] . ") to play.";
            $statusBg = '#fef3c7';
            $statusColor = '#92400e';
            $statusBorder = '#fde68a';
        }
    }

    $opponentEmail = $recipientRole === 'X' ? $game['player_o'] : $game['player_x'];
    $recipientColor = ($recipientRole === 'X') ? '#d95d39' : '#2b7a78';

    $rowsHtml = '';
    for ($r = 0; $r < 3; $r++) {
        $rowsHtml .= '<tr>';
        for ($c = 0; $c < 3; $c++) {
            $idx = $r * 3 + $c;
            $val = $board[$idx];
            $cellNum = $idx + 1;

            if ($val === 'X') {
                $rowsHtml .= '
                <td align="center" valign="middle" style="width:76px;height:76px;background-color:#ffede8;border-radius:11px;border:2px solid #d95d39;padding:0;margin:0;vertical-align:middle;text-align:center;">
                    <table role="presentation" width="100%" height="76" cellspacing="0" cellpadding="0" border="0" style="width:76px;height:76px;border-collapse:collapse;">
                        <tr>
                            <td align="left" valign="top" style="padding:4px 0 0 6px;height:12px;font-family:\'Plus Jakarta Sans\',Arial,sans-serif;font-size:10px;font-weight:700;color:#d95d39;line-height:1;">
                                ' . $cellNum . '
                            </td>
                        </tr>
                        <tr>
                            <td align="center" valign="middle" style="height:54px;font-family:\'Fraunces\',Georgia,serif;font-size:38px;font-weight:700;color:#d95d39;line-height:1;">
                                X
                            </td>
                        </tr>
                    </table>
                </td>';
            } elseif ($val === 'O') {
                $rowsHtml .= '
                <td align="center" valign="middle" style="width:76px;height:76px;background-color:#e6f4f1;border-radius:11px;border:2px solid #2b7a78;padding:0;margin:0;vertical-align:middle;text-align:center;">
                    <table role="presentation" width="100%" height="76" cellspacing="0" cellpadding="0" border="0" style="width:76px;height:76px;border-collapse:collapse;">
                        <tr>
                            <td align="left" valign="top" style="padding:4px 0 0 6px;height:12px;font-family:\'Plus Jakarta Sans\',Arial,sans-serif;font-size:10px;font-weight:700;color:#2b7a78;line-height:1;">
                                ' . $cellNum . '
                            </td>
                        </tr>
                        <tr>
                            <td align="center" valign="middle" style="height:54px;font-family:\'Fraunces\',Georgia,serif;font-size:38px;font-weight:700;color:#2b7a78;line-height:1;">
                                O
                            </td>
                        </tr>
                    </table>
                </td>';
            } elseif ($isRecipientTurn) {
                $moveUrl = BASE_URL . '?action=move&game=' . urlencode($gameId) . '&cell=' . $idx . '&token=' . urlencode($token);

                $rowsHtml .= '
                <td align="center" valign="middle" style="width:76px;height:76px;background-color:#fdfbf7;border-radius:11px;padding:0;margin:0;vertical-align:middle;text-align:center;">
                    <a href="' . htmlspecialchars($moveUrl, ENT_QUOTES, 'UTF-8') . '" target="_blank" style="display:block;width:76px;height:76px;text-decoration:none;border-radius:11px;background-color:#fdfbf7;">
                        <table role="presentation" width="100%" height="76" cellspacing="0" cellpadding="0" border="0" style="width:76px;height:76px;border-collapse:collapse;">
                            <tr>
                                <td align="left" valign="top" style="padding:4px 0 0 6px;height:12px;font-family:\'Plus Jakarta Sans\',Arial,sans-serif;font-size:10px;font-weight:700;color:#b5ac9a;line-height:1;">
                                    ' . $cellNum . '
                                </td>
                            </tr>
                            <tr>
                                <td align="center" valign="middle" style="height:54px;font-size:14px;color:#cbd5e1;line-height:1;">
                                    &nbsp;
                                </td>
                            </tr>
                        </table>
                    </a>
                </td>';
            } else {
                $rowsHtml .= '
                <td align="center" valign="middle" style="width:76px;height:76px;background-color:#fdfbf7;border-radius:11px;padding:0;margin:0;vertical-align:middle;text-align:center;">
                    <table role="presentation" width="100%" height="76" cellspacing="0" cellpadding="0" border="0" style="width:76px;height:76px;border-collapse:collapse;">
                        <tr>
                            <td align="left" valign="top" style="padding:4px 0 0 6px;height:12px;font-family:\'Plus Jakarta Sans\',Arial,sans-serif;font-size:10px;font-weight:700;color:#b5ac9a;line-height:1;">
                                ' . $cellNum . '
                            </td>
                        </tr>
                        <tr>
                            <td align="center" valign="middle" style="height:54px;font-size:14px;color:#cbd5e1;line-height:1;">
                                &nbsp;
                            </td>
                        </tr>
                    </table>
                </td>';
            }
        }
        $rowsHtml .= '</tr>';
    }

    return '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Email Tic Tac Toe</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    body, table, td, p, a, li, blockquote { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
    table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
    img { -ms-interpolation-mode: bicubic; }
    a[x-apple-data-detectors] { color: inherit !important; text-decoration: none !important; font-size: inherit !important; font-family: inherit !important; font-weight: inherit !important; line-height: inherit !important; }
</style>
</head>
<body style="margin:0;padding:0;background-color:#f6f3eb;font-family:\'Plus Jakarta Sans\',-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;-webkit-font-smoothing:antialiased;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#f6f3eb;padding:36px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:420px;width:100%;background-color:#ffffff;border:2px solid #24211d;border-radius:22px;box-shadow:7px 7px 0px #24211d;overflow:hidden;">
                    <tr>
                        <td align="center" style="padding:28px 22px 24px 22px;">
                            <h1 style="margin:0 auto 4px auto;font-family:\'Fraunces\',Georgia,serif;font-size:28px;font-weight:700;color:#24211d;letter-spacing:-0.5px;line-height:1.2;text-align:center;">Email Tic Tac Toe</h1>
                            <p style="margin:0 0 16px 0;font-family:\'Plus Jakarta Sans\',Arial,sans-serif;font-size:12px;color:#5c5549;line-height:1.5;text-align:center;">Send a live match straight to a friend\'s inbox.</p>

                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#faf8f3;border:1.5px solid #e2dac8;border-radius:16px;margin-bottom:16px;padding:12px 14px;">
                                <tr>
                                    <td align="left" style="font-family:\'Plus Jakarta Sans\',Arial,sans-serif;font-size:12.5px;color:#5c5549;line-height:1.4;">
                                        <span style="display:inline-block;width:22px;height:22px;line-height:22px;text-align:center;border-radius:6px;font-family:\'Fraunces\',Georgia,serif;font-size:13px;font-weight:700;color:#ffffff;background-color:' . $recipientColor . ';margin-right:6px;vertical-align:middle;">' . $recipientRole . '</span>
                                        You are <strong>' . $recipientRole . '</strong> vs <strong>' . htmlspecialchars($opponentEmail, ENT_QUOTES, 'UTF-8') . '</strong>
                                    </td>
                                    <td align="right" valign="middle" style="font-family:\'Plus Jakarta Sans\',Arial,sans-serif;font-size:11px;font-weight:700;color:#6b6356;white-space:nowrap;padding-left:8px;">
                                        <span style="background-color:#ffffff;border:1px solid #d5ccb8;border-radius:6px;padding:2px 7px;">#' . htmlspecialchars($gameId, ENT_QUOTES, 'UTF-8') . '</span>
                                    </td>
                                </tr>
                            </table>

                            <div style="background-color:' . $statusBg . ';color:' . $statusColor . ';border:1px solid ' . $statusBorder . ';border-radius:10px;padding:10px 14px;font-family:\'Plus Jakarta Sans\',Arial,sans-serif;font-size:13px;font-weight:700;text-align:center;line-height:1.4;margin-bottom:18px;">
                                ' . htmlspecialchars($statusText, ENT_QUOTES, 'UTF-8') . '
                            </div>

                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="background-color:#24211d;border-radius:18px;padding:9px;margin:0 auto 16px auto;">
                                <tr>
                                    <td>
                                        <table role="presentation" cellspacing="8" cellpadding="0" border="0" style="border-collapse:separate;margin:0 auto;">
                                            ' . $rowsHtml . '
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            ' . ($isRecipientTurn ? '<div style="font-family:\'Plus Jakarta Sans\',Arial,sans-serif;font-size:12px;color:#787061;text-align:center;margin-bottom:12px;font-weight:500;">Click an empty square to make your move directly.</div>' : '') . '

                            <div style="font-family:\'Plus Jakarta Sans\',Arial,sans-serif;font-size:11.5px;color:#787061;text-align:center;line-height:1.4;">
                                Session expires after 15 minutes of inactivity
                            </div>
                        </td>
                    </tr>
                </table>

                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:420px;margin-top:16px;">
                    <tr>
                        <td align="center" style="background-color:#ffffff;border:1.5px solid #24211d;border-radius:16px;padding:12px 16px;box-shadow:4px 4px 0px #e2dac8;">
                            <div style="font-family:\'Plus Jakarta Sans\',Arial,sans-serif;font-size:12px;color:#5c5549;line-height:1.4;text-align:center;">
                                <strong style="color:#24211d;">Seamless Play:</strong> Click any square in your email to instantly play your move. Tested and supported in Gmail, Roundcube, and modern HTML email clients.
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
}

function renderWebBoardPreview(array $game, string $viewerRole, ?int $selectedCell = null): string {
    $board = $game['board'];
    $isViewerTurn = ($game['status'] === 'ACTIVE' && $game['turn'] === $viewerRole);
    $token = $game['tokens'][$viewerRole];
    $gameId = $game['id'];
    $opponentEmail = $viewerRole === 'X' ? $game['player_o'] : $game['player_x'];

    $bannerClass = 'status-info';
    if ($game['status'] === 'WON') {
        $bannerClass = ($game['winner'] === $viewerRole) ? 'status-win' : 'status-lose';
        $statusText = ($game['winner'] === $viewerRole) ? 'You won the match!' : 'You lost! (' . $game['winner'] . ' won)';
    } elseif ($game['status'] === 'DRAW') {
        $bannerClass = 'status-warn';
        $statusText = "It's a draw!";
    } else {
        if ($isViewerTurn) {
            $statusText = $selectedCell !== null
                ? 'Square ' . ($selectedCell + 1) . ' selected! Click Confirm Move below to send.'
                : 'Your turn as ' . $viewerRole . '. Click an empty square.';
        } else {
            $statusText = 'Move sent! Waiting for opponent (' . $game['turn'] . ') to play.';
        }
    }

    $cellsHtml = '';
    for ($i = 0; $i < 9; $i++) {
        $val = $board[$i];
        $isSelected = ($selectedCell === $i);

        if ($val === 'X') {
            $cellsHtml .= '<div class="preview-cell taken-x"><span class="cell-num">' . ($i + 1) . '</span><span class="mark-x">X</span></div>';
        } elseif ($val === 'O') {
            $cellsHtml .= '<div class="preview-cell taken-o"><span class="cell-num">' . ($i + 1) . '</span><span class="mark-o">O</span></div>';
        } elseif ($isViewerTurn) {
            $url = BASE_URL . '?action=move&game=' . urlencode($gameId) . '&cell=' . $i . '&token=' . urlencode($token);
            $selectedClass = $isSelected ? ' is-selected-' . strtolower($viewerRole) : '';
            $markClass = $viewerRole === 'X' ? 'mark-x' : 'mark-o';
            $cellsHtml .= '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" class="preview-cell interactive' . $selectedClass . '"><span class="cell-num">' . ($i + 1) . '</span><span class="' . $markClass . ' ghost-mark">' . $viewerRole . '</span></a>';
        } else {
            $cellsHtml .= '<div class="preview-cell"><span class="cell-num">' . ($i + 1) . '</span></div>';
        }
    }

    $confirmHtml = '';
    if ($isViewerTurn && $selectedCell !== null) {
        $confirmUrl = BASE_URL . '?action=move&game=' . urlencode($gameId) . '&cell=' . $selectedCell . '&token=' . urlencode($token);
        $confirmHtml = '<a href="' . htmlspecialchars($confirmUrl, ENT_QUOTES, 'UTF-8') . '" class="confirm-btn">Confirm Move (Square ' . ($selectedCell + 1) . ') &rarr;</a>';
    }

    return '
    <div class="match-panel">
        <div class="match-meta">
            <span>vs <strong>' . htmlspecialchars($opponentEmail, ENT_QUOTES, 'UTF-8') . '</strong></span>
            <span class="game-id-tag">#' . htmlspecialchars($gameId, ENT_QUOTES, 'UTF-8') . '</span>
        </div>
        <div class="status-banner ' . $bannerClass . '">' . htmlspecialchars($statusText, ENT_QUOTES, 'UTF-8') . '</div>
        <div class="board-wrap" style="margin-bottom:14px;">
            <div class="ttt-grid">
                ' . $cellsHtml . '
            </div>
        </div>
        ' . $confirmHtml . '
        <div class="match-footer">Session expires after 15 minutes of inactivity</div>
    </div>';
}

function sendGameEmail(string $toEmail, string $subject, string $htmlBody): bool {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = SMTP_PORT;
        $mail->CharSet = 'UTF-8';

        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($toEmail);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = 'Please view this email in an HTML-compatible email client like Gmail to play Tic Tac Toe.';

        $mail->send();
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function renderWebLayout(string $title, string $bodyContent): void {
    echo '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
* { box-sizing: border-box; }
body {
    margin: 0;
    padding: 40px 16px;
    background-color: #f6f3eb;
    background-image: radial-gradient(#dfd7c5 1.25px, transparent 1.25px);
    background-size: 24px 24px;
    font-family: "Plus Jakarta Sans", sans-serif;
    color: #24211d;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    position: relative;
    overflow-x: hidden;
}
.bg-glyphs {
    position: fixed;
    inset: 0;
    pointer-events: none;
    z-index: 0;
    overflow: hidden;
}
.glyph {
    position: absolute;
    font-family: "Fraunces", serif;
    font-weight: 700;
    user-select: none;
    opacity: 0.08;
    animation: drift 16s ease-in-out infinite;
}
.g1 { top: 8%; left: 7%; font-size: 130px; color: #d95d39; animation-delay: 0s; }
.g2 { bottom: 10%; right: 8%; font-size: 150px; color: #2b7a78; animation-delay: -5s; }
.g3 { top: 62%; left: 10%; font-size: 95px; color: #2b7a78; animation-delay: -9s; }
.g4 { top: 14%; right: 12%; font-size: 110px; color: #d95d39; animation-delay: -12s; }
@keyframes drift {
    0%, 100% { transform: translateY(0px) rotate(-6deg); }
    50% { transform: translateY(-22px) rotate(8deg); }
}
.wrapper {
    position: relative;
    z-index: 1;
    width: 100%;
    max-width: 460px;
}
.card {
    width: 100%;
    background: #ffffff;
    border: 2px solid #24211d;
    border-radius: 22px;
    padding: 32px;
    box-shadow: 7px 7px 0px #24211d;
}
.badge-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 14px;
}
.pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #fef3c7;
    border: 1.5px solid #24211d;
    color: #24211d;
    font-size: 12px;
    font-weight: 700;
    padding: 4px 12px;
    border-radius: 999px;
}
.dot {
    width: 7px;
    height: 7px;
    background: #16a34a;
    border-radius: 50%;
    animation: pulse 2s infinite;
}
@keyframes pulse {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.4; transform: scale(1.3); }
}
.title {
    font-family: "Fraunces", serif;
    display: flex;
    justify-content: center;
    margin: 0 auto 4px auto;
    font-size: 30px;
    font-weight: 700;
    color: #24211d;
    letter-spacing: -0.5px;
    line-height: 1.15;
}
.subtitle {
    margin: 0 0 2px 0;
    display: flex;
    justify-content: center;
    font-size: 12px;
    color: #5c5549;
    line-height: 1.5;
    word-break: break;
}
.players-box {
    background: #faf8f3;
    border: 1.5px solid #e2dac8;
    border-radius: 16px;
    padding: 16px;
    margin-bottom: 22px;
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.player-field {
    display: flex;
    flex-direction: column;
}
.player-label {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    font-weight: 700;
    margin-bottom: 6px;
    color: #24211d;
}
.tag-x, .tag-o {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    border-radius: 6px;
    font-family: "Fraunces", serif;
    font-size: 13px;
    font-weight: 700;
    color: #ffffff;
}
.tag-x { background: #d95d39; }
.tag-o { background: #2b7a78; }
.text-input {
    width: 100%;
    padding: 11px 14px;
    border: 1.5px solid #d5ccb8;
    border-radius: 10px;
    font-family: "Plus Jakarta Sans", sans-serif;
    font-size: 14px;
    background: #ffffff;
    color: #24211d;
    transition: border-color 0.2s, box-shadow 0.2s;
}
.text-input:focus {
    outline: none;
    border-color: #24211d;
    box-shadow: 3px 3px 0px #24211d;
}
.board-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 10px;
}
.board-header span {
    font-size: 13px;
    font-weight: 700;
    color: #24211d;
}
.board-hint {
    font-size: 12px !important;
    font-weight: 500 !important;
    color: #787061 !important;
}
.board-wrap {
    background: #24211d;
    padding: 10px;
    border-radius: 18px;
    margin-bottom: 24px;
    max-width: 280px;
    margin-left: auto;
    margin-right: auto;
}
.ttt-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
}
.ttt-cell, .preview-cell {
    position: relative;
    aspect-ratio: 1 / 1;
    background: #fdfbf7;
    border-radius: 11px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: transform 0.15s ease, background-color 0.2s ease;
    user-select: none;
    text-decoration: none;
}
.ttt-cell, .preview-cell.interactive {
    cursor: pointer;
}
.ttt-cell input[type="radio"] {
    position: absolute;
    opacity: 0;
    width: 0;
    height: 0;
}
.cell-num {
    position: absolute;
    top: 6px;
    left: 8px;
    font-size: 11px;
    font-weight: 700;
    color: #b5ac9a;
    transition: opacity 0.2s;
}
.cell-mark, .mark-x, .mark-o {
    font-family: "Fraunces", serif;
    font-size: 40px;
    font-weight: 700;
    line-height: 1;
}
.cell-mark, .mark-x { color: #d95d39; }
.mark-o { color: #2b7a78; }
.cell-mark, .ghost-mark {
    opacity: 0;
    transform: scale(0.4) rotate(-12deg);
    transition: all 0.22s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.ttt-cell:hover, .preview-cell.interactive:hover {
    background: #fff5f2;
    transform: translateY(-2px);
}
.ttt-cell:hover .cell-mark, .preview-cell.interactive:hover .ghost-mark {
    opacity: 0.32;
    transform: scale(0.9) rotate(0deg);
}
.ttt-cell:has(input:checked), .preview-cell.taken-x, .preview-cell.is-selected-x {
    background: #ffede8;
    box-shadow: inset 0 0 0 2.5px #d95d39;
}
.preview-cell.taken-o, .preview-cell.is-selected-o {
    background: #e6f4f1;
    box-shadow: inset 0 0 0 2.5px #2b7a78;
}
.ttt-cell:has(input:checked) .cell-mark,
.preview-cell.is-selected-x .ghost-mark,
.preview-cell.is-selected-o .ghost-mark {
    opacity: 1;
    transform: scale(1) rotate(0deg);
}
.ttt-cell:has(input:checked) .cell-num,
.preview-cell.taken-x .cell-num,
.preview-cell.is-selected-x .cell-num {
    color: #d95d39;
}
.preview-cell.taken-o .cell-num,
.preview-cell.is-selected-o .cell-num {
    color: #2b7a78;
}
.match-panel {
    background: #faf8f3;
    border: 1.5px solid #e2dac8;
    border-radius: 18px;
    padding: 18px;
    margin-bottom: 20px;
}
.match-meta {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    font-size: 12.5px;
    color: #5c5549;
    margin-bottom: 12px;
    flex-wrap: wrap;
}
.match-meta strong {
    color: #24211d;
    word-break: break-all;
}
.game-id-tag {
    background: #ffffff;
    border: 1px solid #d5ccb8;
    border-radius: 6px;
    padding: 2px 7px;
    font-size: 11px;
    font-weight: 700;
    color: #6b6356;
}
.status-banner {
    padding: 10px 14px;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 700;
    text-align: center;
    margin-bottom: 16px;
    line-height: 1.4;
}
.status-info { background: #e6f4f1; color: #1d5c5a; border: 1px solid #b5ddd6; }
.status-win { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
.status-lose { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
.status-warn { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
.match-footer {
    text-align: center;
    font-size: 11.5px;
    color: #787061;
    margin-top: 8px;
}
.submit-btn, .confirm-btn {
    width: 100%;
    padding: 15px;
    background-color: #d95d39;
    color: #ffffff;
    border: 2px solid #24211d;
    border-radius: 14px;
    font-family: "Plus Jakarta Sans", sans-serif;
    font-size: 15px;
    font-weight: 700;
    cursor: pointer;
    box-shadow: 4px 4px 0px #24211d;
    transition: transform 0.15s ease, box-shadow 0.15s ease, background-color 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    text-decoration: none;
}
.confirm-btn {
    background-color: #2b7a78;
    margin-bottom: 8px;
}
.confirm-btn:hover {
    background-color: #226361;
    transform: translate(-1px, -1px);
    box-shadow: 5px 5px 0px #24211d;
}
.submit-btn:hover {
    background-color: #c74e2b;
    transform: translate(-1px, -1px);
    box-shadow: 5px 5px 0px #24211d;
}
.submit-btn:active, .confirm-btn:active {
    transform: translate(3px, 3px);
    box-shadow: 1px 1px 0px #24211d;
}
.client-info {
    margin-top: 20px;
    background: #ffffff;
    border: 1.5px solid #24211d;
    border-radius: 16px;
    padding: 14px 18px;
    font-size: 12.5px;
    color: #5c5549;
    line-height: 1.5;
    box-shadow: 4px 4px 0px #e2dac8;
}
.client-info strong {
    color: #24211d;
}
.client-badges {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 8px;
}
.c-badge {
    background: #f6f3eb;
    border: 1px solid #d5ccb8;
    border-radius: 6px;
    padding: 2px 8px;
    font-size: 11.5px;
    font-weight: 600;
    color: #24211d;
}
@media (max-width: 480px) {
    .card { padding: 24px 20px; }
    .title { font-size: 26px; }
    .board-wrap { max-width: 250px; }
}
</style>
</head>
<body>
    <div class="bg-glyphs" aria-hidden="true">
        <span class="glyph g1">X</span>
        <span class="glyph g2">O</span>
        <span class="glyph g3">O</span>
        <span class="glyph g4">X</span>
    </div>
    <div class="wrapper">
        <div class="card">
            ' . $bodyContent . '
        </div>
        <div class="client-info">
            <strong>Supported Email Clients:</strong> Tested and confirmed working in <strong>Gmail</strong> and <strong>Roundcube</strong>. Playable in any email client that supports standard HTML tables and links.
            <div class="client-badges">
                <span class="c-badge">✓ Gmail</span>
                <span class="c-badge">✓ Roundcube</span>
                <span class="c-badge">✓ HTML Email Clients</span>
            </div>
        </div>
    </div>
</body>
</html>';
}

$games = loadGames();
cleanExpiredGames($games);

$action = $_REQUEST['action'] ?? 'home';

if ($action === 'start' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!checkRateLimit($clientIp)) {
        renderWebLayout('Rate Limited', '
            <h2 class="title" style="font-size:24px;color:#d95d39;">Whoa, slow down!</h2>
            <p class="subtitle">You are starting matches a little too fast. Wait a few seconds before sending another board.</p>
            <a href="' . htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') . '" class="submit-btn">Back to Board</a>
        ');
        exit;
    }

    $playerX = filter_var(trim($_POST['player_x'] ?? ''), FILTER_VALIDATE_EMAIL);
    $playerO = filter_var(trim($_POST['player_o'] ?? ''), FILTER_VALIDATE_EMAIL);
    $firstCell = isset($_POST['cell']) ? (int)$_POST['cell'] : -1;

    if (!$playerX || !$playerO || $playerX === $playerO || $firstCell < 0 || $firstCell > 8) {
        renderWebLayout('Invalid Input', '
            <h2 class="title" style="font-size:24px;color:#dc2626;">Check Your Setup</h2>
            <p class="subtitle">Make sure both email addresses are valid, different from each other, and you picked an opening square.</p>
            <a href="' . htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') . '" class="submit-btn">Try Again</a>
        ');
        exit;
    }

    $gameId = bin2hex(random_bytes(6));
    $board = array_fill(0, 9, '');
    $board[$firstCell] = 'X';

    $game = [
        'id' => $gameId,
        'player_x' => $playerX,
        'player_o' => $playerO,
        'tokens' => [
            'X' => bin2hex(random_bytes(12)),
            'O' => bin2hex(random_bytes(12))
        ],
        'board' => $board,
        'turn' => 'O',
        'status' => 'ACTIVE',
        'winner' => null,
        'updated_at' => time()
    ];

    $games[$gameId] = $game;
    saveGames($games);

    $htmlForO = buildEmailHtml($game, 'O');
    $subject = "Your turn in Tic Tac Toe vs {$playerX} [Game #{$gameId}]";
    $sent = sendGameEmail($playerO, $subject, $htmlForO);

    if ($sent) {
        renderWebLayout('Match Sent!', '
            <h2 class="title" style="font-size:26px;">First Move Delivered!</h2>
            <p class="subtitle">Your opening <strong>X</strong> has been sent to <strong>' . htmlspecialchars($playerO, ENT_QUOTES, 'UTF-8') . '</strong>. Keep an eye on your inbox for their reply!</p>
            ' . renderWebBoardPreview($game, 'X') . '
            <a href="' . htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') . '" class="submit-btn">Start Another Match</a>
        ');
    } else {
        renderWebLayout('SMTP Error', '
            <h2 class="title" style="font-size:24px;color:#dc2626;">Email Delivery Failed</h2>
            <p class="subtitle">Could not dispatch the email via PHPMailer. Double-check your SMTP credentials in <code>.env</code>.</p>
        ');
    }
    exit;
}

if ($action === 'move' || $action === 'confirm' || $action === 'select') {
    $gameId = $_GET['game'] ?? '';
    $token = $_GET['token'] ?? '';
    $cell = isset($_GET['cell']) ? (int)$_GET['cell'] : -1;

    if (!isset($games[$gameId])) {
        renderWebLayout('Session Expired', '
            <h2 class="title" style="font-size:24px;color:#dc2626;">Match Expired</h2>
            <p class="subtitle">This match timed out after 15 minutes of inactivity or no longer exists.</p>
            <a href="' . htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') . '" class="submit-btn">Start a Fresh Match</a>
        ');
        exit;
    }

    $game = &$games[$gameId];
    $playerRole = null;
    if (hash_equals($game['tokens']['X'], $token)) {
        $playerRole = 'X';
    } elseif (hash_equals($game['tokens']['O'], $token)) {
        $playerRole = 'O';
    }

    if ($playerRole === null) {
        renderWebLayout('Unauthorized', '<h2 class="title" style="font-size:24px;color:#dc2626;">Invalid Player Token</h2>');
        exit;
    }

    if ($game['status'] !== 'ACTIVE') {
        renderWebLayout('Match Finished', '
            <h2 class="title" style="font-size:26px;">Match Already Completed</h2>
            ' . renderWebBoardPreview($game, $playerRole) . '
            <a href="' . htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') . '" class="submit-btn">Start Another Match</a>
        ');
        exit;
    }

    if ($game['turn'] !== $playerRole) {
        renderWebLayout('Not Your Turn', '
            <h2 class="title" style="font-size:24px;color:#a16207;">Waiting on Opponent</h2>
            <p class="subtitle">It is currently <strong>' . htmlspecialchars($game['turn'], ENT_QUOTES, 'UTF-8') . '</strong>\'s turn to make a move.</p>
            ' . renderWebBoardPreview($game, $playerRole)
        );
        exit;
    }

    if ($cell < 0 || $cell > 8 || $game['board'][$cell] !== '') {
        renderWebLayout('Invalid Move', '
            <h2 class="title" style="font-size:24px;color:#dc2626;">Square Taken</h2>
            <p class="subtitle">That square is already claimed. Pick an empty spot below.</p>
            ' . renderWebBoardPreview($game, $playerRole)
        );
        exit;
    }

    // Execute move directly without confirmation step
    $game['board'][$cell] = $playerRole;
    $game['updated_at'] = time();

    $winner = checkWinner($game['board']);
    $nextRole = ($playerRole === 'X') ? 'O' : 'X';

    if ($winner === 'DRAW') {
        $game['status'] = 'DRAW';
        saveGames($games);

        sendGameEmail(
            $game['player_x'],
            "Tic Tac Toe Draw! [Game #{$gameId}]",
            buildEmailHtml($game, 'X')
        );
        sendGameEmail(
            $game['player_o'],
            "Tic Tac Toe Draw! [Game #{$gameId}]",
            buildEmailHtml($game, 'O')
        );

        $heading = "It's a Draw!";
        $desc = "The game ended in a draw! Results have been emailed to both players.";
    } elseif ($winner === 'X' || $winner === 'O') {
        $game['status'] = 'WON';
        $game['winner'] = $winner;
        saveGames($games);

        sendGameEmail(
            $game['player_x'],
            ($winner === 'X' ? "You Won!" : "You Lost!") . " Tic Tac Toe [Game #{$gameId}]",
            buildEmailHtml($game, 'X')
        );
        sendGameEmail(
            $game['player_o'],
            ($winner === 'O' ? "You Won!" : "You Lost!") . " Tic Tac Toe [Game #{$gameId}]",
            buildEmailHtml($game, 'O')
        );

        $heading = ($winner === $playerRole) ? "You Won!" : "Match Complete!";
        $desc = "Game over! " . $winner . " won the match. Results have been emailed to both players.";
    } else {
        $game['turn'] = $nextRole;
        saveGames($games);

        $recipientEmail = ($nextRole === 'X') ? $game['player_x'] : $game['player_o'];
        $senderEmail = ($playerRole === 'X') ? $game['player_x'] : $game['player_o'];

        sendGameEmail(
            $recipientEmail,
            "Your turn in Tic Tac Toe vs {$senderEmail} [Game #{$gameId}]",
            buildEmailHtml($game, $nextRole)
        );

        $heading = "Move Sent!";
        $desc = "Square " . ($cell + 1) . " played. Your move has been sent to <strong>" . htmlspecialchars($recipientEmail, ENT_QUOTES, 'UTF-8') . "</strong>.";
    }

    renderWebLayout($heading, '
        <div class="badge-row">
            <div class="pill">
                <span class="dot"></span>
                <span>Move Recorded</span>
            </div>
        </div>
        <h2 class="title" style="font-size:26px;">' . $heading . '</h2>
        <p class="subtitle" style="margin-bottom:14px;">' . $desc . '</p>
        <div class="status-banner status-info" id="close-banner" style="margin-bottom:16px;">
            Closing tab and returning you to your email...
        </div>
        <button type="button" onclick="window.close()" id="manual-close-btn" class="submit-btn" style="background-color:#2b7a78;margin-bottom:14px;display:none;">
            <span>Close Tab & Return to Email</span> &rarr;
        </button>
        ' . renderWebBoardPreview($game, $playerRole) . '
        <a href="' . htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') . '" class="submit-btn" style="margin-top:10px;">Start Another Match</a>
        <script>
            function tryAutoClose() {
                window.close();
            }
            tryAutoClose();
            document.addEventListener("DOMContentLoaded", tryAutoClose);
            setTimeout(tryAutoClose, 250);
            setTimeout(tryAutoClose, 600);
            setTimeout(function() {
                var btn = document.getElementById("manual-close-btn");
                var banner = document.getElementById("close-banner");
                if (btn) btn.style.display = "flex";
                if (banner) banner.textContent = "Move recorded! You can now close this tab to return to your email window.";
            }, 800);
        </script>
    ');
    exit;
}

$gridSelectors = '';
for ($i = 0; $i < 9; $i++) {
    $checked = ($i === 4) ? 'checked' : '';
    $gridSelectors .= '
    <label class="ttt-cell">
        <input type="radio" name="cell" value="' . $i . '" ' . $checked . ' required>
        <span class="cell-num">' . ($i + 1) . '</span>
        <span class="cell-mark">X</span>
    </label>';
}

renderWebLayout('Email Tic Tac Toe', '
    <h1 class="title">Email Tic Tac Toe</h1>
    <p class="subtitle">Send a live match straight to a friend\'s inbox. Pick your opening square below to kick things off.</p>
    <form method="POST" action="' . htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') . '">
        <input type="hidden" name="action" value="start">
        <div class="players-box">
            <div class="player-field">
                <label class="player-label"><span class="tag-x">X</span> Your Email</label>
                <input class="text-input" type="email" name="player_x" required placeholder="you@gmail.com">
            </div>
            <div class="player-field">
                <label class="player-label"><span class="tag-o">O</span> Opponent\'s Email</label>
                <input class="text-input" type="email" name="player_o" required placeholder="friend@gmail.com">
            </div>
        </div>
        <div class="board-header">
            <span>Place Your Opening X</span>
            <span class="board-hint">Click any square</span>
        </div>
        <div class="board-wrap">
            <div class="ttt-grid">
                ' . $gridSelectors . '
            </div>
        </div>
        <button type="submit" class="submit-btn">
            <span>Send Opening Move</span>
            <span>&rarr;</span>
        </button>
    </form>
');