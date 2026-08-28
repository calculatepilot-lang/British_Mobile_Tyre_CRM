<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use BMT\Approvals\ApprovalService;
use BMT\Auth\AuthService;
use BMT\Database;
use BMT\Leads\LeadRepository;
use BMT\Leads\LeadService;

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
function render(string $title, string $body, ?array $user): never {
    $nav = $user ? '<aside><h2>BMT CRM</h2><a href="/">Dashboard</a><a href="/leads">Leads</a><a href="/leads/new">Add lead</a><a href="/changes">Changes</a><a href="/logout">Logout</a></aside>' : '';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . h($title) . ' | BMT CRM</title><style>body{margin:0;font-family:Arial,sans-serif;background:#f5f7fb;color:#18202a}.layout{display:flex;min-height:100vh}aside{width:220px;background:#10233b;color:#fff;padding:24px}aside a{display:block;color:#dbe8f6;text-decoration:none;padding:10px 0}.content{flex:1;padding:28px;max-width:1200px}h1{margin-top:0}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px}.card{background:#fff;border:1px solid #e1e6ee;border-radius:12px;padding:18px}.metric{font-size:28px;font-weight:bold}.toolbar{display:flex;justify-content:space-between;align-items:center;margin:16px 0}.button,button{display:inline-block;background:#1463c3;color:#fff;border:0;border-radius:7px;padding:10px 14px;text-decoration:none;cursor:pointer}table{width:100%;border-collapse:collapse;background:#fff}th,td{text-align:left;padding:10px;border-bottom:1px solid #e6eaf0;font-size:14px}input,select,textarea{width:100%;box-sizing:border-box;padding:10px;border:1px solid #cfd7e3;border-radius:7px;margin:5px 0 12px}.form{max-width:720px;background:#fff;padding:24px;border-radius:12px}.notice{padding:12px;border-radius:8px;background:#fff3cd;margin-bottom:16px}.status{padding:3px 8px;border-radius:20px;background:#edf2f7;font-size:12px}@media(max-width:700px){aside{width:auto;padding:12px}.layout{display:block}.content{padding:16px}}</style></head><body><div class="layout">' . $nav . '<main class="content">' . $body . '</main></div></body></html>'; exit;
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
    if (!$auth->attempt((string)($_POST['email'] ?? ''), (string)($_POST['password'] ?? ''))) render('Login', '<h1>Login</h1><p class="notice">Invalid email or password.</p><form class="form" method="post"><input type="hidden" name="csrf" value="'.h(AuthService::csrfToken()).'"><label>Email<input type="email" name="email" required></label><label>Password<input type="password" name="password" required></label><button>Sign in</button></form>', null);
    redirect('/');
}
if ($path === '/login') render('Login', '<h1>British Mobile Tyres CRM</h1><p>Private administration area.</p><form class="form" method="post"><input type="hidden" name="csrf" value="'.h(AuthService::csrfToken()).'"><label>Email<input type="email" name="email" required></label><label>Password<input type="password" name="password" required></label><button>Sign in</button></form>', null);
if ($path === '/logout') { $auth->logout(); redirect('/login'); }

$user = $auth->user();
if ($user === null) redirect('/login');

if ($path === '/leads/new' && $method === 'POST') {
    if (!AuthService::verifyCsrf($_POST['csrf'] ?? null)) { http_response_code(419); render('Invalid request', '<h1>Invalid request</h1>', $user); }
    $publicId = (new LeadService())->create([
        'lead_type' => $_POST['lead_type'] ?? '', 'source' => $_POST['source'] ?? 'manual', 'customer_name' => $_POST['customer_name'] ?? null,
        'customer_phone' => $_POST['customer_phone'] ?? null, 'customer_email' => $_POST['customer_email'] ?? null,
        'service_requested' => $_POST['service_requested'] ?? null, 'city' => $_POST['city'] ?? null, 'postcode' => $_POST['postcode'] ?? null,
    ], ['gclid'=>$_POST['gclid'] ?? null, 'gbraid'=>$_POST['gbraid'] ?? null, 'wbraid'=>$_POST['wbraid'] ?? null, 'utm_source'=>$_POST['utm_source'] ?? null, 'utm_medium'=>$_POST['utm_medium'] ?? null, 'utm_campaign'=>$_POST['utm_campaign'] ?? null]);
    redirect('/leads/' . rawurlencode($publicId));
}
if ($path === '/leads/new') {
    $types=''; foreach(['phone'=>'Phone','whatsapp'=>'WhatsApp','form'=>'Form','purchase'=>'Purchase','other'=>'Other'] as $v=>$label) $types.='<option value="'.h($v).'">'.h($label).'</option>';
    $body='<h1>Add lead</h1><form class="form" method="post"><input type="hidden" name="csrf" value="'.h(AuthService::csrfToken()).'"><label>Lead type<select name="lead_type" required>'.$types.'</select></label><label>Source<input name="source" value="manual"></label><label>Customer name<input name="customer_name"></label><label>Phone<input name="customer_phone"></label><label>Email<input type="email" name="customer_email"></label><label>Service requested<input name="service_requested"></label><label>City<input name="city"></label><label>Postcode<input name="postcode"></label><h3>Google Ads attribution</h3><label>GCLID<input name="gclid"></label><label>GBRAID<input name="gbraid"></label><label>WBRAID<input name="wbraid"></label><label>UTM source<input name="utm_source"></label><label>UTM medium<input name="utm_medium"></label><label>UTM campaign<input name="utm_campaign"></label><button>Create lead</button></form>';
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
    $fields=['customer_name'=>'Customer name','customer_phone'=>'Phone','customer_email'=>'Email','service_requested'=>'Service requested','city'=>'City','postcode'=>'Postcode','quoted_amount'=>'Quoted amount','final_revenue'=>'Final revenue','quality_score'=>'Quality score 0-100','outcome_reason'=>'Outcome reason'];
    $inputs=''; foreach($fields as $key=>$label){$type=in_array($key,['quoted_amount','final_revenue','quality_score'],true)?'number':'text';$step=in_array($key,['quoted_amount','final_revenue'],true)?' step="0.01"':'';$min=$key==='quality_score'?' min="0" max="100"':'';$inputs.='<label>'.h($label).'<input type="'.$type.'"'.$step.$min.' name="'.h($key).'" value="'.h($lead[$key]??'').'"></label>';}
    $body='<div class="toolbar"><div><h1>'.h($lead['public_id']).'</h1><p><span class="status">'.h($lead['status']).'</span> '.h($lead['lead_type']).' · '.h($lead['source']).'</p></div><a class="button" href="/leads">Back to leads</a></div><form class="form" method="post" action="/leads/'.rawurlencode($lead['public_id']).'/update"><input type="hidden" name="csrf" value="'.h(AuthService::csrfToken()).'"><label>Status<select name="status">'.$statuses.'</select></label>'.$inputs.'<h3>Attribution</h3><p>Campaign: '.h($lead['campaign_name']??'—').' · Ad group: '.h($lead['ad_group_name']??'—').'</p><p>Keyword: '.h($lead['keyword_text']??'—').' · GCLID: '.h($lead['gclid']??'—').'</p><button>Save changes</button></form>';
    render('Lead ' . $lead['public_id'], $body, $user);
}
if ($path === '/leads') {
    $rows=''; foreach($leads->list(100) as $lead){$rows.='<tr><td><a href="/leads/'.rawurlencode($lead['public_id']).'">'.h($lead['public_id']).'</a></td><td>'.h($lead['status']).'</td><td>'.h($lead['lead_type']).'</td><td>'.h($lead['customer_name']?:'—').'</td><td>'.h($lead['city']?:'—').'</td><td>'.h($lead['campaign_name']?:'—').'</td><td>'.h($lead['created_at']).'</td></tr>';}
    render('Leads','<div class="toolbar"><h1>Leads</h1><a class="button" href="/leads/new">Add lead</a></div><table><thead><tr><th>ID</th><th>Status</th><th>Type</th><th>Customer</th><th>City</th><th>Campaign</th><th>Created</th></tr></thead><tbody>'.$rows.'</tbody></table>',$user);
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
if ($path === '/changes') {
    $rows=''; foreach((new ApprovalService())->list(100) as $c){
        $actions = $c['status']==='pending_approval'
            ? '<form method="post" action="/changes/'.h($c['change_uuid']).'/approve" style="display:inline"><input type="hidden" name="csrf" value="'.h(AuthService::csrfToken()).'"><button>Approve</button></form> '
              .'<form method="post" action="/changes/'.h($c['change_uuid']).'/reject" style="display:inline"><input type="hidden" name="csrf" value="'.h(AuthService::csrfToken()).'"><button style="background:#b42318">Reject</button></form>'
            : '—';
        $rows.='<tr><td>'.h($c['change_type']).'</td><td>'.h($c['resource_name']?:'—').'</td><td>'.h($c['reason']).'</td><td>'.h($c['risk_level']).'</td><td><span class="status">'.h(str_replace('_',' ',$c['status'])).'</span></td><td>'.h($c['created_at']).'</td><td>'.$actions.'</td></tr>';
    }
    render('Changes','<h1>Automation changes</h1><p>Google Ads mutation execution remains disabled (automation mode: <strong>'.h(envValue('AUTOMATION_MODE','audit_only')).'</strong>). Approving a change here records the decision only — nothing is written to Google Ads until a reviewed executor is built.</p><table><thead><tr><th>Type</th><th>Resource</th><th>Reason</th><th>Risk</th><th>Status</th><th>Created</th><th>Action</th></tr></thead><tbody>'.($rows?:'<tr><td colspan="7">No automation changes yet.</td></tr>').'</tbody></table>',$user);
}

$data=$leads->dashboard(); $t=$data['today']; $cards='<div class="grid"><div class="card"><div>New leads today</div><div class="metric">'.h($t['total']??0).'</div></div><div class="card"><div>Qualified today</div><div class="metric">'.h($t['qualified']??0).'</div></div><div class="card"><div>Completed today</div><div class="metric">'.h($t['completed']??0).'</div></div><div class="card"><div>Completed revenue today</div><div class="metric">£'.h(number_format((float)($t['revenue']??0),2)).'</div></div></div>';
$pipeline=''; foreach($data['pipeline'] as $p)$pipeline.='<tr><td>'.h(ucwords(str_replace('_',' ',$p['status']))).'</td><td>'.h($p['total']).'</td></tr>';
$body='<div class="toolbar"><div><h1>Dashboard</h1><p>Welcome, '.h($user['name']).'. Automation mode: <strong>'.h(envValue('AUTOMATION_MODE', 'audit_only')).'</strong></p></div><a class="button" href="/leads/new">Add lead</a></div>'.$cards.'<h2>Lead pipeline</h2><table><tr><th>Status</th><th>Total</th></tr>'.$pipeline.'</table>';
render('Dashboard',$body,$user);
