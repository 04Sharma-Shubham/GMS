<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
[$user, $gym] = boot_page('assistant.ask');
$question = trim((string) ($_POST['q'] ?? $_GET['q'] ?? ''));
if (is_post()) {
    csrf_verify();
}
$answer = $question !== '' ? AssistantService::ask((int) $gym['id'], $user, $question) : null;
layout_start('Desk', 'assistant');
page_head('Ask the desk', 'Gym desk', 'Answers come from ' . $gym['name'] . ' only. Amounts are read from the ledger, not guessed.');
echo form_open(url('assistant'));
echo '<div class="cluster"><input name="q" value="' . e($question) . '" placeholder="Whose membership is ending?" required style="min-width:min(100%,420px)"><button class="btn btn-primary">Ask</button></div></form>';
echo '<div class="cluster" style="margin:14px 0">';
foreach ([
    'Whose membership is still running?',
    'Who still owes money?',
    'Billing for Meera Iyer',
    'Who is here today?',
] as $prompt) {
    echo '<a class="btn btn-ghost btn-sm" href="' . e(url('assistant?q=' . rawurlencode($prompt))) . '">' . e($prompt) . '</a>';
}
echo '</div>';
if ($answer) {
    echo '<article class="card"><h2>' . e($answer['title']) . '</h2><p>' . e($answer['text']) . '</p>';
    if ($answer['rows']) {
        echo '<div class="table-wrap"><table><tbody>';
        foreach ($answer['rows'] as $row) {
            echo '<tr><td><a href="' . e(url($row['href'])) . '">' . e($row['label']) . '</a>';
            if ($row['meta'] !== '') {
                echo '<br><small class="muted">' . e($row['meta']) . '</small>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</article>';
}
layout_end();
