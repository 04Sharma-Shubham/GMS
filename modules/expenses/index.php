<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
[$user, $gym] = boot_page('expenses.manage');
$gymId = (int) $gym['id'];
$today = gym_today($gym);
handle_post(function () use ($gymId, $user, $today) {
    $action = (string) ($_POST['action'] ?? 'expense');
    if ($action === 'category') {
        $name = clip($_POST['name'] ?? '', 120);
        if ($name === '') throw new AppException('Category name is required.');
        try {
            db_insert('INSERT INTO expense_categories (gym_id, name, created_at) VALUES (?,?,?)', [$gymId, $name, utc_now()]);
        } catch (PDOException $e) {
            throw new AppException('That category already exists.');
        }
        flash('success', 'Category added.');
        redirect_to('expenses');
    }
    $amount = money_norm($_POST['amount'] ?? '0');
    $date = (string) ($_POST['expense_date'] ?? $today);
    $category = (int) ($_POST['category_id'] ?? 0);
    $description = clip($_POST['description'] ?? '', 255);
    if (money_comp($amount, '0') <= 0 || !valid_date($date) || $description === '') {
        throw new AppException('Date, category, description, and a positive amount are required.');
    }
    $cat = db_one('SELECT id FROM expense_categories WHERE id = ? AND gym_id = ?', [$category, $gymId]);
    if (!$cat) throw new AppException('Choose a category.');
    $method = (string) ($_POST['payment_method'] ?? 'cash');
    if (!in_array($method, PaymentService::METHODS, true)) $method = 'cash';
    $receipt = store_upload('receipt', 'storage/receipts', receipt_mimes(), 5_000_000);
    $now = utc_now();
    $id = db_insert('INSERT INTO expenses (gym_id, category_id, expense_date, amount, description, receipt_path, payment_method, recorded_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)', [
        $gymId, $category, $date, $amount, $description, $receipt, $method, $user['id'], $now, $now,
    ]);
    AuditService::log($gymId, (int) $user['id'], 'expense.created', 'expense', $id, null, ['summary' => 'Expense ' . $description . ' ' . $amount]);
    flash('success', 'Expense recorded.');
    redirect_to('expenses');
});
$categories = db_all('SELECT * FROM expense_categories WHERE gym_id = ? ORDER BY name', [$gymId]);
$month = substr($today, 0, 7);
$rows = db_all('SELECT e.*, c.name AS category_name FROM expenses e JOIN expense_categories c ON c.id = e.category_id WHERE e.gym_id = ? ORDER BY e.expense_date DESC, e.id DESC LIMIT 100', [$gymId]);
$monthTotal = (string) (db_one('SELECT COALESCE(SUM(amount),0) AS total FROM expenses WHERE gym_id = ? AND expense_date LIKE ?', [$gymId, $month . '%'])['total'] ?? '0');
layout_start('Expenses', 'expenses');
page_head('Money out', 'Expenses', 'This month: ' . money($monthTotal, $gym));
echo '<section class="split"><article class="card"><h2>Add expense</h2>';
echo form_open(url('expenses'), true);
echo '<input type="hidden" name="action" value="expense"><input type="hidden" name="_back" value="' . e(url('expenses')) . '"><div class="form-grid">';
echo '<label class="field"><span>Date</span><input type="date" name="expense_date" value="' . e($today) . '" required></label><label class="field"><span>Amount</span><input name="amount" required></label>';
echo '<label class="field"><span>Category</span><select name="category_id">';
foreach ($categories as $category) echo '<option value="' . (int) $category['id'] . '">' . e($category['name']) . '</option>';
echo '</select></label><label class="field"><span>Method</span><select name="payment_method">';
foreach (PaymentService::METHODS as $method) echo '<option value="' . $method . '">' . e(status_label($method)) . '</option>';
echo '</select></label><label class="field full"><span>Description</span><input name="description" required></label><label class="field full"><span>Receipt</span><input type="file" name="receipt" accept="image/png,image/jpeg,image/webp,application/pdf"></label></div><button class="btn btn-primary">Save expense</button></form>';
echo '<h2 style="margin-top:22px">New category</h2>';
echo form_open();
echo '<input type="hidden" name="action" value="category"><input type="hidden" name="_back" value="' . e(url('expenses')) . '"><div class="cluster"><input name="name" placeholder="Category" required><button class="btn btn-ghost">Add</button></div></form></article>';
echo '<article class="card table-wrap"><table><thead><tr><th>Date</th><th>Description</th><th>Amount</th><th></th></tr></thead><tbody>';
foreach ($rows as $row) {
    echo '<tr><td>' . e(fmt_date($row['expense_date'])) . '</td><td>' . e($row['description']) . '<small class="muted">' . e($row['category_name']) . '</small></td><td>' . e(money($row['amount'], $gym)) . '</td><td>' . ($row['receipt_path'] ? '<a href="' . e(url('expenses/receipt/' . $row['id'])) . '">Receipt</a>' : '') . '</td></tr>';
}
if (!$rows) echo '<tr><td colspan="4">No expenses yet.</td></tr>';
echo '</tbody></table></article></section>';
layout_end();
