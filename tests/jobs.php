<?php
// php tests/jobs.php — isolated database and fake HTTP; no paid API requests.
require __DIR__.'/../vendor/autoload.php';
putenv('APP_ENV=testing'); putenv('APP_KEY=base64:'.base64_encode(random_bytes(32)));
putenv('DB_CONNECTION=sqlite'); putenv('DB_DATABASE=:memory:'); putenv('SESSION_SECURE_COOKIE=false');
$app = require __DIR__.'/../bootstrap/app.php';
$app->afterBootstrapping(Illuminate\Foundation\Bootstrap\LoadConfiguration::class, function () {
    config(['cache.default'=>'array', 'cache.stores.array'=>['driver'=>'array']]);
});
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class); $kernel->bootstrap();
config(['database.default'=>'sqlite', 'database.connections.sqlite'=>['driver'=>'sqlite', 'database'=>':memory:', 'prefix'=>'', 'foreign_key_constraints'=>true],
    'cache.default'=>'array', 'cache.stores.array'=>['driver'=>'array'], 'session.driver'=>'array', 'session.secure'=>false, 'hashing.bcrypt.rounds'=>4]);
Illuminate\Support\Facades\Artisan::call('migrate', ['--force'=>true]);
set_exception_handler(function (Throwable $e) { fwrite(STDERR, $e->getMessage()."\n".$e->getTraceAsString()."\n"); exit(1); });
use App\Models\Integration;
use App\Services\{JobSearchService, JobLocation};
use Illuminate\Support\Facades\{Cache, Http};
function check(bool $condition, string $label): void { if (!$condition) throw new RuntimeException($label); echo "PASS $label\n"; }
function api(string $method, string $path, array $body = [], array &$cookies = []): array {
    global $kernel;
    app('session')->driver()->flush();
    $request = Illuminate\Http\Request::create('http://localhost/api/'.$path, $method, [], $cookies, [], [
        'HTTP_ACCEPT'=>'application/json', 'CONTENT_TYPE'=>'application/json', 'REMOTE_ADDR'=>'127.0.0.1',
    ], json_encode($body));
    $response = $kernel->handle($request);
    foreach ($response->headers->getCookies() as $cookie) $cookies[$cookie->getName()] = $cookie->getValue();
    $kernel->terminate($request, $response);
    return [$response->getStatusCode(), json_decode($response->getContent(), true)];
}
Http::preventStrayRequests(); Http::fake([]);
$service = new JobSearchService;
$search = ['q'=>'Developpeur', 'country'=>'MA', 'country_name'=>'Morocco', 'city'=>'Casablanca', 'work_mode'=>'any', 'pages'=>2, 'limit'=>100];
$result = $service->search($search);
check($result['data'] === [] && $result['provider'] === '' && str_contains($result['message'], 'Indeed'), 'no configured local source gives an honest empty result and portal guidance');
check(Http::recorded()->isEmpty(), 'no unsupported public feed is queried for local Morocco search');
foreach (['arbeitnow', 'adzuna', 'jsearch'] as $index=>$provider) {
    Integration::create(['type'=>'jobs', 'provider'=>$provider, 'secret'=>'test-key', 'enabled'=>true, 'priority'=>$index, 'settings'=>['app_id'=>'test-app']]);
}
$requests = []; $jsearchStatus = 200;
Http::fake(['jsearch.p.rapidapi.com/*'=>function ($request) use (&$requests, &$jsearchStatus) {
    if ($jsearchStatus !== 200) return Http::response(['message'=>'Private provider diagnostic test-key'], $jsearchStatus);
    $requests[] = $request;
    $common = ['job_title'=>'Developpeur', 'employer_name'=>'Example', 'job_description'=>'Software engineering', 'job_posted_at_datetime_utc'=>date(DATE_ATOM)];
    $data = $request['page'] == 1 ? [
        $common + ['job_id'=>'de', 'job_country'=>'DE', 'job_city'=>'Berlin', 'job_is_remote'=>false, 'job_apply_link'=>'https://example.test/de'],
        $common + ['job_id'=>'de-remote', 'job_country'=>'DE', 'job_city'=>'Berlin', 'job_is_remote'=>true, 'job_apply_link'=>'https://example.test/de-remote'],
        $common + ['job_id'=>'us', 'job_country'=>'US', 'job_city'=>'Boston', 'job_is_remote'=>false, 'job_apply_link'=>'https://example.test/us'],
    ] : [
        $common + ['job_id'=>'ma-linkedin', 'job_country'=>'MA', 'job_city'=>'Casablanca', 'job_is_remote'=>false, 'job_publisher'=>'LinkedIn', 'job_apply_link'=>'https://www.linkedin.com/jobs/view/test-ma'],
        $common + ['job_id'=>'ma-indeed', 'job_country'=>'MA', 'job_city'=>'Rabat', 'job_is_remote'=>false, 'job_publisher'=>'Indeed', 'job_apply_link'=>'https://ma.indeed.com/viewjob?jk=test-ma'],
    ];
    return Http::response(['data'=>$data]);
}]);
$result = $service->search(array_replace($search, ['limit'=>1]));
check(count($result['data']) === 1 && $result['data'][0]['country_code'] === 'MA', 'country filtering happens before the result limit');
check(count($requests) === 2, 'search continues to the next page when the first page has only foreign jobs');
check($requests[0]['country'] === 'ma' && $requests[0]['language'] === 'fr' && str_contains($requests[0]['query'], 'Casablanca Morocco'), 'JSearch receives explicit Moroccan country, language and location');
check(array_column($result['attempts'], 'provider') === ['jsearch'], 'enabled Arbeitnow and unsupported Adzuna are skipped for Morocco');
$result = $service->search($search);
check(array_column($result['data'], 'source') === ['LinkedIn', 'Indeed'], 'imported jobs retain their actual LinkedIn and Indeed publisher');
$before = count($requests); $service->search($search);
check(count($requests) === $before, 'repeated searches use the location-aware cache');
foreach (['arbeitnow', 'adzuna'] as $unsupported) {
    $result = $service->search($search + ['provider'=>$unsupported]);
    check($result['data'] === [] && $result['attempts'] === [], 'explicit unsupported provider cannot substitute another country: '.$unsupported);
}
check(count($requests) === $before, 'unsupported providers make no API request');
foreach ([
    ['Casablanca', false, true], ['Rabat, Morocco', false, true], ['Fès, Fès-Meknès', false, true],
    ['Rabat, Malta', false, false], ['Berlin, Germany', true, false], ['Europe only', true, false],
    ['United States', true, false], ['Worldwide', true, false], ['Location not specified', true, false],
] as [$location, $remote, $expected]) {
    check(JobLocation::matches(compact('location', 'remote'), $search) === $expected, 'Morocco eligibility: '.$location);
}
check(JobLocation::matches(['location'=>'Worldwide', 'remote'=>true], array_replace($search, ['work_mode'=>'remote'])), 'worldwide eligibility is included only when remote is requested');
check(JobLocation::matches(['location'=>'Paris', 'remote'=>false], ['country'=>'FR']), 'other markets retain provider city-only locations');
check(!JobLocation::matches(['location'=>'Berlin', 'remote'=>false], ['country'=>'ALL']), 'worldwide remote excludes onsite listings');
// Profile geography must not change explicit API search geography.
$user = new App\Models\User;
$user->name = 'Jobs Test'; $user->email = 'jobs@example.test'; $user->role = 'user';
$user->password = Illuminate\Support\Facades\Hash::make('test-password-1234'); $user->verified_at = now(); $user->save();
$cookies = [];
check(api('POST', 'login', ['email'=>$user->email, 'password'=>'test-password-1234'], $cookies)[0] === 200, 'job seeker can sign in');
$result = api('POST', 'jobs/search', $search, $cookies);
check($result[0] === 200 && $result[1]['count'] === 2 && array_unique(array_column($result[1]['data'], 'country_code')) === ['MA'], 'search endpoint returns only Morocco offers');
foreach (['', '&city=Casablanca'] as $city) {
    $result = api('GET', 'jobs/links?q=PHP%20%26%20Laravel&country=MA'.$city, [], $cookies);
    check($result[0] === 200 && parse_url($result[1]['indeed'], PHP_URL_HOST) === 'ma.indeed.com', 'links endpoint uses Indeed Morocco');
    parse_str(parse_url($result[1]['linkedin'], PHP_URL_QUERY), $query);
    check($query['keywords'] === 'PHP & Laravel' && str_contains($query['location'], 'Morocco'), 'LinkedIn endpoint preserves query encoding and Moroccan location');
}
$jsearchStatus = 403;
Cache::flush();
$result = $service->search($search);
check($result['data'] === [] && !$result['attempts'][0]['ok'] && $result['attempts'][0]['status'] === 403 &&
    str_contains($result['message'], 'unavailable'), 'denied provider access is reported separately from an empty job market');
check(!str_contains(json_encode($result), 'test-key'), 'provider failure does not expose API credentials or private response text');
Integration::where('provider', 'jsearch')->update(['enabled'=>false]);
Http::fake(['remotive.com/*'=>Http::response(['jobs'=>[
    ['id'=>1, 'title'=>'Developpeur', 'candidate_required_location'=>'Germany', 'url'=>'https://example.test/german-remote'],
    ['id'=>2, 'title'=>'Developpeur', 'candidate_required_location'=>'Worldwide', 'url'=>'https://example.test/worldwide'],
]]), 'jobicy.com/*'=>Http::response(['jobs'=>[]])]);
$result = $service->search(array_replace($search, ['work_mode'=>'remote', 'pages'=>3]));
check(array_column($result['data'], 'external_id') === ['remotive-2'], 'public remote feeds exclude jobs restricted to Germany');
check(count($result['attempts']) === 2, 'remote-only feeds are queried once each');
echo "All job search regression checks passed.\n";
