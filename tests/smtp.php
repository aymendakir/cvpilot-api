<?php
// php tests/smtp.php — isolated DB, fake OAuth HTTP, intercepted mail; no real messages.
require __DIR__.'/../vendor/autoload.php';
putenv('APP_ENV=testing');
putenv('APP_KEY=base64:'.base64_encode(random_bytes(32)));
putenv('DB_CONNECTION=sqlite'); putenv('DB_DATABASE=:memory:'); putenv('SESSION_SECURE_COOKIE=false');
$app = require __DIR__.'/../bootstrap/app.php';
// Keep boot-time rate limiters out of the application's file cache.
$app->afterBootstrapping(Illuminate\Foundation\Bootstrap\LoadConfiguration::class, function () {
    config(['cache.default'=>'array', 'cache.stores.array'=>['driver'=>'array']]);
});
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class); $kernel->bootstrap();
config([
    'database.default'=>'sqlite', 'database.connections.sqlite'=>['driver'=>'sqlite', 'database'=>':memory:', 'prefix'=>'', 'foreign_key_constraints'=>true],
    'cache.default'=>'array', 'cache.stores.array'=>['driver'=>'array'], 'session.driver'=>'array', 'session.secure'=>false,
    'hashing.bcrypt.rounds'=>4, 'app.url'=>'https://api.example.test', 'mail.frontend_url'=>'https://app.example.test',
    'mail.mailers.smtp'=>['transport'=>'smtp', 'scheme'=>'smtps', 'host'=>'smtp.example.test', 'port'=>465,
        'username'=>'sender@example.test', 'password'=>'env-secret', 'require_tls'=>true, 'timeout'=>15],
    'mail.from'=>['address'=>'sender@example.test', 'name'=>'Environment Sender'],
]);
Illuminate\Support\Facades\Artisan::call('migrate', ['--force'=>true]);
set_exception_handler(function (Throwable $error) { fwrite(STDERR, $error->getMessage()."\n".$error->getTraceAsString()."\n"); exit(1); });

use App\Models\MailSetting;
use App\Services\MicrosoftSmtpOAuth;
use App\Services\PlatformMail;
use Illuminate\Support\Facades\{Cache, DB, Event, Http, Mail};

function check(bool $condition, string $label): void {
    if (!$condition) throw new RuntimeException($label);
    echo "PASS $label\n";
}
function api(string $method, string $path, array $body = [], array &$cookies = []): array {
    global $kernel;
    // Match fresh HTTP requests while preserving the in-memory session handler.
    app('session')->driver()->flush();
    $request = Illuminate\Http\Request::create('https://api.example.test/api/'.$path, $method, [], $cookies, [], [
        'HTTP_ACCEPT'=>'application/json', 'CONTENT_TYPE'=>'application/json', 'REMOTE_ADDR'=>'127.0.0.1',
    ], json_encode($body));
    $response = $kernel->handle($request);
    foreach ($response->headers->getCookies() as $cookie) $cookies[$cookie->getName()] = $cookie->getValue();
    $kernel->terminate($request, $response);
    return [$response->getStatusCode(), json_decode($response->getContent(), true), $response];
}
function account(string $email, string $role): App\Models\User {
    $user = new App\Models\User;
    $user->name = 'SMTP Test'; $user->email = $email; $user->role = $role;
    $user->password = Illuminate\Support\Facades\Hash::make('test-password-1234'); $user->verified_at = now(); $user->save();
    return $user;
}
class InspectMailManager extends Illuminate\Mail\MailManager {
    public ?Illuminate\Mail\Mailer $built = null;
    public function build($config) { return $this->built = parent::build($config); }
}
$manager = new InspectMailManager($app);
Mail::swap($manager);
$lastMessage = null;
Event::listen(Illuminate\Mail\Events\MessageSending::class, function ($event) use (&$lastMessage) {
    $lastMessage = $event->message;
    return false; // Stop before any network connection or recipient delivery.
});
Http::preventStrayRequests();
$tokenResponse = ['access_token'=>'access-one', 'refresh_token'=>'refresh-one', 'expires_in'=>3600];
$tokenStatus = 200;
$requests = [];
Http::fake(['https://login.microsoftonline.com/*'=>function ($request) use (&$tokenResponse, &$tokenStatus, &$requests) {
    $requests[] = $request;
    return Http::response($tokenResponse, $tokenStatus);
}]);
$owner = []; $member = []; $guest = [];
$admin = account('admin@example.test', 'admin'); account('member@example.test', 'user');
check(api('POST', 'login', ['email'=>$admin->email, 'password'=>'test-password-1234'], $owner)[0] === 200, 'administrator signed in');
check(api('POST', 'login', ['email'=>'member@example.test', 'password'=>'test-password-1234'], $member)[0] === 200, 'member signed in');
foreach (['GET'=>'admin/smtp', 'PUT'=>'admin/smtp', 'POST'=>'admin/smtp/microsoft/connect'] as $method=>$path) {
    $denied = api($method, $path, [], $member);
    check($denied[0] === 403, "$method $path denies non-admin (status ".$denied[0].")");
}
$guestTest = api('POST', 'admin/smtp/test', [], $guest);
check($guestTest[0] === 401, 'guest cannot send tests (status '.$guestTest[0].')');
$settings = api('GET', 'admin/smtp', [], $owner)[1];
check($settings['encryption'] === 'ssl' && $settings['has_password'] && $settings['from_name'] === 'Environment Sender', 'environment secret and SSL settings are reflected accurately');
check(!str_contains(json_encode($settings), 'env-secret'), 'environment password stays private');
$mail = new PlatformMail;
$mail->send('recipient@example.test', 'Environment test', 'emails.notice', ['name'=>'Test', 'heading'=>'Test', 'noticeMessage'=>'Test <script>alert(1)</script>']);
check($manager->built->getSymfonyTransport()->getStream()->isTLS(), 'environment fallback uses implicit TLS on port 465');
check($lastMessage->getFrom()[0]->getName() === 'Environment Sender', 'environment fallback honors sender name');
check(str_contains($lastMessage->getHtmlBody(), 'Test &lt;script&gt;') && !str_contains($lastMessage->getHtmlBody(), '<script>'), 'notice email renders through Laravel and escapes its content');
$custom = ['host'=>'smtp.example.test', 'port'=>465, 'encryption'=>'ssl', 'username'=>'sender@example.test',
    'password'=>'', 'from_address'=>'sender@example.test', 'from_name'=>'CVPilot'];
check(api('PUT', 'admin/smtp', $custom, $owner)[0] === 200 && MailSetting::first()->password === 'env-secret', 'saving environment settings keeps the existing secret');
check(api('PUT', 'admin/smtp', array_replace($custom, ['port'=>587]), $owner)[0] === 422, 'mismatched TLS and port rejected');
check(api('PUT', 'admin/smtp', array_replace($custom, ['host'=>'smtp.other.test']), $owner)[0] === 422, 'changed host cannot reuse saved password');
check(MailSetting::first()->host === $custom['host'], 'failed validation preserves working configuration');
check(api('PUT', 'admin/smtp', array_replace($custom, ['username'=>'other@example.test']), $owner)[0] === 422, 'changed mailbox needs a fresh password');
$gmail = array_replace($custom, ['host'=>'  SMTP.GMAIL.COM ', 'port'=>587, 'encryption'=>'tls',
    'username'=>' sender@gmail.com ', 'from_address'=>' sender@gmail.com ', 'password'=>'abcd efgh ijkl mnop']);
check(api('PUT', 'admin/smtp', $gmail, $owner)[0] === 200, 'Gmail preset settings save');
$stored = MailSetting::first();
check($stored->host === 'smtp.gmail.com' && $stored->username === 'sender@gmail.com' && $stored->password === 'abcdefghijklmnop', 'host, mailbox and grouped Google App Password are normalized');
check(!str_contains(DB::table('mail_settings')->value('password'), 'abcdefghijklmnop'), 'SMTP password encrypted at rest');
$gmail = array_replace($gmail, ['host'=>'smtp.gmail.com', 'username'=>'sender@gmail.com', 'from_address'=>'sender@gmail.com', 'password'=>'']);
check(api('PUT', 'admin/smtp', $gmail, $owner)[0] === 200 && MailSetting::first()->password === 'abcdefghijklmnop', 'blank password preserves same Gmail account');
check(api('POST', 'admin/smtp/test', [], $owner)[0] === 200, 'test endpoint uses configured mail service');
check($lastMessage->getTo()[0]->getAddress() === $admin->email && $lastMessage->getFrom()[0]->getAddress() === 'sender@gmail.com', 'test sent only to signed-in admin with saved sender');
$transport = $manager->built->getSymfonyTransport();
check($transport->isTlsRequired() && !$transport->getStream()->isTLS() && $transport->getStream()->getPort() === 587, 'STARTTLS required on Gmail submission transport');
$gmailFailure = $mail->failureMessage(new RuntimeException('535 Failed to authenticate abcdefghijklmnop'));
check(str_contains($gmailFailure, 'App Password') && !str_contains($gmailFailure, 'abcdefghijklmnop'), 'Gmail authentication error gives safe actionable guidance');
check(str_contains($mail->failureMessage(new RuntimeException('Connection could not be established')), 'hosting provider'), 'connection errors explain hosting SMTP restrictions');
check(str_contains($mail->failureMessage(new RuntimeException('certificate verify failed')), 'certificate'), 'TLS errors explain certificate failure');
check(!str_contains($mail->failureMessage(new RuntimeException('Unknown failure SECRET')), 'SECRET'), 'unclassified errors never echo credentials');
$outlook = array_replace($gmail, ['host'=>'smtp-mail.outlook.com', 'username'=>'sender@outlook.com', 'from_address'=>'sender@outlook.com', 'password'=>'wrong-password']);
check(api('PUT', 'admin/smtp', $outlook, $owner)[0] === 422, 'Outlook.com rejects password-only configuration');
$outlook = array_replace($outlook, ['auth_mode'=>'microsoft', 'password'=>'', 'oauth_tenant'=>'common',
    'oauth_client_id'=>'12345678-1234-4123-8123-123456789012', 'oauth_client_secret'=>'client-secret']);
check(api('PUT', 'admin/smtp', $outlook, $owner)[0] === 200, 'Microsoft application configuration saves');
check(MailSetting::first()->password === null, 'switching to OAuth discards SMTP password');
check(api('PUT', 'admin/smtp', array_replace($outlook, ['host'=>'smtp.untrusted.test']), $owner)[0] === 422, 'OAuth tokens cannot be configured for another SMTP host');
$beforeConnect = api('POST', 'admin/smtp/test', [], $owner);
check($beforeConnect[0] === 422 && str_contains($beforeConnect[1]['message'], 'Connect your Microsoft account'), 'sending without Microsoft authorization gives a clear error');
function beginMicrosoft(array &$owner): array {
    $response = api('POST', 'admin/smtp/microsoft/connect', [], $owner);
    check($response[0] === 200, 'Microsoft authorization starts');
    parse_str(parse_url($response[1]['url'], PHP_URL_QUERY), $query);
    return $query;
}
$query = beginMicrosoft($owner);
check($query['scope'] === MicrosoftSmtpOAuth::SCOPE && $query['code_challenge_method'] === 'S256' && !empty($query['code_challenge']), 'authorization requests SMTP scope, offline access and PKCE');
check($query['redirect_uri'] === 'https://api.example.test/api/admin/smtp/microsoft/callback', 'callback uses configured public backend URL');
check(api('GET', 'admin/smtp/microsoft/callback?state=wrong&code=bad', [], $owner)[0] === 422 && count($requests) === 0, 'invalid state cannot exchange an authorization code');
$query = beginMicrosoft($owner);
check(api('GET', 'admin/smtp/microsoft/callback?'.http_build_query(['state'=>$query['state'], 'error'=>'access_denied']), [], $owner)[0] === 422 && count($requests) === 0, 'declined consent makes no token request');
$query = beginMicrosoft($owner);
$callback = 'admin/smtp/microsoft/callback?'.http_build_query(['state'=>$query['state'], 'code'=>'authorization-code']);
$result = api('GET', $callback, [], $owner);
check($result[0] === 200 && str_contains($result[2]->getContent(), '/dashboard?section=smtp'), 'Microsoft callback connects and links back to SMTP settings');
check($result[2]->headers->get('Referrer-Policy') === 'no-referrer', 'callback does not forward authorization URL as referrer');
check($requests[0]['grant_type'] === 'authorization_code' && !empty($requests[0]['code_verifier']), 'authorization exchange includes original PKCE verifier');
check(rtrim(strtr(base64_encode(hash('sha256', $requests[0]['code_verifier'], true)), '+/', '-_'), '=') === $query['code_challenge'], 'PKCE verifier matches authorization challenge');
check(api('GET', $callback, [], $owner)[0] === 422 && count($requests) === 1, 'callback authorization cannot be replayed');
$settings = api('GET', 'admin/smtp', [], $owner)[1];
check($settings['oauth_connected'] && $settings['has_oauth_client_secret'], 'UI receives connection status');
foreach (['client-secret', 'access-one', 'refresh-one'] as $secret) {
    check(!str_contains(json_encode($settings), $secret) && !str_contains(json_encode(DB::table('mail_settings')->first()), $secret), 'Microsoft credential encrypted and absent from API: '.explode('-', $secret)[0]);
}
$mail->send('recipient@example.test', 'Microsoft test', 'emails.notice', ['name'=>'Test', 'heading'=>'Test', 'noticeMessage'=>'Test <script>alert(1)</script>']);
$transport = $manager->built->getSymfonyTransport();
$authenticators = (new ReflectionProperty($transport, 'authenticators'))->getValue($transport);
check(count($authenticators) === 1 && $authenticators[0] instanceof Symfony\Component\Mailer\Transport\Smtp\Auth\XOAuth2Authenticator, 'Microsoft sends credentials exclusively through XOAUTH2');
check($transport->getPassword() === 'access-one' && count($requests) === 1, 'valid cached Microsoft access token used without refresh');
$oauth = app(MicrosoftSmtpOAuth::class);
$stored = MailSetting::first(); $stored->oauth_expires_at = now()->subMinute(); $stored->save();
$tokenResponse = ['access_token'=>'access-two', 'refresh_token'=>'refresh-two', 'expires_in'=>3600];
check($oauth->accessToken(MailSetting::first()) === 'access-two' && $requests[1]['grant_type'] === 'refresh_token' && $requests[1]['refresh_token'] === 'refresh-one', 'expired access token refreshed automatically');
check(MailSetting::first()->oauth_refresh_token === 'refresh-two', 'rotated refresh token is persisted');
$stored = MailSetting::first(); $stored->oauth_expires_at = now()->subMinute(); $stored->save();
$tokenResponse = ['access_token'=>'access-three', 'expires_in'=>3600];
check($oauth->accessToken(MailSetting::first()) === 'access-three' && MailSetting::first()->oauth_refresh_token === 'refresh-two', 'refresh keeps existing refresh token when no replacement returned');
$stored = MailSetting::first(); $stored->oauth_expires_at = now()->subMinute(); $stored->save();
$tokenStatus = 400; $tokenResponse = ['error'=>'invalid_grant', 'error_description'=>'private-provider-diagnostic'];
try { $oauth->accessToken(MailSetting::first()); throw new LogicException('Expected rejected grant'); }
catch (App\Services\MailConfigurationException $error) {
    check(str_contains($error->getMessage(), 'Reconnect') && !str_contains($error->getMessage(), 'private-provider'), 'revoked Microsoft permission gives reconnect guidance without raw response');
}
$outlook['oauth_client_secret'] = '';
check(api('PUT', 'admin/smtp', $outlook, $owner)[0] === 200 && MailSetting::first()->oauth_refresh_token === 'refresh-two', 'saving unchanged Microsoft setup keeps connection and client secret');
$query = beginMicrosoft($owner);
$outlook['username'] = $outlook['from_address'] = 'other@outlook.com';
check(api('PUT', 'admin/smtp', $outlook, $owner)[0] === 200 && MailSetting::first()->oauth_refresh_token === null, 'changing Microsoft mailbox invalidates saved tokens');
$count = count($requests);
check(api('GET', 'admin/smtp/microsoft/callback?'.http_build_query(['state'=>$query['state'], 'code'=>'stale-code']), [], $owner)[0] === 422 && count($requests) === $count, 'changed settings invalidate pending authorization');
$outlook['oauth_client_id'] = '98765432-1234-4123-8123-123456789012';
check(api('PUT', 'admin/smtp', $outlook, $owner)[0] === 422, 'changing Microsoft application requires its own secret');
check(!array_key_exists('password', api('GET', 'admin/smtp', [], $owner)[1]), 'API does not include a password value');
echo "All SMTP regression checks passed.\n";
