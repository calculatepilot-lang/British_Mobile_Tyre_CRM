INSTALL INSTRUCTIONS — Finance module
======================================

EDIT 1 — add "Finance" to the nav bar
---------------------------------------
Find (or, if you already applied the Settings install guide, the line will
already include Settings — just add Finance to it):

    $navItems = [['/', 'Dashboard'], ['/leads', 'Leads'], ['/leads/new', 'Add lead'], ['/insights', 'Insights'], ['/changes', 'Changes'], ['/settings', 'Settings']];

Replace with:

    $navItems = [['/', 'Dashboard'], ['/leads', 'Leads'], ['/leads/new', 'Add lead'], ['/insights', 'Insights'], ['/changes', 'Changes'], ['/finance', 'Finance'], ['/settings', 'Settings']];


EDIT 2 — add the Finance routes
---------------------------------------
Paste the block below anywhere after:

    $user = $auth->user();
    if ($user === null) redirect('/login');

A good spot is right after the "/insights" route block.

---------------------------------------------------------------------------
if ($path === '/finance/categories' && $method === 'POST') {
    if (!AuthService::verifyCsrf($_POST['csrf'] ?? null)) { http_response_code(419); render('Invalid request', '<h1>Invalid request</h1>', $user); }
    try { (new \BMT\Finance\FinanceService())->createCategory((string)($_POST['name'] ?? '')); }
    catch (\Throwable $e) { redirect('/finance?error=' . rawurlencode($e->getMessage())); }
    redirect('/finance?category_added=1');
}

if ($path === '/finance/expenses/new' && $method === 'POST') {
    if (!AuthService::verifyCsrf($_POST['csrf'] ?? null)) { http_response_code(419); render('Invalid request', '<h1>Invalid request</h1>', $user); }
    try {
        (new \BMT\Finance\FinanceService())->createExpense([
            'category_id' => $_POST['category_id'] ?? '',
            'payee' => $_POST['payee'] ?? null,
            'description' => $_POST['description'] ?? null,
            'amount_gbp' => $_POST['amount_gbp'] ?? 0,
            'expense_date' => $_POST['expense_date'] ?? date('Y-m-d'),
        ], (string) $user['email']);
        redirect('/finance?expense_added=1');
    } catch (\Throwable $e) {
        redirect('/finance?error=' . rawurlencode($e->getMessage()));
    }
}

if ($path === '/finance/income/new' && $method === 'POST') {
    if (!AuthService::verifyCsrf($_POST['csrf'] ?? null)) { http_response_code(419); render('Invalid request', '<h1>Invalid request</h1>', $user); }
    try {
        (new \BMT\Finance\FinanceService())->createIncome([
            'source' => $_POST['source'] ?? 'manual',
            'description' => $_POST['description'] ?? null,
            'amount_gbp' => $_POST['amount_gbp'] ?? 0,
            'received_at' => $_POST['received_at'] ?? date('Y-m-d'),
        ], (string) $user['email']);
        redirect('/finance?income_added=1');
    } catch (\Throwable $e) {
        redirect('/finance?error=' . rawurlencode($e->getMessage()));
    }
}

if ($path === '/finance') {
    $financeService = new \BMT\Finance\FinanceService();
    $summaryService = new \BMT\Finance\FinanceSummaryService(new Database());
    $periods = $summaryService->periods();
    $categories = $financeService->listCategories();

    $flash = '';
    if (isset($_GET['error'])) $flash .= '<p class="notice">' . h($_GET['error']) . '</p>';
    if (isset($_GET['expense_added'])) $flash .= '<p class="notice" style="background:#E8F7EE;color:#0F7A3D;border-color:#BEE8CE">Expense recorded — exchange rate locked at time of entry.</p>';
    if (isset($_GET['income_added'])) $flash .= '<p class="notice" style="background:#E8F7EE;color:#0F7A3D;border-color:#BEE8CE">Income recorded.</p>';
    if (isset($_GET['category_added'])) $flash .= '<p class="notice" style="background:#E8F7EE;color:#0F7A3D;border-color:#BEE8CE">Category added.</p>';

    $periodCards = '';
    foreach (['today' => 'Today', 'month' => 'This month', 'year' => 'This year'] as $key => $label) {
        $p = $periods[$key];
        $periodCards .= '<div class="card"><div>' . h($label) . '</div><div class="metric">£' . h(number_format($p['net_gbp'], 2)) . '</div><div style="color:var(--muted);font-size:12.5px;margin-top:4px">In £' . h(number_format($p['earned_gbp'], 2)) . ' · Out £' . h(number_format($p['expenses_gbp'], 2)) . ' (₨' . h(number_format($p['expenses_pkr'], 0)) . ')</div></div>';
    }

    $categoryOptions = '<option value="">Uncategorised</option>';
    foreach ($categories as $c) $categoryOptions .= '<option value="' . h($c['id']) . '">' . h($c['name']) . '</option>';

    $categoryRows = '';
    foreach ($periods['month']['by_category'] as $row) {
        $categoryRows .= '<tr><td>' . h($row['category']) . '</td><td>' . h($row['count']) . '</td><td>£' . h(number_format((float)$row['gbp'], 2)) . '</td><td>₨' . h(number_format((float)$row['pkr'], 0)) . '</td></tr>';
    }

    $expenseRows = '';
    foreach ($financeService->listExpenses(30) as $e) {
        $expenseRows .= '<tr><td>' . h($e['expense_date']) . '</td><td>' . h($e['category_name'] ?: 'Uncategorised') . '</td><td>' . h($e['payee'] ?: '—') . '</td><td>' . h($e['description'] ?: '—') . '</td><td>£' . h(number_format((float)$e['amount_gbp'], 2)) . '</td><td>₨' . h(number_format((float)$e['amount_pkr'], 0)) . '</td><td style="color:var(--muted);font-size:12.5px">@ ' . h(number_format((float)$e['exchange_rate'], 4)) . '</td></tr>';
    }

    $incomeRows = '';
    foreach ($financeService->listIncome(30) as $i) {
        $incomeRows .= '<tr><td>' . h($i['received_at']) . '</td><td>' . h(ucfirst($i['source'])) . '</td><td>' . h($i['description'] ?: '—') . '</td><td>£' . h(number_format((float)$i['amount_gbp'], 2)) . '</td></tr>';
    }

    $categoryChips = '';
    foreach ($categories as $c) $categoryChips .= '<span class="status" style="background:#EDF0F4;color:#3D4550;margin:0 6px 6px 0">' . h($c['name']) . '</span>';

    $body = '<div class="toolbar"><h1>Finance</h1></div>'
        . $flash
        . '<div class="grid">' . $periodCards . '</div>'

        . '<h2>Record an expense</h2>'
        . '<form class="form form-grid" method="post" action="/finance/expenses/new"><input type="hidden" name="csrf" value="' . h(AuthService::csrfToken()) . '">'
        . '<label>Category<select name="category_id">' . $categoryOptions . '</select></label>'
        . '<label>Payee<input name="payee" placeholder="e.g. Suhaib, Faiz, Google Ads"></label>'
        . '<label>Amount (GBP)<input type="number" step="0.01" name="amount_gbp" required></label>'
        . '<label>Date<input type="date" name="expense_date" value="' . h(date('Y-m-d')) . '" required></label>'
        . '<label style="grid-column:1/-1">Description<input name="description"></label>'
        . '<div class="form-meta">The GBP→PKR rate is fetched and locked automatically at the moment you save this — it will not change later even if the rate does.</div>'
        . '<div class="form-actions"><button>Record expense</button></div></form>'

        . '<h2>Record income</h2>'
        . '<form class="form form-grid" method="post" action="/finance/income/new"><input type="hidden" name="csrf" value="' . h(AuthService::csrfToken()) . '">'
        . '<label>Source<select name="source"><option value="manual">Manual</option><option value="other">Other</option></select></label>'
        . '<label>Amount (GBP)<input type="number" step="0.01" name="amount_gbp" required></label>'
        . '<label>Date received<input type="date" name="received_at" value="' . h(date('Y-m-d')) . '" required></label>'
        . '<label style="grid-column:1/-1">Description<input name="description"></label>'
        . '<div class="form-actions"><button>Record income</button></div></form>'

        . '<h2>Expense categories</h2>'
        . '<p>' . ($categoryChips ?: 'No categories yet.') . '</p>'
        . '<form method="post" action="/finance/categories" style="max-width:420px;display:flex;gap:8px;align-items:flex-end"><input type="hidden" name="csrf" value="' . h(AuthService::csrfToken()) . '"><label style="flex:1;margin:0">New category<input name="name" required style="margin:5px 0 0"></label><button style="margin-bottom:1px">Add</button></form>'

        . '<h2>This month by category</h2>'
        . '<table><thead><tr><th>Category</th><th>Count</th><th>GBP</th><th>PKR</th></tr></thead><tbody>' . ($categoryRows ?: '<tr><td colspan="4">No expenses this month yet.</td></tr>') . '</tbody></table>'

        . '<h2>Recent expenses</h2>'
        . '<table><thead><tr><th>Date</th><th>Category</th><th>Payee</th><th>Description</th><th>GBP</th><th>PKR</th><th>Rate</th></tr></thead><tbody>' . ($expenseRows ?: '<tr><td colspan="7">No expenses recorded yet.</td></tr>') . '</tbody></table>'

        . '<h2>Recent income</h2>'
        . '<table><thead><tr><th>Date</th><th>Source</th><th>Description</th><th>GBP</th></tr></thead><tbody>' . ($incomeRows ?: '<tr><td colspan="4">No manual income recorded yet.</td></tr>') . '</tbody></table>';

    render('Finance', $body, $user);
}
---------------------------------------------------------------------------


DEPLOYMENT ORDER
---------------------------------------------------------------------------
1. Run database/finance_v2_migration.sql against the CRM's database.
2. Upload app/Finance/ExchangeRateService.php (new file).
3. Upload app/Finance/FinanceService.php (new file).
4. Replace app/Finance/FinanceSummaryService.php with the updated version
   (adds PKR totals and by-category breakdown, keeps the same public method
   names so nothing else that calls it breaks).
5. Apply Edits 1 and 2 to public/index.php as described above.

Once done, "Finance" appears in the nav with:
  - Today / This month / This year net cards (GBP in/out, PKR out)
  - Expense entry (category, payee, amount, date — rate locked automatically)
  - Income entry (manual, separate from lead-derived earnings)
  - Category management (add new ones any time — Suhaib, Faiz, and Google
    Ads Spend are pre-seeded, but nothing is hardcoded — add as many as you
    want)
  - This month by category, recent expenses (with locked rate shown per
    row), recent income

NOTES / THINGS TO DECIDE LATER
---------------------------------------------------------------------------
- Exchange rate source is open.er-api.com (free, no key, updates daily). If
  you want intraday rates or a provider with an SLA, swap the URL in
  ExchangeRateService::fetchLiveRate() — everything else (locking, caching,
  audit log) stays the same regardless of provider.
- "Payee" is a free-text field on expenses (not a separate people table) —
  simplest option for now. If you want per-person running totals (e.g. "how
  much has Faiz been paid this year") that's a quick follow-up query against
  the existing payee column, or a proper `payees` table if you want it
  structured later.
- Report generation for "accounts review and reconciliation" — the by-
  category breakdown above covers the basics. If you want an exportable
  CSV/PDF statement for a given date range, that's a natural next add-on
  once this base is live and you've used it for a few weeks to see what a
  real reconciliation session actually needs from it.
