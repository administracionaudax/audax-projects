<?php

use App\Domain\Import\WeeklySync\Dump\PostgresWeeklySyncSource;
use App\Domain\Import\WeeklySync\Dump\StorageDownloadFailed;
use App\Domain\Import\WeeklySync\Dump\SupabaseStorage;
use App\Domain\Import\WeeklySync\Dump\WeeklySyncConnector;
use App\Domain\Import\WeeklySync\Dump\WeeklySyncCredentials;
use App\Domain\Import\WeeklySync\Dump\WeeklySyncDumper;
use App\Domain\Import\WeeklySync\Dump\WeeklySyncSource;
use App\Domain\Import\WeeklySync\Dump\WeeklySyncStorage;
use App\Domain\Import\WeeklySync\WeeklySyncDump;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/*
| Volcador de WeeklySync (D-213) con dobles: un origen en memoria con las tablas del volcado de
| ejemplo (tests/fixtures/weeklysync) y un Storage que copia sus ficheros. Nunca se conecta a
| Supabase. El volcado de ejemplo se generó con este mismo volcador: el primer test comprueba que lo
| reproduce byte a byte (si cambia el formato, hay que regenerarlo).
*/

const WS_DUMP_FIXTURE = 'tests/fixtures/weeklysync';

/** Origen en memoria con las filas de una carpeta de tablas. */
function fixtureWeeklySyncSource(?string $directory = null, array $skip = []): WeeklySyncSource
{
    return new class($directory ?? base_path(WS_DUMP_FIXTURE.'/tables'), $skip) implements WeeklySyncSource
    {
        public bool $closed = false;

        public function __construct(private readonly string $directory, private readonly array $skip) {}

        public function has(string $table): bool
        {
            return ! in_array($table, $this->skip, true) && is_file("{$this->directory}/{$table}.json");
        }

        public function rows(string $table): iterable
        {
            return json_decode((string) file_get_contents("{$this->directory}/{$table}.json"), true);
        }

        public function close(): void
        {
            $this->closed = true;
        }
    };
}

/** Storage que copia los ficheros del volcado de ejemplo y anota lo que le piden. */
function fixtureWeeklySyncStorage(): WeeklySyncStorage
{
    return new class(base_path(WS_DUMP_FIXTURE.'/storage')) implements WeeklySyncStorage
    {
        /** @var list<string> */
        public array $requested = [];

        public function __construct(private readonly string $directory) {}

        public function download(string $bucket, string $path, string $target): bool
        {
            $this->requested[] = "{$bucket}/{$path}";
            $from = "{$this->directory}/{$bucket}/{$path}";

            return is_file($from) && copy($from, $target);
        }
    };
}

function weeklySyncTempDir(): string
{
    return storage_path('framework/testing/ws-dump-'.uniqid());
}

/**
 * Fichero de credenciales de prueba con permisos 600.
 */
function weeklySyncCredentialsFile(string $contents, int $mode = 0o600): string
{
    $path = storage_path('framework/testing/ws-cred-'.uniqid().'.env');
    File::ensureDirectoryExists(dirname($path));
    file_put_contents($path, $contents);
    chmod($path, $mode);

    return $path;
}

const WS_SECRET_PASSWORD = 'contraseña-super-secreta-123';
const WS_SECRET_KEY = 'sb_secret_clave-de-servicio-falsa-456';

function weeklySyncCredentialsContents(): string
{
    return "# Credenciales de prueba\n"
        ."WEEKLYSYNC_DB_URL=postgresql://postgres.abc@aws-0-eu-west-3.pooler.supabase.com:5432/postgres\n"
        .'WEEKLYSYNC_DB_PASSWORD="'.WS_SECRET_PASSWORD."\"\n"
        ."export WEEKLYSYNC_URL=https://abc.supabase.co/\n"
        ."WEEKLYSYNC_SERVICE_KEY='".WS_SECRET_KEY."'\n";
}

test('el volcador reproduce el volcado de ejemplo: tablas, ficheros y manifiesto', function () {
    $directory = weeklySyncTempDir();
    $source = fixtureWeeklySyncSource();
    $storage = fixtureWeeklySyncStorage();

    $result = app(WeeklySyncDumper::class)->dump($directory, $source, $storage);

    $fixture = json_decode((string) file_get_contents(base_path(WS_DUMP_FIXTURE.'/manifest.json')), true);
    $manifest = json_decode((string) file_get_contents($directory.'/manifest.json'), true);

    expect($manifest['tables'])->toBe($fixture['tables'])
        ->and($manifest['files'])->toBe($fixture['files'])
        ->and($manifest['missing_files'])->toBe($fixture['missing_files'])
        ->and($manifest['format'])->toBe(WeeklySyncDump::FORMAT)
        ->and($source->closed)->toBeTrue()
        ->and($result->files)->toBe(6)
        ->and($result->missing)->toBe(['audio-submissions/weekly-reports/'.wsDumpId('33333333', 1).'/sections/outro-perdido.mp3'])
        ->and($result->tables['users'])->toBe(6);

    // Solo los ficheros a los que apunta alguna fila.
    expect($storage->requested)->toHaveCount(7);

    foreach (array_keys($fixture['tables']) as $table) {
        expect(file_get_contents("{$directory}/tables/{$table}.json"))->toBe(file_get_contents(base_path(WS_DUMP_FIXTURE."/tables/{$table}.json")));
    }

    // Carpeta y ficheros solo para quien vuelca.
    expect(fileperms($directory) & 0o777)->toBe(0o700)
        ->and(fileperms($directory.'/manifest.json') & 0o777)->toBe(0o600)
        ->and(fileperms($directory.'/tables/users.json') & 0o777)->toBe(0o600);

    // Y el importador lo acepta.
    expect(WeeklySyncDump::open($directory)->rows('weekly_submissions'))->toHaveCount(6);

    File::deleteDirectory($directory);
});

function wsDumpId(string $prefix, int $n): string
{
    return sprintf('%s-0000-4000-8000-%012d', $prefix, $n);
}

test('una tabla que no existe en el origen queda marcada y el importador la lee vacía', function () {
    $directory = weeklySyncTempDir();

    app(WeeklySyncDumper::class)->dump($directory, fixtureWeeklySyncSource(skip: ['help_update_likes']), null);

    $manifest = json_decode((string) file_get_contents($directory.'/manifest.json'), true);
    expect($manifest['tables']['help_update_likes'])->toBe(['rows' => 0, 'missing' => true])
        ->and($manifest['files'])->toBe([])
        // Sin Storage, los ficheros quedan como no descargados.
        ->and($manifest['missing_files'])->toHaveCount(7)
        ->and(WeeklySyncDump::open($directory)->rows('help_update_likes'))->toBe([])
        ->and(WeeklySyncDump::open($directory)->manifestRows('help_update_likes'))->toBeNull();

    File::deleteDirectory($directory);
});

test('el volcador nunca escribe en una carpeta con contenido', function () {
    $directory = weeklySyncTempDir();
    File::ensureDirectoryExists($directory);
    file_put_contents($directory.'/algo.txt', 'x');

    expect(fn () => app(WeeklySyncDumper::class)->dump($directory, fixtureWeeklySyncSource(), null))
        ->toThrow(RuntimeException::class, 'no está vacía');

    File::deleteDirectory($directory);
});

test('rutas del Storage: URLs firmadas, prefijo del bucket y rutas peligrosas', function () {
    expect(WeeklySyncDump::storagePath('weekly-reports/a/b.mp3', 'audio-submissions'))->toBe('weekly-reports/a/b.mp3')
        ->and(WeeklySyncDump::storagePath('audio-submissions/weekly-reports/x.mp3', 'audio-submissions'))->toBe('weekly-reports/x.mp3')
        ->and(WeeklySyncDump::storagePath('https://abc.supabase.co/storage/v1/object/sign/audio-submissions/weekly-reports/x%20y.mp3?token=abc', 'audio-submissions'))->toBe('weekly-reports/x y.mp3')
        ->and(WeeklySyncDump::storagePath('https://otra.web/x.mp3', 'audio-submissions'))->toBeNull()
        ->and(WeeklySyncDump::storagePath('../../etc/passwd', 'audio-submissions'))->toBeNull()
        ->and(WeeklySyncDump::storagePath('a//b.mp3', 'audio-submissions'))->toBeNull()
        ->and(WeeklySyncDump::storagePath('', 'audio-submissions'))->toBeNull()
        ->and(WeeklySyncDump::storagePath(null, 'audio-submissions'))->toBeNull();
});

test('las credenciales se leen del fichero local y nunca se muestran', function () {
    $path = weeklySyncCredentialsFile(weeklySyncCredentialsContents());

    $credentials = WeeklySyncCredentials::load($path);

    expect($credentials->databaseUrl)->toStartWith('postgresql://postgres.abc@')
        ->and($credentials->databasePassword)->toBe(WS_SECRET_PASSWORD)
        ->and($credentials->projectUrl)->toBe('https://abc.supabase.co')
        ->and($credentials->serviceKey)->toBe(WS_SECRET_KEY)
        ->and(print_r($credentials, true))->not->toContain(WS_SECRET_PASSWORD)->not->toContain(WS_SECRET_KEY)->not->toContain('postgres.abc');

    @unlink($path);
});

test('las credenciales tienen que ser solo de quien vuelca y estar completas', function () {
    $open = weeklySyncCredentialsFile(weeklySyncCredentialsContents(), 0o644);
    expect(fn () => WeeklySyncCredentials::load($open))->toThrow(RuntimeException::class, 'chmod 600');

    $noKey = weeklySyncCredentialsFile("WEEKLYSYNC_DB_URL=postgresql://u@h/db\nWEEKLYSYNC_URL=https://abc.supabase.co\n");
    expect(fn () => WeeklySyncCredentials::load($noKey))->toThrow(RuntimeException::class, 'Falta WEEKLYSYNC_SERVICE_KEY')
        ->and(WeeklySyncCredentials::load($noKey, needsStorage: false)->serviceKey)->toBeNull();

    $notPostgres = weeklySyncCredentialsFile("WEEKLYSYNC_DB_URL=mysql://u:p@h/db\n");
    expect(fn () => WeeklySyncCredentials::load($notPostgres, false))->toThrow(RuntimeException::class, 'no es una URL de PostgreSQL');

    expect(fn () => WeeklySyncCredentials::load('/no/existe.env'))->toThrow(RuntimeException::class, 'No existe el fichero de credenciales');

    foreach ([$open, $noKey, $notPostgres] as $path) {
        @unlink($path);
    }
});

test('la URL de la base se convierte en DSN de PDO con TLS y la contraseña aparte', function () {
    $parts = PostgresWeeklySyncSource::parse('postgresql://postgres.abc:p%40ss@aws-0.pooler.supabase.com:6543/postgres');
    expect($parts)->toBe([
        'dsn' => 'pgsql:host=aws-0.pooler.supabase.com;port=6543;dbname=postgres;sslmode=require',
        'user' => 'postgres.abc',
        'password' => 'p@ss',
    ]);

    $parts = PostgresWeeklySyncSource::parse('postgres://lector@db.abc.supabase.co/postgres?sslmode=verify-full', 'otra');
    expect($parts['dsn'])->toBe('pgsql:host=db.abc.supabase.co;port=5432;dbname=postgres;sslmode=verify-full')
        ->and($parts['password'])->toBe('otra');

    try {
        PostgresWeeklySyncSource::parse('postgresql://sin-host-'.WS_SECRET_PASSWORD);
        $this->fail('Debía fallar');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->not->toContain(WS_SECRET_PASSWORD);
    }
});

test('el Storage se descarga con GET, la clave en las cabeceras y las rutas codificadas', function () {
    Sleep::fake();
    Http::fake([
        'abc.supabase.co/storage/v1/object/audio-submissions/weekly-reports/x%20y.mp3' => Http::response('MP3', 200),
        'abc.supabase.co/storage/v1/object/help-content/no-esta.mp4' => Http::response('{"statusCode":"404","error":"not_found","message":"Object not found"}', 400),
        'abc.supabase.co/storage/v1/object/help-content/borrado.mp4' => Http::response('', 404),
        'abc.supabase.co/storage/v1/object/help-content/falla.mp4' => Http::response('boom', 500),
    ]);
    $storage = new SupabaseStorage('https://abc.supabase.co', WS_SECRET_KEY);
    $directory = weeklySyncTempDir();
    File::ensureDirectoryExists($directory);

    expect($storage->download('audio-submissions', 'weekly-reports/x y.mp3', $directory.'/a.mp3'))->toBeTrue()
        ->and(file_get_contents($directory.'/a.mp3'))->toBe('MP3')
        ->and($storage->download('help-content', 'no-esta.mp4', $directory.'/b.mp4'))->toBeFalse()
        ->and($storage->download('help-content', 'borrado.mp4', $directory.'/c.mp4'))->toBeFalse()
        ->and(file_exists($directory.'/b.mp4'))->toBeFalse();

    try {
        $storage->download('help-content', 'falla.mp4', $directory.'/d.mp4');
        $this->fail('Debía fallar');
    } catch (StorageDownloadFailed $e) {
        expect($e->getMessage())->toContain('500')->not->toContain(WS_SECRET_KEY);
    }

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->hasHeader('apikey', WS_SECRET_KEY)
        && $request->hasHeader('Authorization', 'Bearer '.WS_SECRET_KEY));
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');

    File::deleteDirectory($directory);
});

test('el comando vuelca con las credenciales del fichero sin mostrarlas', function () {
    $path = weeklySyncCredentialsFile(weeklySyncCredentialsContents());
    $directory = weeklySyncTempDir();
    $seen = new ArrayObject;

    $this->app->instance(WeeklySyncConnector::class, new class($seen) extends WeeklySyncConnector
    {
        public function __construct(private readonly ArrayObject $seen) {}

        public function source(WeeklySyncCredentials $credentials): WeeklySyncSource
        {
            $this->seen['password'] = $credentials->databasePassword;

            return fixtureWeeklySyncSource();
        }

        public function storage(WeeklySyncCredentials $credentials): ?WeeklySyncStorage
        {
            $this->seen['key'] = $credentials->serviceKey;

            return fixtureWeeklySyncStorage();
        }
    });

    $this->artisan('app:dump-weeklysync', ['directorio' => $directory, '--credenciales' => $path])
        ->expectsOutputToContain('Volcado de WeeklySync (solo lectura)')
        ->expectsOutputToContain('outro-perdido.mp3')
        ->doesntExpectOutputToContain(WS_SECRET_PASSWORD)
        ->doesntExpectOutputToContain(WS_SECRET_KEY)
        ->assertSuccessful();

    expect($seen['password'])->toBe(WS_SECRET_PASSWORD)
        ->and($seen['key'])->toBe(WS_SECRET_KEY)
        ->and(WeeklySyncDump::open($directory)->files())->toHaveCount(6);

    // Con la carpeta ya llena, se niega.
    $this->artisan('app:dump-weeklysync', ['directorio' => $directory, '--credenciales' => $path])
        ->expectsOutputToContain('no está vacía')
        ->assertFailed();

    File::deleteDirectory($directory);
    @unlink($path);
});

test('el comando no se conecta si las credenciales no valen', function () {
    $path = weeklySyncCredentialsFile(weeklySyncCredentialsContents(), 0o640);
    $this->app->instance(WeeklySyncConnector::class, new class extends WeeklySyncConnector
    {
        public function source(WeeklySyncCredentials $credentials): WeeklySyncSource
        {
            throw new LogicException('No debía conectarse');
        }
    });

    $this->artisan('app:dump-weeklysync', ['directorio' => weeklySyncTempDir(), '--credenciales' => $path])
        ->expectsOutputToContain('chmod 600')
        ->doesntExpectOutputToContain(WS_SECRET_KEY)
        ->assertFailed();

    @unlink($path);
});
