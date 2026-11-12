<?php

declare(strict_types=1);

function invoice_document(array $d, bool $standalone = true): string
{
    $gym = $d['gym'];
    $invoice = $d['invoice'];
    $member = $d['member'];
    $plan = $d['plan'];
    $items = $d['items'];
    $payments = $d['payments'];
    $symbol = $gym['currency_symbol'] ?: '₹';
    $money = static function (mixed $amount) use ($symbol): string {
        return $symbol . number_format((float) money_norm($amount), 2);
    };
    $address = trim(implode(', ', array_filter([
        $gym['address_line'] ?? '',
        $gym['city'] ?? '',
        $gym['state'] ?? '',
        $gym['postal_code'] ?? '',
        $gym['country'] ?? '',
    ])));
    $logo = '';
    if (!empty($d['logo'])) {
        $logo = '<img src="' . e($d['logo']) . '" alt="" style="height:54px;width:auto;max-width:160px;">';
    } else {
        $logo = '<div style="width:48px;height:48px;border-radius:14px;background:#111;color:#d6ff4a;font-weight:800;text-align:center;line-height:48px;font-size:20px;">G</div>';
    }
    $rows = '';
    foreach ($items as $item) {
        $rows .= '<tr>
            <td style="padding:12px 10px;border-bottom:1px solid #ececec;">' . e($item['description']) . '</td>
            <td style="padding:12px 10px;border-bottom:1px solid #ececec;text-align:right;">' . e(rtrim(rtrim(number_format((float) $item['quantity'], 2), '0'), '.')) . '</td>
            <td style="padding:12px 10px;border-bottom:1px solid #ececec;text-align:right;">' . e($money($item['unit_price'])) . '</td>
            <td style="padding:12px 10px;border-bottom:1px solid #ececec;text-align:right;">' . e($money($item['amount'])) . '</td>
        </tr>';
    }
    $payRows = '';
    if (!$payments) {
        $payRows = '<tr><td colspan="4" style="padding:10px;color:#777;">No payments recorded.</td></tr>';
    }
    foreach ($payments as $payment) {
        $payRows .= '<tr>
            <td style="padding:8px 10px;border-bottom:1px solid #f0f0f0;">' . e(fmt_date($payment['payment_date'])) . '</td>
            <td style="padding:8px 10px;border-bottom:1px solid #f0f0f0;">' . e(status_label($payment['method'])) . '</td>
            <td style="padding:8px 10px;border-bottom:1px solid #f0f0f0;">' . e($payment['reference_no'] ?: '—') . '</td>
            <td style="padding:8px 10px;border-bottom:1px solid #f0f0f0;text-align:right;">' . e($money($payment['amount'])) . '</td>
        </tr>';
    }
    $tone = match ($invoice['status']) {
        'paid' => '#1f7a32',
        'partial' => '#9a6b00',
        default => '#9d2433',
    };
    $paper = '<div style="font-family:DejaVu Sans,sans-serif;color:#1b1d21;background:#fff;padding:28px;">
        <table style="width:100%;border-collapse:collapse;"><tr>
            <td style="vertical-align:top;">' . $logo . '
                <div style="font-size:22px;font-weight:800;margin-top:10px;">' . e($gym['name']) . '</div>
                <div style="color:#555;font-size:12px;line-height:1.5;max-width:340px;">' . e($address) . '<br>'
                . e($gym['phone'] ?: '') . ($gym['email'] ? ' · ' . e($gym['email']) : '') 
                . (!empty($gym['gstin']) ? '<br>GSTIN ' . e($gym['gstin']) : '') . '</div>
            </td>
            <td style="vertical-align:top;text-align:right;">
                <div style="font-size:28px;letter-spacing:2px;font-weight:800;">INVOICE</div>
                <div style="margin-top:8px;font-size:13px;">No. <strong>' . e($invoice['invoice_number']) . '</strong></div>
                <div style="font-size:13px;">Date ' . e(fmt_date($invoice['invoice_date'])) . '</div>
                <div style="margin-top:8px;display:inline-block;padding:4px 10px;border-radius:99px;background:#f4f6f2;color:' . $tone . ';font-weight:700;font-size:12px;">' . e(status_label($invoice['status'])) . '</div>
            </td>
        </tr></table>
        <table style="width:100%;margin-top:22px;background:#f7f8f5;border-radius:12px;"><tr>
            <td style="padding:14px 16px;width:50%;vertical-align:top;">
                <div style="font-size:11px;letter-spacing:1px;color:#777;">BILLED TO</div>
                <div style="font-weight:700;margin-top:4px;">' . e($member['full_name']) . '</div>
                <div style="font-size:12px;color:#444;">' . e($member['member_code']) . '<br>' . e($member['phone']) . ($member['email'] ? '<br>' . e($member['email']) : '') . '</div>
            </td>
            <td style="padding:14px 16px;vertical-align:top;">
                <div style="font-size:11px;letter-spacing:1px;color:#777;">MEMBERSHIP</div>
                <div style="font-weight:700;margin-top:4px;">' . e($plan['name']) . '</div>
                <div style="font-size:12px;color:#444;">' . e(fmt_date($d['subscription']['start_date'])) . ' to ' . e(fmt_date($d['subscription']['end_date'])) . '</div>
            </td>
        </tr></table>
        <table style="width:100%;border-collapse:collapse;margin-top:18px;font-size:13px;">
            <thead><tr style="background:#14160f;color:#f3f6ea;">
                <th style="text-align:left;padding:10px;">Description</th>
                <th style="text-align:right;padding:10px;">Qty</th>
                <th style="text-align:right;padding:10px;">Rate</th>
                <th style="text-align:right;padding:10px;">Amount</th>
            </tr></thead>
            <tbody>' . $rows . '</tbody>
        </table>
        <table style="width:280px;margin-left:auto;margin-top:12px;font-size:13px;border-collapse:collapse;">
            <tr><td style="padding:4px 8px;color:#555;">Subtotal</td><td style="padding:4px 8px;text-align:right;">' . e($money($invoice['subtotal'])) . '</td></tr>
            <tr><td style="padding:4px 8px;color:#555;">Discount</td><td style="padding:4px 8px;text-align:right;">' . e($money($invoice['discount'])) . '</td></tr>
            <tr><td style="padding:4px 8px;color:#555;">' . e($gym['tax_label'] ?: 'Tax') . ' (' . e(rtrim(rtrim(number_format((float) $invoice['tax_percent'], 2), '0'), '.')) . '%)</td><td style="padding:4px 8px;text-align:right;">' . e($money($invoice['tax_amount'])) . '</td></tr>
            <tr><td style="padding:8px;font-weight:800;border-top:2px solid #14160f;">Total</td><td style="padding:8px;text-align:right;font-weight:800;border-top:2px solid #14160f;">' . e($money($invoice['total_amount'])) . '</td></tr>
            <tr><td style="padding:4px 8px;">Amount paid</td><td style="padding:4px 8px;text-align:right;">' . e($money($invoice['amount_paid'])) . '</td></tr>
            <tr><td style="padding:4px 8px;font-weight:700;">Balance due</td><td style="padding:4px 8px;text-align:right;font-weight:700;color:' . $tone . ';">' . e($money($invoice['balance_due'])) . '</td></tr>
        </table>
        <div style="margin-top:8px;font-size:12px;color:#555;">' . e(amount_in_words((string) $invoice['total_amount'], (string) ($gym['currency_code'] ?: 'INR'))) . '</div>
        <div style="margin-top:22px;font-size:13px;font-weight:700;">Payment history</div>
        <table style="width:100%;border-collapse:collapse;font-size:12px;margin-top:6px;">
            <thead><tr style="color:#666;text-align:left;"><th style="padding:6px 10px;">Date</th><th>Method</th><th>Reference</th><th style="text-align:right;">Amount</th></tr></thead>
            <tbody>' . $payRows . '</tbody>
        </table>
        <div style="margin-top:26px;border-top:1px solid #ececec;padding-top:12px;font-size:11px;color:#666;line-height:1.5;">'
            . nl2br(e($gym['invoice_footer'] ?: 'Thank you for training with us.'))
            . '<br>' . nl2br(e($gym['invoice_terms'] ?: 'This invoice records a manual payment collected at the gym. It is not an online payment receipt.'))
            . '</div>
    </div>';
    if (!$standalone) {
        return $paper;
    }
    return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . e($invoice['invoice_number']) . '</title></head><body style="margin:0;background:#fff;">' . $paper . '</body></html>';
}
