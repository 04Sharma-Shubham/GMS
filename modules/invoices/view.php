<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
[$user, $gym] = boot_page('invoices.view');
$gymId = (int) $gym['id'];
$id = (int) ($_GET['id'] ?? 0);
$invoice = db_one('SELECT * FROM invoices WHERE id = ? AND gym_id = ?', [$id, $gymId]);
if (!$invoice) {
    flash('error', 'Invoice not found.');
    redirect_to('invoices');
}
handle_post(function () use ($gymId, $invoice) {
    require_permission('invoices.send');
    $member = db_one('SELECT * FROM members WHERE id = ? AND gym_id = ?', [$invoice['member_id'], $gymId]);
    if (!$member || !(int) $member['whatsapp_opt_in']) {
        throw new AppException('This member has not opted in to WhatsApp.');
    }
    $document = null;
    if (str_starts_with((string) env('APP_URL', ''), 'https://')) {
        try {
            InvoiceService::ensurePdf($gymId, (int) $invoice['id']);
            $document = ['link' => InvoiceService::publicUrl($gymId, (int) $invoice['id']), 'filename' => $invoice['invoice_number'] . '.pdf'];
        } catch (Throwable $e) {
            app_log('error', $e->getMessage());
        }
    }
    $gym = current_gym();
    $result = WhatsAppService::send($gymId, (int) $member['id'], (string) $member['phone'], $document ? 'invoice_document' : 'payment_confirmation', $document ? [$member['full_name'], $invoice['invoice_number'], money($invoice['total_amount'], $gym)] : [$member['full_name'], money($invoice['amount_paid'], $gym), $invoice['invoice_number'], money($invoice['balance_due'], $gym)], $document);
    $_SESSION['wa_result'] = $result;
    flash($result['status'] === 'failed' ? 'error' : 'success', $result['status'] === 'sent' ? 'WhatsApp accepted the invoice message.' : ($result['status'] === 'demo' ? 'Demo mode prepared a manual WhatsApp link. It was not delivered automatically.' : ($result['error'] ?: 'WhatsApp did not accept the message.')));
    redirect_to('invoices/' . $invoice['id']);
});
$wa = $_SESSION['wa_result'] ?? null;
unset($_SESSION['wa_result']);
require dirname(__DIR__, 2) . '/includes/views/invoice_document.php';
$html = invoice_document(InvoiceService::bundle($gymId, $id), false);
layout_start($invoice['invoice_number'], 'invoices');
if (!empty($_GET['recorded'])) {
    echo '<div class="success-panel"><div class="checkorb">✓</div><div><strong>Payment saved</strong><p class="muted">The invoice balance was recalculated from the payment ledger.</p></div></div>';
}
if (is_array($wa)) {
    echo '<div class="flash flash-info">' . e($wa['status'] === 'demo' ? 'Manual WhatsApp link, not an automatic delivery.' : ($wa['error'] ?: 'WhatsApp response recorded.')) . ' <a target="_blank" rel="noopener" href="' . e($wa['click_to_chat']) . '">Open chat</a></div>';
}
page_head('Invoice', $invoice['invoice_number'], status_label($invoice['status']) . ' · Balance ' . money($invoice['balance_due'], $gym),
    '<a class="btn btn-primary" href="' . e(url('invoices/' . $id . '/pdf')) . '">Download PDF</a> <a class="btn btn-ghost" href="' . e(url('invoices/' . $id . '/print')) . '" target="_blank">Print</a> <a class="btn btn-ghost" href="' . e(url('payments?invoice=' . $id . '#record')) . '">Record payment</a>');
if (can('invoices.send')) {
    echo form_open();
    echo '<input type="hidden" name="_back" value="' . e(url('invoices/' . $id)) . '"><button class="btn btn-ghost btn-sm" type="submit">Share on WhatsApp</button></form>';
}
echo '<div class="invoice-sheet" style="margin-top:16px">' . $html . '</div>';
layout_end();
