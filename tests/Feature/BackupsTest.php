<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BackupService;
use App\Services\UserService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Módulo de copias de seguridad: permisos, retención (máx. 10),
 * configuración de directorio, descarga/restauración y auditoría de usuarios.
 * El dump real (MySQL) se sustituye por un volcado falso en los tests.
 */
class BackupsTest extends TestCase
{
    use RefreshDatabase;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'backups_test_'.bin2hex(random_bytes(4));
        mkdir($this->tmp, 0755, true);

        $service = new FakeBackupService($this->tmp);
        $service->setDirectory($this->tmp); // config.json dentro del temporal
        $this->app->instance(BackupService::class, $service);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmp)) {
            foreach (array_diff(scandir($this->tmp), ['.', '..']) as $f) {
                @unlink($this->tmp.DIRECTORY_SEPARATOR.$f);
            }
            @rmdir($this->tmp);
        }
        parent::tearDown();
    }

    private function userWithRole(string $role): User
    {
        return app(UserService::class)->createWithPosition([
            'username' => strtolower($role).'_u', 'password' => 'password', 'role' => $role,
            'title' => 'LIC', 'firstName' => 'A', 'lastName' => 'U', 'position' => 'C', 'department' => 'D',
        ]);
    }

    // ------------------------------------------------------------------
    // Permisos
    // ------------------------------------------------------------------

    public function test_solo_admin_accede_al_modulo(): void
    {
        // sin autenticar → 401 (ANTES de actingAs, que persiste en la prueba)
        $this->getJson('/api/backups')->assertStatus(401);

        $op = $this->userWithRole('OPERATOR_INGRESO');

        $this->actingAs($op, 'sanctum')->getJson('/api/backups')->assertStatus(403);
        $this->actingAs($op, 'sanctum')->postJson('/api/backups')->assertStatus(403);
        $this->actingAs($op, 'sanctum')->postJson('/api/backups/restore', ['file' => 'x.sql'])->assertStatus(403);
    }

    // ------------------------------------------------------------------
    // Crear + retención de 10 + auditoría
    // ------------------------------------------------------------------

    public function test_crear_copias_retencion_maximo_10_y_log_de_creacion(): void
    {
        $admin = $this->userWithRole('ADMIN');
        $auth = fn () => $this->actingAs($admin, 'sanctum');

        for ($i = 0; $i < 11; $i++) {
            $auth()->postJson('/api/backups')->assertCreated();
        }

        // Retención: quedan exactamente 10 archivos y 10 entradas de log
        $files = array_values(array_filter(scandir($this->tmp), fn ($f) => str_ends_with($f, '.sql')));
        $this->assertCount(10, $files);

        $log = json_decode(file_get_contents($this->tmp.DIRECTORY_SEPARATOR.'backup-log.json'), true);
        $this->assertCount(10, $log);

        // La lista fusiona la auditoría: creada por el admin
        $data = $auth()->getJson('/api/backups')->assertOk()->json('data');
        $this->assertCount(10, $data['backups']);
        $this->assertSame(10, $data['max']);
        $this->assertSame($this->tmp, $data['directory']);
        foreach ($data['backups'] as $b) {
            $this->assertSame($admin->username, $b['createdBy']['username']);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $b['date']);
            $this->assertGreaterThan(0, $b['size']);
        }

        // Se eliminó la más antigua: los 10 archivos = las 10 entradas de la lista
        $names = array_column($data['backups'], 'name'); // la API lista más reciente primero
        sort($names, SORT_STRING); // scandir → ascendente
        $this->assertSame($files, $names);
    }

    // ------------------------------------------------------------------
    // Configuración del directorio
    // ------------------------------------------------------------------

    public function test_configuracion_de_directorio(): void
    {
        $admin = $this->userWithRole('ADMIN');
        $auth = fn () => $this->actingAs($admin, 'sanctum');

        // vacío → 400
        $auth()->postJson('/api/backups/config', ['directory' => '', 'max' => 10])->assertStatus(400);

        // dentro de public/ → 400
        $auth()->postJson('/api/backups/config', ['directory' => base_path('public/backups-x'), 'max' => 10])->assertStatus(400);
        $this->assertDirectoryDoesNotExist(base_path('public/backups-x'));

        // directorio válido → persiste en el JSON y lo usa la lista (junto al máximo)
        $other = $this->tmp.'_b';
        $auth()->postJson('/api/backups/config', ['directory' => $other, 'max' => 5])->assertOk()
            ->assertJsonPath('data.directory', $other)
            ->assertJsonPath('data.max', 5);
        $auth()->getJson('/api/backups')->assertOk()
            ->assertJsonPath('data.directory', $other)
            ->assertJsonPath('data.max', 5)
            ->assertJsonPath('data.limits.min', 1)
            ->assertJsonPath('data.limits.max', 100);

        $persisted = json_decode(file_get_contents($this->tmp.DIRECTORY_SEPARATOR.'backup-config.json'), true);
        $this->assertSame($other, $persisted['directory']);
        $this->assertSame(5, $persisted['max']);
        @rmdir($other);
    }

    // ------------------------------------------------------------------
    // Configuración del número máximo de copias
    // ------------------------------------------------------------------

    public function test_configuracion_numero_maximo_de_copias(): void
    {
        $admin = $this->userWithRole('ADMIN');
        $auth = fn () => $this->actingAs($admin, 'sanctum');

        // fuera de rango / no numérico → 400
        $auth()->postJson('/api/backups/config', ['directory' => $this->tmp, 'max' => 0])->assertStatus(400);
        $auth()->postJson('/api/backups/config', ['directory' => $this->tmp, 'max' => 101])->assertStatus(400);
        $auth()->postJson('/api/backups/config', ['directory' => $this->tmp, 'max' => 'abc'])->assertStatus(400);

        // guardar max=3 → lo devuelve y lo persiste
        $auth()->postJson('/api/backups/config', ['directory' => $this->tmp, 'max' => 3])
            ->assertOk()->assertJsonPath('data.max', 3);
        $auth()->getJson('/api/backups')->assertOk()->assertJsonPath('data.max', 3);

        // 5 creaciones → la retención conserva solo 3 (las más recientes)
        for ($i = 0; $i < 5; $i++) {
            $auth()->postJson('/api/backups')->assertCreated();
        }
        $files = array_values(array_filter(scandir($this->tmp), fn ($f) => str_ends_with($f, '.sql')));
        $this->assertCount(3, $files);

        $log = json_decode(file_get_contents($this->tmp.DIRECTORY_SEPARATOR.'backup-log.json'), true);
        $this->assertCount(3, $log);

        $data = $auth()->getJson('/api/backups')->assertOk()->json('data');
        $this->assertCount(3, $data['backups']);
        $this->assertSame(3, $data['max']);
    }

    // ------------------------------------------------------------------
    // Explorador de carpetas del servidor
    // ------------------------------------------------------------------

    public function test_explorador_de_directorios(): void
    {
        $admin = $this->userWithRole('ADMIN');
        $auth = fn () => $this->actingAs($admin, 'sanctum');

        mkdir($this->tmp.DIRECTORY_SEPARATOR.'alfa');
        mkdir($this->tmp.DIRECTORY_SEPARATOR.'beta');
        file_put_contents($this->tmp.DIRECTORY_SEPARATOR.'no-es-carpeta.txt', 'x');

        // sin path → parte del directorio configurado
        $data = $auth()->getJson('/api/backups/directories')->assertOk()->json('data');
        $this->assertSame($this->tmp, $data['path']);
        $this->assertTrue($data['writable']);
        $this->assertIsArray($data['drives']);

        // con path → lista solo carpetas, ordenadas, y ofrece el contenedor
        $data = $auth()->getJson('/api/backups/directories?path='.urlencode($this->tmp))->assertOk()->json('data');
        $this->assertSame(['alfa', 'beta'], array_column($data['directories'], 'name'));
        $this->assertNotNull($data['parent']);

        // ruta inexistente → 404
        $auth()->getJson('/api/backups/directories?path='.urlencode($this->tmp.DIRECTORY_SEPARATOR.'nope'))
            ->assertStatus(404);

        // crear carpeta → 201 con su ruta y se crea de verdad
        $expected = $this->tmp.DIRECTORY_SEPARATOR.'respaldos-2026';
        $auth()->postJson('/api/backups/directories', ['path' => $this->tmp, 'name' => 'respaldos-2026'])
            ->assertCreated()->assertJsonPath('data', $expected);
        $this->assertDirectoryExists($expected);

        // nombres inválidos / duplicada → 400
        $auth()->postJson('/api/backups/directories', ['path' => $this->tmp, 'name' => 'a/b'])->assertStatus(400);
        $auth()->postJson('/api/backups/directories', ['path' => $this->tmp, 'name' => '..'])->assertStatus(400);
        $auth()->postJson('/api/backups/directories', ['path' => $this->tmp, 'name' => 'alfa'])->assertStatus(400);

        // no ADMIN → 403
        $op = $this->userWithRole('OPERATOR_INGRESO');
        $this->actingAs($op, 'sanctum')->getJson('/api/backups/directories')->assertStatus(403);
    }

    // ------------------------------------------------------------------
    // Descarga + auditoría
    // ------------------------------------------------------------------

    public function test_descarga_registra_al_usuario(): void
    {
        $admin = $this->userWithRole('ADMIN');
        $auth = fn () => $this->actingAs($admin, 'sanctum');

        $name = $auth()->postJson('/api/backups')->assertCreated()->json('data.name');

        $auth()->getJson('/api/backups/download?file='.urlencode($name))
            ->assertOk()
            ->assertDownload($name);

        $b = $auth()->getJson('/api/backups')->assertOk()->json('data.backups.0');
        $this->assertSame(1, $b['downloadCount']);
        $this->assertSame($admin->username, $b['lastDownload']['username']);

        // nombre inválido (path traversal) → 400 · inexistente → 404
        $auth()->getJson('/api/backups/download?file='.urlencode('../.env'))->assertStatus(400);
        $auth()->getJson('/api/backups/download?file=backup_2000-01-01_000000_000000.sql')->assertStatus(404);
    }

    // ------------------------------------------------------------------
    // Restaurar copia existente
    // ------------------------------------------------------------------

    public function test_restaurar_copia_existente_reemplaza_datos_y_registra(): void
    {
        $admin = $this->userWithRole('ADMIN');
        $auth = fn () => $this->actingAs($admin, 'sanctum');

        $name = 'backup_2026-01-02_030405_000001.sql';
        file_put_contents(
            $this->tmp.DIRECTORY_SEPARATOR.$name,
            "CREATE TABLE bak_restore (id INTEGER);\nINSERT INTO bak_restore VALUES (42);\n"
        );

        $auth()->postJson('/api/backups/restore', ['file' => $name])->assertOk();
        $this->assertSame(42, DB::table('bak_restore')->value('id'));

        $b = $auth()->getJson('/api/backups')->assertOk()->json('data.backups.0');
        $this->assertSame(1, $b['restoreCount']);
        $this->assertSame($admin->username, $b['lastRestore']['username']);
        $this->assertSame('server', $b['lastRestore']['source']);

        // copia inexistente → 404
        $auth()->postJson('/api/backups/restore', ['file' => 'backup_2000-01-01_000000_000000.sql'])->assertStatus(404);
    }

    // ------------------------------------------------------------------
    // Restaurar archivo subido
    // ------------------------------------------------------------------

    public function test_restaurar_archivo_subido(): void
    {
        $admin = $this->userWithRole('ADMIN');
        $auth = fn () => $this->actingAs($admin, 'sanctum');

        $upload = fn (string $content, string $name) => new UploadedFile(
            $this->writeTmp($name, $content), $name, 'text/plain', null, true
        );

        // extensión incorrecta → 400
        $auth()->post('/api/backups/restore-upload', ['file' => $upload('hola', 'notas.txt')])
            ->assertStatus(400);

        // .sql que no parece volcado → 400
        $auth()->post('/api/backups/restore-upload', ['file' => $upload('hola mundo', 'junk.sql')])
            ->assertStatus(400);

        // .sql válido → restaura y registra con source=upload
        $sql = "CREATE TABLE bak_upload (id INTEGER);\nINSERT INTO bak_upload VALUES (7);\n";
        $auth()->post('/api/backups/restore-upload', ['file' => $upload($sql, 'dump.sql')])->assertOk();
        $this->assertSame(7, DB::table('bak_upload')->value('id'));

        $data = $auth()->getJson('/api/backups')->assertOk()->json('data');
        $this->assertCount(1, $data['uploadRestores']);
        $this->assertSame($admin->username, $data['uploadRestores'][0]['username']);
        $this->assertSame('upload', $data['uploadRestores'][0]['source']);
    }

    // ------------------------------------------------------------------
    // Entorno compartido (Desarrollo/Producción + BD) en login
    // ------------------------------------------------------------------

    public function test_login_comparte_entorno_y_base_de_datos(): void
    {
        $this->get('/login')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Login')
            ->has('env.name')
            ->has('env.db')
            ->where('env.isProduction', false));
    }

    // ------------------------------------------------------------------

    private function writeTmp(string $name, string $content): string
    {
        $path = $this->tmp.DIRECTORY_SEPARATOR.'upload_'.$name;
        file_put_contents($path, $content);

        return $path;
    }
}

/**
 * Sustituye el volcado MySQL por uno falso y aísla config/log en el temporal.
 * (setDirectory escribe en configPath() y directory() lo lee de ahí.)
 */
class FakeBackupService extends BackupService
{
    public function __construct(private readonly string $base)
    {
    }

    protected function configPath(): string
    {
        return $this->base.DIRECTORY_SEPARATOR.'backup-config.json';
    }

    protected function logPath(): string
    {
        return $this->base.DIRECTORY_SEPARATOR.'backup-log.json';
    }

    protected function dumpDatabase(string $path): void
    {
        file_put_contents($path, "-- volcado de prueba\nCREATE TABLE IF NOT EXISTS bak_demo (id INTEGER);\nINSERT INTO bak_demo VALUES (1);\n");
    }
}
