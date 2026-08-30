<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use BMT\Approvals\ApprovalService;
use BMT\Auth\AuthService;
use BMT\Database;
use BMT\Leads\LeadRepository;
use BMT\Leads\LeadService;
use BMT\Optimisation\LeadQualityReport;
use BMT\Optimisation\OptimiserService;
use BMT\Finance\FinanceService;
use BMT\Finance\FinanceSummaryService;
use BMT\Finance\ImportService;

$root = dirname(__DIR__);
if (is_file($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}
function envValue(string $key, string $default = ''): string {
    if (isset($_ENV[$key])) return (string) $_ENV[$key];
    if (isset($_SERVER[$key])) return (string) $_SERVER[$key];
    $value = getenv($key);
    return $value !== false ? (string) $value : $default;
}
date_default_timezone_set(envValue('APP_TIMEZONE', 'Europe/London'));
AuthService::boot();
$auth = new AuthService();
$leads = new LeadRepository();
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function h(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function redirect(string $to): never { header('Location: ' . $to, true, 302); exit; }

/** Customer name if we have one, otherwise a stable "Customer #<id>" using the lead's own numeric id. */
function leadDisplayName(array $lead): string {
    $name = trim((string) ($lead['customer_name'] ?? ''));
    return $name !== '' ? $name : 'Customer #' . (int) ($lead['id'] ?? 0);
}

/** Strips a phone number down to a wa.me-compatible digit string, assuming UK numbers (leading 0 -> 44). */
function phoneToWhatsAppDigits(string $phone): string {
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if ($digits === '') return '';
    if (str_starts_with($digits, '0')) return '44' . substr($digits, 1);
    if (str_starts_with($digits, '44')) return $digits;
    if (str_starts_with($digits, '440')) return '44' . substr($digits, 3);
    return $digits;
}

/** Small Call + WhatsApp action buttons next to a phone number, or an em dash if there's no phone at all. */
function phoneActions(?string $phone): string {
    $phone = trim((string) $phone);
    if ($phone === '') return '—';
    $wa = phoneToWhatsAppDigits($phone);
    $out = '<span style="white-space:nowrap">' . h($phone) . '</span> ';
    $out .= '<a href="tel:' . h($phone) . '" title="Call" style="display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:999px;background:#EAF1FF;color:#0B4FCF;text-decoration:none;font-size:13px;margin-left:4px">☎</a>';
    if ($wa !== '') {
        $out .= '<a href="https://wa.me/' . h($wa) . '" target="_blank" rel="noopener" title="WhatsApp" style="display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:999px;background:#E8F7EE;color:#0F7A3D;text-decoration:none;font-size:13px;margin-left:4px">💬</a>';
    }
    return $out;
}

function statusBadge(string $status): string {
    $label = h(ucwords(str_replace('_', ' ', $status)));
    $palette = [
        'new' => ['#EAF1FF', '#0B4FCF'], 'contacted' => ['#EAF1FF', '#0B4FCF'],
        'qualified' => ['#FFF4E0', '#966400'], 'quoted' => ['#FFF4E0', '#966400'],
        'booked' => ['#E8F7EE', '#0F7A3D'], 'completed' => ['#E8F7EE', '#0F7A3D'],
        'lost' => ['#FBEAEA', '#B42318'], 'spam' => ['#FBEAEA', '#B42318'], 'rejected' => ['#FBEAEA', '#B42318'],
        'duplicate' => ['#F1F0F5', '#5B5876'], 'existing_customer' => ['#F1F0F5', '#5B5876'],
        'pending_approval' => ['#FFF4E0', '#966400'], 'planned' => ['#EAF1FF', '#0B4FCF'], 'approved' => ['#E8F7EE', '#0F7A3D'],
        'executed' => ['#E8F7EE', '#0F7A3D'],
        'low' => ['#E8F7EE', '#0F7A3D'], 'medium' => ['#FFF4E0', '#966400'], 'high' => ['#FBEAEA', '#B42318'], 'critical' => ['#FBEAEA', '#B42318'],
    ];
    [$bg, $fg] = $palette[$status] ?? ['#EDF0F4', '#3D4550'];
    return '<span class="status" style="background:' . $bg . ';color:' . $fg . '">' . $label . '</span>';
}
function render(string $title, string $body, ?array $user): never {
    $currentPath = $GLOBALS['path'] ?? '';
    $navItems = [['/', 'Dashboard'], ['/leads', 'Leads'], ['/leads/new', 'Add lead'], ['/insights', 'Insights'], ['/finance', 'Finance'], ['/changes', 'Changes']];
    $navLinks = '';
    foreach ($navItems as [$href, $label]) {
        $isActive = $href === '/' ? $currentPath === '/' : str_starts_with($currentPath, $href);
        $navLinks .= '<a href="' . h($href) . '"' . ($isActive ? ' class="active"' : '') . '>' . h($label) . '</a>';
    }
    $nav = $user ? '<aside><div class="brand"><img src="/assets/logo.png" alt="British Mobile Tyres"><span>BMT CRM</span></div><nav>' . $navLinks . '</nav><a class="logout" href="/logout">Logout</a></aside>' : '';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . h($title) . ' | BMT CRM</title><style>
:root{--ink:#012169;--ink-soft:#0B2A6B;--brand:#C8102E;--brand-hover:#A50D25;--bg:#F4F6FA;--surface:#fff;--border:#E3E7EE;--text:#1A2130;--muted:#667085;--radius:12px;--shadow:0 1px 3px rgba(16,24,40,.06),0 1px 2px rgba(16,24,40,.04)}
*{box-sizing:border-box}
body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;background:var(--bg);color:var(--text);font-size:14.5px;-webkit-font-smoothing:antialiased}
.layout{display:flex;min-height:100vh}
aside{width:232px;flex:0 0 232px;background:var(--ink);color:#fff;padding:22px 16px;display:flex;flex-direction:column;gap:20px}
.brand{display:flex;align-items:center;gap:10px;font-weight:800;font-size:17px;letter-spacing:-.01em;padding:0 8px}
.brand img{width:28px;height:28px;object-fit:contain;flex:0 0 auto;border-radius:4px}
aside nav{display:flex;flex-direction:column;gap:2px}
aside nav a{display:block;color:#C7D3EA;text-decoration:none;padding:10px 10px;border-radius:8px;font-size:14px;font-weight:600;transition:background-color .12s,color .12s}
aside nav a:hover{background:rgba(255,255,255,.08);color:#fff}
aside nav a.active{background:rgba(255,255,255,.14);color:#fff}
aside .logout{margin-top:auto;color:#8A9BC0;text-decoration:none;font-size:13px;padding:8px 10px}
aside .logout:hover{color:#fff}
.content{flex:1;padding:32px 36px;max-width:1180px;width:100%}
.auth-shell{min-height:100vh;width:100%;display:flex;align-items:center;justify-content:center;background:linear-gradient(160deg,var(--ink) 0%,#04143A 100%)}
.auth-card{width:100%;max-width:380px;background:#fff;border-radius:16px;padding:36px 34px;box-shadow:0 20px 50px rgba(1,33,105,.35)}
.auth-card .brand{color:var(--ink);margin-bottom:22px;font-size:19px;justify-content:center}
.auth-card .brand img{width:88px;height:88px}
.auth-card .brand span{display:none}
.auth-card h1{font-size:17px;margin-bottom:2px}
.auth-card .form{box-shadow:none;border:0;padding:0;max-width:none}
.auth-card button{width:100%;justify-content:center;padding:11px}
h1{margin:0 0 4px;font-size:22px;font-weight:800;letter-spacing:-.015em;color:var(--ink)}
h2{font-size:16px;font-weight:750;color:var(--ink);margin:28px 0 12px}
h3{font-size:14px;font-weight:700;color:var(--ink)}
p{color:var(--muted);line-height:1.55}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px;margin:18px 0 8px}
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:18px 20px;box-shadow:var(--shadow)}
.card>div:first-child{color:var(--muted);font-size:12.5px;font-weight:650;text-transform:uppercase;letter-spacing:.03em;margin-bottom:6px}
.card:has(a){transition:box-shadow .12s,transform .08s;cursor:pointer}
.card:has(a):hover{box-shadow:0 4px 14px rgba(16,24,40,.1);transform:translateY(-1px)}
.metric{font-size:30px;font-weight:800;color:var(--ink);letter-spacing:-.02em}
.toolbar{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin:0 0 18px;flex-wrap:wrap}
.toolbar p{margin:6px 0 0}
.button,button{display:inline-flex;align-items:center;gap:6px;background:var(--ink);color:#fff;border:0;border-radius:9px;padding:10px 16px;font-size:13.5px;font-weight:650;text-decoration:none;cursor:pointer;transition:background-color .12s,transform .08s;white-space:nowrap}
.button:hover,button:hover{background:var(--ink-soft)}
.button:active,button:active{transform:translateY(1px)}
button[style*="b42318"],button[style*="B42318"]{background:var(--brand)!important}
button[style*="b42318"]:hover,button[style*="B42318"]:hover{background:var(--brand-hover)!important}
table{width:100%;border-collapse:collapse;background:var(--surface);border-radius:var(--radius);overflow:hidden;box-shadow:var(--shadow)}
th{text-align:left;padding:12px 14px;font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);background:#FAFBFD;border-bottom:1px solid var(--border)}
td{text-align:left;padding:13px 14px;border-bottom:1px solid var(--border);font-size:14px}
tr:last-child td{border-bottom:0}
tbody tr:hover td{background:#FAFBFD}
table a{color:var(--ink);font-weight:650;text-decoration:none}
table a:hover{text-decoration:underline}
input,select,textarea{width:100%;box-sizing:border-box;padding:10px 12px;border:1.5px solid var(--border);border-radius:9px;font:inherit;font-size:14px;margin:5px 0 14px;background:#fff;color:var(--text)}
input:focus,select:focus,textarea:focus{outline:none;border-color:var(--ink);box-shadow:0 0 0 3px rgba(1,33,105,.08)}
label{font-size:13px;font-weight:650;color:var(--text)}
.form{max-width:820px;background:var(--surface);padding:26px 28px;border-radius:var(--radius);box-shadow:var(--shadow);border:1px solid var(--border)}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:0 20px}
.form-grid label{min-width:0}
.form-section{grid-column:1/-1;font-size:12.5px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);margin:18px 0 2px;padding-top:14px;border-top:1px solid var(--border)}
.form-section:first-child{margin-top:0;padding-top:0;border-top:0}
.form-actions{grid-column:1/-1;margin-top:6px}
.form-meta{grid-column:1/-1;background:#FAFBFD;border:1px solid var(--border);border-radius:9px;padding:12px 14px;font-size:13px;color:var(--muted);margin:4px 0 16px}
@media(max-width:640px){.form-grid{grid-template-columns:1fr}}
.notice{padding:12px 14px;border-radius:9px;background:#FFF4E0;color:#7A5200;border:1px solid #F5DEA6;margin-bottom:18px;font-size:13.5px;font-weight:600}
.status{display:inline-flex;padding:3px 10px;border-radius:999px;font-size:11.5px;font-weight:700;letter-spacing:.01em}
.finance-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin:18px 0 8px}
.finance-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:18px 20px;box-shadow:var(--shadow);border-left:4px solid var(--border)}
.finance-card.income{border-left-color:#0F7A3D}
.finance-card.expense{border-left-color:var(--brand)}
.finance-card.net{border-left-color:var(--ink)}
.finance-card>div:first-child{color:var(--muted);font-size:12.5px;font-weight:650;text-transform:uppercase;letter-spacing:.03em;margin-bottom:6px}
.finance-card .metric{font-size:26px}
.finance-card .metric.positive{color:#0F7A3D}
.finance-card .metric.negative{color:var(--brand)}
.finance-card .sub{font-size:12px;color:var(--muted);margin-top:4px}
.finance-cols{display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start;margin:18px 0}
@media(max-width:900px){.finance-cols{grid-template-columns:1fr}}
.chip-row{display:flex;flex-wrap:wrap;gap:8px;margin:14px 0 24px}
.chip{display:inline-flex;align-items:center;gap:8px;background:var(--surface);border:1px solid var(--border);border-radius:999px;padding:6px 8px 6px 14px;font-size:13px;font-weight:600;box-shadow:var(--shadow)}
.chip.default{background:#EAF1FF;border-color:#C9DBFF;color:var(--ink)}
.chip form{margin:0}
.chip button{padding:4px 10px;font-size:11px;border-radius:999px;background:transparent!important;color:var(--muted)!important;box-shadow:none}
.chip button:hover{background:#FBEAEA!important;color:var(--brand)!important}
.amount-gbp{font-weight:700;color:var(--text)}
.amount-pkr{font-size:12px;color:var(--muted)}
@media(max-width:760px){aside{width:auto;flex:none;padding:14px}.layout{display:block}.content{padding:18px}aside nav{flex-direction:row;flex-wrap:wrap}}
</style></head><body>' . ($user
        ? '<div class="layout">' . $nav . '<main class="content">' . $body . '</main></div>'
        : '<div class="auth-shell"><div class="auth-card">' . $body . '</div></div>'
    ) . '</body></html>'; exit;
}

if ($path === '/setup' && $method === 'POST') {
    $count = (int) Database::connection()->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $setupToken = envValue('INITIAL_SETUP_TOKEN');
    if ($count > 0 || $setupToken === '' || !hash_equals($setupToken, (string)($_POST['setup_token'] ?? ''))) { http_response_code(403); render('Setup unavailable', '<h1>Setup unavailable</h1>', null); }
    if (!AuthService::verifyCsrf($_POST['csrf'] ?? null)) { http_response_code(419); render('Invalid request', '<h1>Invalid request</h1>', null); }
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $name = trim((string)($_POST['name'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '' || strlen($password) < 12) { render('Setup error', '<h1>Setup error</h1><p>Use a valid email, name and password of at least 12 characters.</p>', null); }
    $stmt = Database::connection()->prepare('INSERT INTO users (name,email,password_hash,role) VALUES (:name,:email,:password_hash,\'admin\')');
    $stmt->execute(['name'=>$name,'email'=>$email,'password_hash'=>password_hash($password,PASSWORD_DEFAULT)]);
    redirect('/login');
}

if ($path === '/setup') {
    $count = (int) Database::connection()->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($count > 0) redirect('/login');
    $form = '<h1>Initial administrator setup</h1><p>Available only while no CRM users exist. Requires INITIAL_SETUP_TOKEN from the server environment.</p><form class="form" method="post"><input type="hidden" name="csrf" value="'.h(AuthService::csrfToken()).'"><label>Setup token<input name="setup_token" required></label><label>Name<input name="name" required></label><label>Email<input type="email" name="email" required></label><label>Password<input type="password" name="password" minlength="12" required></label><button>Create administrator</button></form>';
    render('Initial setup', $form, null);
}

if ($path === '/login' && $method === 'POST') {
    if (!AuthService::verifyCsrf($_POST['csrf'] ?? null)) { http_response_code(419); render('Invalid request', '<h1>Invalid request</h1>', null); }
    if (!$auth->attempt((string)($_POST['email'] ?? ''), (string)($_POST['password'] ?? ''))) render('Login', '<div class="brand"><img src="/assets/logo.png" alt="British Mobile Tyres"><span>BMT CRM</span></div><h1>Sign in</h1><p class="notice">Invalid email or password.</p><form class="form" method="post"><input type="hidden" name="csrf" value="'.h(AuthService::csrfToken()).'"><label>Email<input type="email" name="email" required autofocus></label><label>Password<input type="password" name="password" required></label><button>Sign in</button></form>', null);
    redirect('/');
}
if ($path === '/login') render('Login', '<div class="brand"><img src="/assets/logo.png" alt="British Mobile Tyres"><span>BMT CRM</span></div><h1>Sign in</h1><p style="margin-bottom:22px">Private administration area.</p><form class="form" method="post"><input type="hidden" name="csrf" value="'.h(AuthService::csrfToken()).'"><label>Email<input type="email" name="email" required autofocus></label><label>Password<input type="password" name="password" required></label><button>Sign in</button></form>', null);
if ($path === '/logout') { $auth->logout(); redirect('/login'); }

$user = $auth->user();
if ($user === null) redirect('/login');

if ($path === '/leads/new' && $method === 'POST') {
    if (!AuthService::verifyCsrf($_POST['csrf'] ?? null)) { http_response_code(419); render('Invalid request', '<h1>Invalid request</h1>', $user); }
    $publicId = (new LeadService())->create([
        'lead_type' => $_POST['lead_type'] ?? '', 'source' => $_POST['source'] ?? 'manual', 'customer_name' => $_POST['customer_name'] ?? null,
        'customer_phone' => $_POST['customer_phone'] ?? null, 'customer_email' => $_POST['customer_email'] ?? null,
        'service_requested' => $_POST['service_requested'] ?? null, 'tyre_size' => $_POST['tyre_size'] ?? null, 'vehicle_registration' => $_POST['vehicle_registration'] ?? null,
        'city' => $_POST['city'] ?? null, 'postcode' => $_POST['postcode'] ?? null,
    ], ['gclid'=>$_POST['gclid'] ?? null, 'gbraid'=>$_POST['gbraid'] ?? null, 'wbraid'=>$_POST['wbraid'] ?? null, 'utm_source'=>$_POST['utm_source'] ?? null, 'utm_medium'=>$_POST['utm_medium'] ?? null, 'utm_campaign'=>$_POST['utm_campaign'] ?? null]);
    redirect('/leads/' . rawurlencode($publicId));
}
if ($path === '/leads/new') {
    $types=''; foreach(['phone'=>'Phone','whatsapp'=>'WhatsApp','form'=>'Form','purchase'=>'Purchase','other'=>'Other'] as $v=>$label) $types.='<option value="'.h($v).'">'.h($label).'</option>';
    $body='<h1>Add lead</h1><form class="form form-grid" method="post"><input type="hidden" name="csrf" value="'.h(AuthService::csrfToken()).'"><div class="form-section">Lead details</div><label>Lead type<select name="lead_type" required>'.$types.'</select></label><label>Source<input name="source" value="manual"></label><label>Customer name<input name="customer_name"></label><label>Phone<input name="customer_phone"></label><label>Email<input type="email" name="customer_email"></label><label>Service requested<input name="service_requested"></label><label>Tyre size<input name="tyre_size" placeholder="e.g. 205/55 R16"></label><label>Vehicle registration<input name="vehicle_registration" placeholder="e.g. AB12 CDE"></label><label>City<input name="city"></label><label>Postcode<input name="postcode"></label><div class="form-section">Google Ads attribution (optional)</div><label>GCLID<input name="gclid"></label><label>GBRAID<input name="gbraid"></label><label>WBRAID<input name="wbraid"></label><label>UTM source<input name="utm_source"></label><label>UTM medium<input name="utm_medium"></label><label>UTM campaign<input name="utm_campaign"></label><div class="form-actions"><button>Create lead</button></div></form>';
    render('Add lead', $body, $user);
}

if (preg_match('#^/leads/([^/]+)/update$#', $path, $m) && $method === 'POST') {
    if (!AuthService::verifyCsrf($_POST['csrf'] ?? null)) { http_response_code(419); render('Invalid request', '<h1>Invalid request</h1>', $user); }
    $leads->updateOutcome(rawurldecode($m[1]), $_POST, (int)$user['id']);
    redirect('/leads/' . rawurlencode(rawurldecode($m[1])));
}
if (preg_match('#^/leads/([^/]+)$#', $path, $m)) {
    $lead=$leads->find(rawurldecode($m[1])); if(!$lead){http_response_code(404);render('Not found','<h1>Lead not found</h1>',$user);}
    $statuses=''; foreach(['new','contacted','qualified','quoted','booked','completed','lost','spam','duplicate','existing_customer'] as $s) $statuses.='<option value="'.h($s).'"'.($lead['status']===$s?' selected':'').'>'.h(ucwords(str_replace('_',' ',$s))).'</option>';
    $fields=['customer_name'=>'Customer name','customer_phone'=>'Phone','customer_email'=>'Email','service_requested'=>'Service requested','tyre_size'=>'Tyre size','vehicle_registration'=>'Vehicle registration','city'=>'City','postcode'=>'Postcode','source_page_label'=>'Source page','quoted_amount'=>'Quoted amount','final_revenue'=>'Final revenue','quality_score'=>'Quality score 0-100','outcome_reason'=>'Outcome reason'];
    $inputs=''; foreach($fields as $key=>$label){$type=in_array($key,['quoted_amount','final_revenue','quality_score'],true)?'number':'text';$step=in_array($key,['quoted_amount','final_revenue'],true)?' step="0.01"':'';$min=$key==='quality_score'?' min="0" max="100"':'';$inputs.='<label>'.h($label).'<input type="'.$type.'"'.$step.$min.' name="'.h($key).'" value="'.h($lead[$key]??'').'"></label>';}
    if (!empty($lead['source_page_url'])) {
        $inputs .= '<div class="form-meta" style="grid-column:1/-1">Full source URL: <a href="'.h($lead['source_page_url']).'" target="_blank" rel="noopener">'.h($lead['source_page_url']).'</a></div>';
    }
    $inputs .= '<label style="grid-column:1/-1">Remarks (internal notes)<textarea name="remarks" rows="4" placeholder="Manual notes for this lead — anything worth flagging for whoever follows up next.">'.h($lead['remarks']??'').'</textarea></label>';
    $body='<div class="toolbar"><div><h1>'.h(leadDisplayName($lead)).'</h1><p>'.statusBadge($lead['status']).' &nbsp;'.h($lead['lead_type']).' · '.h($lead['source']).' &nbsp;'.phoneActions($lead['customer_phone']).'</p></div><a class="button" href="/leads">Back to leads</a></div><form class="form form-grid" method="post" action="/leads/'.rawurlencode($lead['public_id']).'/update"><input type="hidden" name="csrf" value="'.h(AuthService::csrfToken()).'"><div class="form-meta">Campaign: '.h($lead['campaign_name']??'—').' · Ad group: '.h($lead['ad_group_name']??'—').'<br>Keyword: '.h($lead['keyword_text']??'—').' · GCLID: '.h($lead['gclid']??'—').'</div><div class="form-section">Lead status</div><label>Status<select name="status">'.$statuses.'</select></label><div class="form-section">Details</div>'.$inputs.'<div class="form-actions"><button>Save changes</button></div></form>';
    render('Lead ' . $lead['public_id'], $body, $user);
}
if ($path === '/leads') {
    $rows=''; foreach($leads->list(100) as $lead){$rows.='<tr><td><a href="/leads/'.rawurlencode($lead['public_id']).'">'.h(leadDisplayName($lead)).'</a></td><td>'.statusBadge($lead['status']).'</td><td>'.h($lead['lead_type']).'</td><td>'.phoneActions($lead['customer_phone']).'</td><td>'.h($lead['tyre_size']?:'—').'</td><td>'.h($lead['source_page_label']?:'—').'</td><td>'.h($lead['city']?:'—').'</td><td>'.h($lead['campaign_name']?:'—').'</td><td>'.h($lead['created_at']).'</td></tr>';}
    render('Leads','<div class="toolbar"><h1>Leads</h1><a class="button" href="/leads/new">Add lead</a></div><table><thead><tr><th>Customer</th><th>Status</th><th>Type</th><th>Contact</th><th>Tyre size</th><th>Source page</th><th>City</th><th>Campaign</th><th>Created</th></tr></thead><tbody>'.($rows?:'<tr><td colspan="9">No leads yet.</td></tr>').'</tbody></table>',$user);
}
if (preg_match('#^/changes/([0-9a-f-]{36})/approve$#', $path, $m) && $method === 'POST') {
    if (!AuthService::verifyCsrf($_POST['csrf'] ?? null)) { http_response_code(419); render('Invalid request', '<h1>Invalid request</h1>', $user); }
    try { (new ApprovalService())->approve($m[1], (string) $user['email']); }
    catch (\Throwable $e) { render('Changes', '<h1>Automation changes</h1><p class="notice">'.h($e->getMessage()).'</p><p><a href="/changes">Back to changes</a></p>', $user); }
    redirect('/changes');
}
if (preg_match('#^/changes/([0-9a-f-]{36})/reject$#', $path, $m) && $method === 'POST') {
    if (!AuthService::verifyCsrf($_POST['csrf'] ?? null)) { http_response_code(419); render('Invalid request', '<h1>Invalid request</h1>', $user); }
    try { (new ApprovalService())->reject($m[1]); }
    catch (\Throwable $e) { render('Changes', '<h1>Automation changes</h1><p class="notice">'.h($e->getMessage()).'</p><p><a href="/changes">Back to changes</a></p>', $user); }
    redirect('/changes');
}
if ($path === '/changes/run-approved' && $method === 'POST') {
    if (!AuthService::verifyCsrf($_POST['csrf'] ?? null)) { http_response_code(419); render('Invalid request', '<h1>Invalid request</h1>', $user); }
    try {
        $result = (new \BMT\Execution\ChangeExecutor())->runPending();
        redirect('/changes?executed=' . count($result['executed']) . '&failed=' . count($result['failed']));
    } catch (\Throwable $e) {
        render('Changes', '<h1>Automation changes</h1><p class="notice">Run failed before any change could be processed: '.h($e->getMessage()).'</p><p><a href="/changes">Back to changes</a></p>', $user);
    }
}
if ($path === '/finance/expense/new' && $method === 'POST') {
    if (!AuthService::verifyCsrf($_POST['csrf'] ?? null)) { http_response_code(419); render('Invalid request', '<h1>Invalid request</h1>', $user); }
    try {
        (new FinanceService())->createExpense($_POST, (string) $user['email']);
    } catch (\Throwable $e) {
        render('Finance', '<h1>Finance</h1><p class="notice">'.h($e->getMessage()).'</p><p><a href="/finance">Back to Finance</a></p>', $user);
    }
    redirect('/finance');
}
if ($path === '/finance/income/new' && $method === 'POST') {
    if (!AuthService::verifyCsrf($_POST['csrf'] ?? null)) { http_response_code(419); render('Invalid request', '<h1>Invalid request</h1>', $user); }
    try {
        (new FinanceService())->createIncome($_POST, (string) $user['email']);
    } catch (\Throwable $e) {
        render('Finance', '<h1>Finance</h1><p class="notice">'.h($e->getMessage()).'</p><p><a href="/finance">Back to Finance</a></p>', $user);
    }
    redirect('/finance');
}
if ($path === '/finance/category/new' && $method === 'POST') {
    if (!AuthService::verifyCsrf($_POST['csrf'] ?? null)) { http_response_code(419); render('Invalid request', '<h1>Invalid request</h1>', $user); }
    try {
        (new FinanceService())->createCategory((string) ($_POST['name'] ?? ''));
    } catch (\Throwable $e) {
        render('Finance', '<h1>Finance</h1><p class="notice">'.h($e->getMessage()).'</p><p><a href="/finance">Back to Finance</a></p>', $user);
    }
    redirect('/finance');
}
if (preg_match('#^/finance/category/(\d+)/archive$#', $path, $m) && $method === 'POST') {
    if (!AuthService::verifyCsrf($_POST['csrf'] ?? null)) { http_response_code(419); render('Invalid request', '<h1>Invalid request</h1>', $user); }
    (new FinanceService())->archiveCategory((int) $m[1]);
    redirect('/finance');
}
if ($path === '/finance/report') {
    $summaryError = null;
    $lifetime = null;
    $trend = [];
    try {
        $svc = new FinanceSummaryService(new Database());
        $lifetime = $svc->lifetime();
        $trend = $svc->monthlyTrend(12);
    } catch (\Throwable $e) {
        $summaryError = $e->getMessage();
    }

    $notice = $summaryError ? '<p class="notice">Finance tables aren\'t set up yet — run <code>database/finance_v2_migration.sql</code>, then reload this page. ('.h($summaryError).')</p>' : '';

    $cardsHtml = '';
    if ($lifetime !== null) {
        $netClass = $lifetime['net_gbp'] >= 0 ? 'positive' : 'negative';
        $sinceLabel = $lifetime['since'] === date('Y-m-d') ? 'no transactions recorded yet' : 'since '.h(date('d M Y', strtotime($lifetime['since'])));
        $cardsHtml = '<div class="finance-grid">'
            .'<div class="finance-card income"><div>Total earned</div><div class="metric positive">£'.h(number_format($lifetime['earned_gbp'],2)).'</div><div class="sub">£'.h(number_format($lifetime['earned_from_leads_gbp'],2)).' from leads · £'.h(number_format($lifetime['earned_manual_gbp'],2)).' manual</div></div>'
            .'<div class="finance-card expense"><div>Total spent</div><div class="metric negative">£'.h(number_format($lifetime['expenses_gbp'],2)).'</div><div class="sub">₨'.h(number_format($lifetime['expenses_pkr'],2)).' at locked rates</div></div>'
            .'<div class="finance-card net"><div>Lifetime profit</div><div class="metric '.$netClass.'">£'.h(number_format($lifetime['net_gbp'],2)).'</div><div class="sub">'.$sinceLabel.'</div></div>'
            .'</div>';
    }

    $byCategoryTable = '<p style="color:var(--muted)">No expenses recorded yet.</p>';
    if ($lifetime !== null && !empty($lifetime['by_category'])) {
        $totalGbp = array_sum(array_column($lifetime['by_category'], 'gbp')) ?: 1;
        $rows = '';
        foreach ($lifetime['by_category'] as $row) {
            $pct = round(((float)$row['gbp'] / $totalGbp) * 100, 1);
            $rows .= '<tr><td>'.h($row['category']).'</td><td class="amount-gbp">£'.h(number_format((float)$row['gbp'],2)).'</td><td class="amount-pkr">₨'.h(number_format((float)$row['pkr'],2)).'</td><td>'.h($pct).'%</td><td>'.h($row['count']).'</td></tr>';
        }
        $byCategoryTable = '<table><thead><tr><th>Category</th><th>GBP</th><th>PKR</th><th>Share</th><th>Count</th></tr></thead><tbody>'.$rows.'</tbody></table>';
    }

    $trendRows = '';
    foreach ($trend as $t) {
        $rowNetClass = $t['net_gbp'] >= 0 ? 'positive' : 'negative';
        $trendRows .= '<tr><td>'.h($t['label']).'</td><td class="amount-gbp" style="color:#0F7A3D">£'.h(number_format($t['earned_gbp'],2)).'</td><td class="amount-gbp" style="color:var(--brand)">£'.h(number_format($t['expenses_gbp'],2)).'</td><td class="amount-gbp"><span class="metric '.$rowNetClass.'" style="font-size:14px">£'.h(number_format($t['net_gbp'],2)).'</span></td></tr>';
    }
    $trendTable = '<table><thead><tr><th>Month</th><th>Earned</th><th>Spent</th><th>Net</th></tr></thead><tbody>'.($trendRows?:'<tr><td colspan="4">No data yet.</td></tr>').'</tbody></table>';

    $body = '<div class="toolbar"><div><h1>Overall report</h1><p>Lifetime earnings, spending, and profit across the whole business — every converted lead, manual income entry, and recorded expense to date.</p></div><a class="button" href="/finance">Back to Finance</a></div>'
        .$notice.$cardsHtml
        .'<h2>Lifetime spending by category</h2>'.$byCategoryTable
        .'<h2>Last 12 months</h2>'.$trendTable;

    render('Overall report', $body, $user);
}
if ($path === '/finance/import' && $method === 'POST') {
    if (!AuthService::verifyCsrf($_POST['csrf'] ?? null)) { http_response_code(419); render('Invalid request', '<h1>Invalid request</h1>', $user); }
    $file = $_FILES['import_file'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK || $file['size'] === 0) {
        render('Finance', '<h1>Finance</h1><p class="notice">No file was uploaded, or the upload failed. Try again with a .csv file.</p><p><a href="/finance/import">Back to import</a></p>', $user);
    }
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'csv') {
        render('Finance', '<h1>Finance</h1><p class="notice">Only .csv files are supported right now — got .'.h($ext).'. Export your spreadsheet/statement as CSV and try again.</p><p><a href="/finance/import">Back to import</a></p>', $user);
    }
    try {
        $result = (new ImportService())->importCsv((string)$file['tmp_name'], (string)$file['name'], (string)$user['email']);
        redirect('/finance/import?imported='.$result['rows_imported'].'&rejected='.$result['rows_rejected'].'&total='.$result['rows_total']);
    } catch (\Throwable $e) {
        render('Finance', '<h1>Finance</h1><p class="notice">Import failed: '.h($e->getMessage()).'</p><p><a href="/finance/import">Back to import</a></p>', $user);
    }
}
if ($path === '/finance/import') {
    $imports = (new ImportService())->listImports(20);
    $rows = '';
    foreach ($imports as $i) {
        $statusClass = match($i['status']) { 'completed' => 'low', 'needs_review' => 'medium', 'failed' => 'high', default => 'medium' };
        $rows .= '<tr><td>'.h($i['created_at']).'</td><td>'.h($i['original_filename']).'</td><td>'.statusBadge($i['status']).'</td><td>'.h($i['rows_imported']).' / '.h($i['rows_total']).'</td><td>'.h($i['rows_rejected']).'</td></tr>';
    }
    $historyTable = '<table><thead><tr><th>Date</th><th>File</th><th>Status</th><th>Imported</th><th>Rejected</th></tr></thead><tbody>'.($rows?:'<tr><td colspan="5">No imports yet.</td></tr>').'</tbody></table>';

    $flash = '';
    if (isset($_GET['total'])) {
        $imported = (int)($_GET['imported']??0); $rejected = (int)($_GET['rejected']??0); $total = (int)($_GET['total']??0);
        $flash = $rejected > 0
            ? '<p class="notice">Imported '.$imported.' of '.$total.' rows — '.$rejected.' rejected. Check the row below for the error summary, or re-export and try the rejected rows again.</p>'
            : '<p class="notice" style="background:#E8F7EE;color:#0F7A3D;border-color:#B9E6C9">Imported all '.$total.' row(s) successfully.</p>';
    }

    $body = '<div class="toolbar"><div><h1>Import expenses</h1><p>Upload a CSV of expenses — a bank/card statement export works well. Needs a date column and an amount column; category, payee, and description are optional and auto-detected by header name.</p></div><a class="button" href="/finance">Back to Finance</a></div>'
        .$flash
        .'<form class="form" method="post" action="/finance/import" enctype="multipart/form-data"><input type="hidden" name="csrf" value="'.h(AuthService::csrfToken()).'"><label>CSV file<input type="file" name="import_file" accept=".csv" required></label><div class="form-meta">Recognised headers (case-insensitive): <strong>date</strong> or expense_date · <strong>amount</strong> · category · payee/supplier/merchant · description/note/memo. Amounts should be in GBP; each imported row locks the GBP→PKR rate at import time, same as a manually entered expense.</div><div class="form-actions"><button>Upload and import</button></div></form>'
        .'<h2>Import history</h2>'.$historyTable;

    render('Import expenses', $body, $user);
}
if ($path === '/finance') {
    $finance = new FinanceService();
    $categories = $finance->listCategories();
    $categoryOptions = '<option value="">Uncategorised</option>';
    foreach ($categories as $c) { $categoryOptions .= '<option value="'.h($c['id']).'">'.h($c['name']).'</option>'; }

    $summaryError = null;
    $periods = ['today' => null, 'month' => null, 'year' => null];
    try {
        $periods = (new FinanceSummaryService(new Database()))->periods();
    } catch (\Throwable $e) {
        $summaryError = $e->getMessage();
    }

    $month = $periods['month'] ?? null;

    if ($month !== null) {
        $netClass = $month['net_gbp'] >= 0 ? 'positive' : 'negative';
        $cardsHtml = '<div class="finance-grid">'
            .'<div class="finance-card income"><div>Income this month</div><div class="metric positive">£'.h(number_format($month['earned_gbp'],2)).'</div><div class="sub">£'.h(number_format($month['earned_from_leads_gbp'],2)).' from leads · £'.h(number_format($month['earned_manual_gbp'],2)).' manual</div></div>'
            .'<div class="finance-card expense"><div>Expenses this month</div><div class="metric negative">£'.h(number_format($month['expenses_gbp'],2)).'</div><div class="sub">₨'.h(number_format($month['expenses_pkr'],2)).' at locked rates</div></div>'
            .'<div class="finance-card net"><div>Net this month</div><div class="metric '.$netClass.'">£'.h(number_format($month['net_gbp'],2)).'</div><div class="sub">Today: £'.h(number_format($periods['today']['net_gbp']??0,2)).' · This year: £'.h(number_format($periods['year']['net_gbp']??0,2)).'</div></div>'
            .'</div>';
    } else {
        $cardsHtml = '<div class="finance-grid"><div class="finance-card"><div>Income</div><div class="metric">—</div></div><div class="finance-card"><div>Expenses</div><div class="metric">—</div></div><div class="finance-card"><div>Net</div><div class="metric">—</div></div></div>';
    }

    $byCategoryTable = '<p style="color:var(--muted)">No expenses recorded yet.</p>';
    if (!empty($month['by_category'])) {
        $rows = '';
        foreach ($month['by_category'] as $row) {
            $rows .= '<tr><td>'.h($row['category']).'</td><td class="amount-gbp">£'.h(number_format((float)$row['gbp'],2)).'</td><td class="amount-pkr">₨'.h(number_format((float)$row['pkr'],2)).'</td><td>'.h($row['count']).'</td></tr>';
        }
        $byCategoryTable = '<table><thead><tr><th>Category</th><th>GBP</th><th>PKR</th><th>Count</th></tr></thead><tbody>'.$rows.'</tbody></table>';
    }

    $expenseRows = '';
    foreach ($finance->listExpenses(50) as $e) {
        $expenseRows .= '<tr><td>'.h($e['expense_date']).'</td><td>'.h($e['category_name']?:($e['category']?:'Uncategorised')).'</td><td>'.h($e['supplier']?:'—').'</td><td class="amount-gbp">£'.h(number_format((float)$e['amount'],2)).'</td><td class="amount-pkr">₨'.h(number_format((float)($e['converted_amount_pkr']??0),2)).'</td><td>'.h($e['description']?:'—').'</td></tr>';
    }
    $expenseTable = '<table><thead><tr><th>Date</th><th>Category</th><th>Payee</th><th>GBP</th><th>PKR</th><th>Note</th></tr></thead><tbody>'.($expenseRows?:'<tr><td colspan="6">No expenses yet.</td></tr>').'</tbody></table>';

    $incomeRows = '';
    foreach ($finance->listIncome(50) as $i) {
        $incomeRows .= '<tr><td>'.h($i['received_at']).'</td><td>'.h(ucwords($i['source'])).'</td><td class="amount-gbp">£'.h(number_format((float)$i['amount_gbp'],2)).'</td><td>'.h($i['description']?:'—').'</td></tr>';
    }
    $incomeTable = '<table><thead><tr><th>Date</th><th>Source</th><th>GBP</th><th>Note</th></tr></thead><tbody>'.($incomeRows?:'<tr><td colspan="4">No manual income recorded yet.</td></tr>').'</tbody></table>';

    $categoryChips = '';
    foreach ($categories as $c) {
        $archiveBtn = (int)$c['is_default'] === 0
            ? '<form method="post" action="/finance/category/'.(int)$c['id'].'/archive"><input type="hidden" name="csrf" value="'.h(AuthService::csrfToken()).'"><button title="Archive">×</button></form>'
            : '';
        $chipClass = $c['is_default'] ? ' default' : '';
        $categoryChips .= '<span class="chip'.$chipClass.'">'.h($c['name']).$archiveBtn.'</span>';
    }

    $notice = $summaryError ? '<p class="notice">Finance tables aren\'t set up yet — run <code>database/finance_v2_migration.sql</code> against the CRM database, then reload this page. ('.h($summaryError).')</p>' : '';

    $body = '<div class="toolbar"><div><h1>Finance</h1><p>Income and expenses across the business — Google Ads spend, payments, and manual income. Every expense locks its GBP→PKR rate at entry.</p></div><div style="display:flex;gap:10px"><a class="button" href="/finance/report" style="background:var(--ink-soft)">Overall report</a><a class="button" href="/finance/import">Import CSV</a></div></div>'
        .$notice.$cardsHtml
        .'<h2>Expenses by category — this month</h2>'.$byCategoryTable
        .'<h2>Categories</h2><div class="chip-row">'.($categoryChips?:'<p style="color:var(--muted)">No categories yet.</p>').'</div>'
        .'<form class="form form-grid" method="post" action="/finance/category/new" style="max-width:420px;margin-bottom:28px"><input type="hidden" name="csrf" value="'.h(AuthService::csrfToken()).'"><label>New category<input name="name" required></label><div class="form-actions"><button>Add category</button></div></form>'
        .'<div class="finance-cols">'
            .'<form class="form form-grid" method="post" action="/finance/expense/new"><input type="hidden" name="csrf" value="'.h(AuthService::csrfToken()).'"><div class="form-section">Add expense</div><label>Category<select name="category_id">'.$categoryOptions.'</select></label><label>Payee<input name="payee"></label><label>Amount (GBP)<input type="number" step="0.01" name="amount_gbp" required></label><label>Date<input type="date" name="expense_date" value="'.h(date('Y-m-d')).'"></label><label>Note<input name="description"></label><div class="form-actions"><button style="background:var(--brand)">Add expense</button></div></form>'
            .'<form class="form form-grid" method="post" action="/finance/income/new"><input type="hidden" name="csrf" value="'.h(AuthService::csrfToken()).'"><div class="form-section">Add income</div><label>Source<input name="source" value="manual"></label><label>Amount (GBP)<input type="number" step="0.01" name="amount_gbp" required></label><label>Date<input type="date" name="received_at" value="'.h(date('Y-m-d')).'"></label><label>Note<input name="description"></label><div class="form-actions"><button style="background:#0F7A3D">Add income</button></div></form>'
        .'</div>'
        .'<h2>Recent expenses</h2>'.$expenseTable
        .'<h2>Recent income</h2>'.$incomeTable;

    render('Finance', $body, $user);
}
if ($path === '/insights') {
    $qualityTable = static function (array $rows): string {
        if (!$rows) return '<p style="color:var(--muted)">No data yet.</p>';
        $out = '<table><thead><tr><th></th><th>Leads</th><th>Qualified</th><th>Completed</th><th>Revenue</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $out .= '<tr><td>'.h($r['dimension']?:'—').'</td><td>'.h($r['leads']).'</td><td>'.h($r['qualified_leads']).'</td><td>'.h($r['completed_leads']).'</td><td>£'.h(number_format((float)$r['revenue'],2)).'</td></tr>';
        }
        return $out.'</tbody></table>';
    };

    $lqr = new LeadQualityReport(new Database());
    $byCity = $qualityTable($lqr->byCity());
    $byVehicle = $qualityTable($lqr->byVehicleType());
    $byCampaign = $qualityTable($lqr->byCampaign());

    $yesterday = (new DateTimeImmutable('yesterday', new DateTimeZone(envValue('APP_TIMEZONE','Europe/London'))))->format('Y-m-d');
    $recommendations = [];
    try { $recommendations = (new OptimiserService(new Database()))->recommendations($yesterday); } catch (\Throwable) { /* no metrics collected yet — shown as empty state below */ }

    $recRows = '';
    foreach ($recommendations as $r) {
        $recRows .= '<tr><td>'.statusBadge($r['risk']).'</td><td>'.h($r['campaign']).'</td><td>'.h(ucwords(str_replace('_',' ',$r['action']))).'</td><td>'.h($r['reason']).'</td></tr>';
    }
    $recTable = $recommendations
        ? '<table><thead><tr><th>Risk</th><th>Campaign</th><th>Suggested action</th><th>Reason</th></tr></thead><tbody>'.$recRows.'</tbody></table>'
        : '<p style="color:var(--muted)">No campaign performance data for '.h($yesterday).' yet'.(envValue('GOOGLE_ADS_DEVELOPER_TOKEN')?'':' — this fills in automatically once the daily audit runs successfully').'.</p>';

    render('Insights', '<h1>Insights</h1><p>Lead quality below reflects every lead in the CRM regardless of Google Ads status. Campaign recommendations need at least one successful daily audit to have data to analyse.</p>'
        .'<h2>Campaign recommendations — '.h($yesterday).'</h2>'.$recTable
        .'<h2>Lead quality by city</h2>'.$byCity
        .'<h2>Lead quality by vehicle type</h2>'.$byVehicle
        .'<h2>Lead quality by campaign</h2>'.$byCampaign, $user);
}
if ($path === '/changes') {
    $rows=''; $approvedCount=0; foreach((new ApprovalService())->list(100) as $c){
        if ($c['status']==='planned') $approvedCount++;
        $actions = $c['status']==='pending_approval'
            ? '<form method="post" action="/changes/'.h($c['change_uuid']).'/approve" style="display:inline"><input type="hidden" name="csrf" value="'.h(AuthService::csrfToken()).'"><button>Approve</button></form> '
              .'<form method="post" action="/changes/'.h($c['change_uuid']).'/reject" style="display:inline"><input type="hidden" name="csrf" value="'.h(AuthService::csrfToken()).'"><button style="background:#b42318">Reject</button></form>'
            : '—';
        $rows.='<tr><td>'.h($c['change_type']).'</td><td>'.h($c['resource_name']?:'—').'</td><td>'.h($c['reason']).'</td><td>'.h($c['risk_level']).'</td><td>'.statusBadge($c['status']).'</td><td>'.h($c['created_at']).'</td><td>'.$actions.'</td></tr>';

        if ($c['change_type'] === 'create_campaign' && $c['status'] === 'executed' && $c['before_state']) {
            $decoded = json_decode((string) $c['before_state'], true);
            $checklist = $decoded['still_needed_before_enabling'] ?? null;
            if ($checklist) {
                $items = '';
                foreach ($checklist as $item) { $items .= '<li>'.h($item).'</li>'; }
                $rows .= '<tr><td colspan="7" style="background:#FFF4E0;padding:12px 14px"><strong>Campaign created PAUSED — before enabling in Google Ads:</strong><ul style="margin:8px 0 0;padding-left:20px;color:var(--text)">'.$items.'</ul></td></tr>';
            }
        }
    }
    $flash='';
    if (isset($_GET['executed']) || isset($_GET['failed'])) {
        $flash='<p class="notice">Run complete — '.(int)($_GET['executed']??0).' change(s) executed, '.(int)($_GET['failed']??0).' failed. Failed changes are marked below; check their reason or contact support before retrying.</p>';
    }
    $runButton = $approvedCount > 0
        ? '<form method="post" action="/changes/run-approved" onsubmit="return confirm(\'This will apply '.(int)$approvedCount.' approved change(s) to your live Google Ads account. Continue?\')"><input type="hidden" name="csrf" value="'.h(AuthService::csrfToken()).'"><button>Run '.(int)$approvedCount.' approved change(s)</button></form>'
        : '';
    render('Changes','<div class="toolbar"><div><h1>Automation changes</h1><p>Automation mode: <strong>'.h(envValue('AUTOMATION_MODE','audit_only')).'</strong>. Approving a change only marks it ready — nothing reaches Google Ads until you click below, so you control exactly when each batch of changes goes live. Campaigns are always created PAUSED and never auto-enabled.</p></div>'.$runButton.'</div>'.$flash.'<table><thead><tr><th>Type</th><th>Resource</th><th>Reason</th><th>Risk</th><th>Status</th><th>Created</th><th>Action</th></tr></thead><tbody>'.($rows?:'<tr><td colspan="7">No automation changes yet.</td></tr>').'</tbody></table>',$user);
}

$data=$leads->dashboard(); $t=$data['today']; $pendingApprovals=(new \BMT\Dashboard\DashboardService(new Database()))->overview()['pending_approvals'] ?? 0; $cards='<div class="grid"><div class="card"><div>New leads today</div><div class="metric">'.h($t['total']??0).'</div></div><div class="card"><div>Qualified today</div><div class="metric">'.h($t['qualified']??0).'</div></div><div class="card"><div>Completed today</div><div class="metric">'.h($t['completed']??0).'</div></div><div class="card"><div>Completed revenue today</div><div class="metric">£'.h(number_format((float)($t['revenue']??0),2)).'</div></div><div class="card"><a href="/changes" style="text-decoration:none;color:inherit"><div>Pending approvals</div><div class="metric">'.h($pendingApprovals).'</div></a></div></div>';
$pipeline=''; foreach($data['pipeline'] as $p)$pipeline.='<tr><td>'.h(ucwords(str_replace('_',' ',$p['status']))).'</td><td>'.h($p['total']).'</td></tr>';
$body='<div class="toolbar"><div><h1>Dashboard</h1><p>Welcome, '.h($user['name']).'. Automation mode: <strong>'.h(envValue('AUTOMATION_MODE', 'audit_only')).'</strong></p></div><a class="button" href="/leads/new">Add lead</a></div>'.$cards.'<h2>Lead pipeline</h2><table><tr><th>Status</th><th>Total</th></tr>'.$pipeline.'</table>';
render('Dashboard',$body,$user);
