<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Ifsnop\Mysqldump\Mysqldump;
use InvalidArgumentException;
use RuntimeException;

/**
 * Copias de seguridad de la base de datos (contrato del módulo de backups):
 * - Directorio y número de copias configurables por el ADMIN
 *   → storage/app/backup-config.json (sobrevive a restauraciones de la BD)
 * - Retención: al superar el máximo configurado se elimina la más antigua
 * - Auditoría en storage/app/backup-log.json: quién creó / descargó / restauró
 *   (el log vive en un archivo para SOBREVIVIR a las restauraciones de la BD)
 */
class BackupService
{
    /** Número de copias configurable por el ADMIN (entre MIN y MAX); por defecto 10. */
    public const MIN_BACKUPS = 1;
    public const MAX_BACKUPS = 100;
    public const DEFAULT_BACKUPS = 10;

    /** Clave del log para restauraciones desde archivo subido (no ligadas a una copia). */
    public const UPLOAD_KEY = '__upload__';

    private const FILE_PATTERN = '/^backup_\d{4}-\d{2}-\d{2}_\d{6}_\d{6}\.sql$/';

    /** Máximo de subcarpetas devueltas por el explorador. */
    private const EXPLORER_LIMIT = 500;

    // ------------------------------------------------------------------
    // Configuración
    // ------------------------------------------------------------------

    protected function configPath(): string
    {
        return storage_path('app/backup-config.json');
    }

    protected function logPath(): string
    {
        return storage_path('app/backup-log.json');
    }

    /** Directorio configurado (por defecto storage/app/backups). */
    public function directory(): string
    {
        $dir = $this->readJson($this->configPath())['directory'] ?? null;

        return is_string($dir) && $dir !== '' ? $dir : storage_path('app/backups');
    }

    /** Número máximo de copias configurado (por defecto 10, entre 1 y 100). */
    public function maxBackups(): int
    {
        $max = $this->readJson($this->configPath())['max'] ?? null;

        return is_numeric($max)
            ? (int) max(self::MIN_BACKUPS, min(self::MAX_BACKUPS, (int) $max))
            : self::DEFAULT_BACKUPS;
    }

    /**
     * Guarda directorio + número máximo de copias en la configuración.
     * El directorio debe poder crearse/ser escribible y no estar en public/.
     * Si el nuevo máximo es menor que las copias existentes, aplica la
     * retención de inmediato (borra las más antiguas que excedan).
     *
     * @return array{directory: string, max: int}
     */
    public function configure(string $directory, int $max): array
    {
        $directory = $this->validateDirectory($directory);

        if ($max < self::MIN_BACKUPS || $max > self::MAX_BACKUPS) {
            throw new InvalidArgumentException(sprintf(
                'El número de copias debe estar entre %d y %d.',
                self::MIN_BACKUPS,
                self::MAX_BACKUPS
            ));
        }

        $this->writeJson($this->configPath(), ['directory' => $directory, 'max' => $max]);

        if (is_dir($directory)) {
            $this->prune($directory);
        }

        return ['directory' => $directory, 'max' => $max];
    }

    /** Guarda solo el directorio, manteniendo el máximo actual (uso interno/tests). */
    public function setDirectory(string $directory): string
    {
        return $this->configure($directory, $this->maxBackups())['directory'];
    }

    /**
     * Valida/normaliza el directorio de copias: no vacío, no dentro de public/,
     * creable y escribible.
     */
    private function validateDirectory(string $directory): string
    {
        $directory = trim($directory);
        if ($directory === '') {
            throw new InvalidArgumentException('El directorio no puede estar vacío.');
        }

        // Relativo → relativo a la raíz del proyecto
        if (! preg_match('#^([a-zA-Z]:[\\\\/]|/)#', $directory)) {
            $directory = base_path($directory);
        }
        // Normalizar separadores (base_path puede mezclar / y \ en Windows)
        $alt = DIRECTORY_SEPARATOR === '\\' ? '/' : '\\';
        $directory = rtrim(str_replace($alt, DIRECTORY_SEPARATOR, $directory), DIRECTORY_SEPARATOR);

        // Prohibido dentro de public/ (los .sql quedarían servibles por el web server)
        $public = rtrim(str_replace($alt, DIRECTORY_SEPARATOR, base_path('public')), DIRECTORY_SEPARATOR);
        if ($directory === $public || str_starts_with($directory, $public.DIRECTORY_SEPARATOR)) {
            throw new InvalidArgumentException('El directorio no puede estar dentro de public/.');
        }

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true)) {
            throw new InvalidArgumentException('No se pudo crear el directorio. Verifique la ruta y los permisos.');
        }
        if (! is_writable($directory)) {
            throw new InvalidArgumentException('El directorio no tiene permisos de escritura.');
        }

        return $directory;
    }

    // ------------------------------------------------------------------
    // Crear / listar / descargar
    // ------------------------------------------------------------------

    /** Genera el volcado, aplica la retención y registra al usuario. */
    public function create(User $user): array
    {
        $dir = $this->directory();
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true)) {
            throw new RuntimeException('No se pudo crear el directorio de copias.');
        }

        $name = $this->uniqueName($dir);
        $this->dumpDatabase($dir.DIRECTORY_SEPARATOR.$name);

        if (! file_exists($dir.DIRECTORY_SEPARATOR.$name) || filesize($dir.DIRECTORY_SEPARATOR.$name) === 0) {
            @unlink($dir.DIRECTORY_SEPARATOR.$name);
            throw new RuntimeException('El volcado quedó vacío. Verifique la conexión a la base de datos.');
        }

        $this->prune($dir);
        $this->logCreate($name, $user);

        return $this->entry($name, $dir);
    }

    /** Lista las copias (más reciente primero) con auditoría fusionada. */
    public function list(): array
    {
        $dir = $this->directory();
        $log = $this->readJson($this->logPath());

        $entries = [];
        foreach ($this->files($dir) as $name) {
            $entries[] = $this->entry($name, $dir, $log[$name] ?? []);
        }

        // Restauraciones desde archivo subido (no ligadas a una copia)
        $uploads = array_map(
            fn ($r) => $r,
            $log[self::UPLOAD_KEY]['restores'] ?? []
        );

        return ['backups' => $entries, 'uploadRestores' => array_reverse($uploads)];
    }

    /** Ruta absoluta de una copia validando el nombre (anti path-traversal). */
    public function path(string $file): string
    {
        if (! preg_match(self::FILE_PATTERN, $file)) {
            throw new InvalidArgumentException('Nombre de copia inválido.');
        }

        $path = $this->directory().DIRECTORY_SEPARATOR.$file;
        if (! is_file($path)) {
            throw new RuntimeException('La copia indicada no existe.');
        }

        return $path;
    }

    /** Copia existente → registrar descarga y devolver la ruta. */
    public function download(string $file, User $user): string
    {
        $path = $this->path($file);
        $this->logAppend($file, 'downloads', $user);

        return $path;
    }

    // ------------------------------------------------------------------
    // Restaurar
    // ------------------------------------------------------------------

    /** Restaura una copia existente del servidor. */
    public function restore(string $file, User $user): void
    {
        $path = $this->path($file);
        $sql = file_get_contents($path);
        if ($sql === false || trim($sql) === '') {
            throw new RuntimeException('No se pudo leer la copia.');
        }

        $this->executeSql($sql);
        $this->logAppend($file, 'restores', $user, 'server');
    }

    /** Restaura un .sql subido por el usuario (archivo descargado previamente). */
    public function restoreUpload(UploadedFile $file, User $user): void
    {
        if (strtolower($file->getClientOriginalExtension()) !== 'sql') {
            throw new InvalidArgumentException('El archivo debe tener extensión .sql.');
        }

        $sql = file_get_contents($file->getRealPath());
        if ($sql === false || ! $this->looksLikeDump($sql)) {
            throw new InvalidArgumentException('El archivo no parece un volcado válido de la base de datos.');
        }

        $this->executeSql($sql);
        $this->logAppend(self::UPLOAD_KEY, 'restores', $user, 'upload');
    }

    // ------------------------------------------------------------------
    // Explorador de directorios (selector de destino)
    // ------------------------------------------------------------------

    /**
     * Subcarpetas de una ruta para el explorador del frontend.
     * Sin ruta → parte del directorio configurado actualmente.
     *
     * @return array{path: string, parent: ?string, writable: bool, directories: array, truncated: bool, total: int, drives: array}
     */
    public function directories(?string $path): array
    {
        if ($path === null || trim($path) === '') {
            $path = $this->directory();
            if (! is_dir($path)) {
                $path = $this->roots()[0] ?? DIRECTORY_SEPARATOR;
            }
        }

        $path = $this->normalizePath($path);
        if (! is_dir($path)) {
            throw new RuntimeException('La ruta indicada no existe o no es un directorio.');
        }

        $directories = [];
        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path.DIRECTORY_SEPARATOR.$item;
            if (is_dir($full)) {
                $directories[] = ['name' => $item, 'path' => $full];
            }
        }
        usort($directories, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        $total = count($directories);

        return [
            'path' => $path,
            'parent' => $this->parentPath($path),
            'writable' => is_writable($path),
            'directories' => array_slice($directories, 0, self::EXPLORER_LIMIT),
            'truncated' => $total > self::EXPLORER_LIMIT,
            'total' => $total,
            'drives' => $this->roots(),
        ];
    }

    /** Crea una subcarpeta dentro de $path y devuelve su ruta completa. */
    public function createDirectory(string $path, string $name): string
    {
        $path = $this->normalizePath($path);
        if (! is_dir($path)) {
            throw new RuntimeException('La ruta indicada no existe o no es un directorio.');
        }

        $name = trim($name);
        if ($name === '' || $name === '.' || $name === '..'
            || ! preg_match('/^[^\\\\\/:*?"<>|]+$/u', $name)) {
            throw new InvalidArgumentException('Nombre de carpeta inválido.');
        }
        if (! is_writable($path)) {
            throw new InvalidArgumentException('El directorio no tiene permisos de escritura.');
        }

        $full = $path.DIRECTORY_SEPARATOR.$name;
        if (file_exists($full)) {
            throw new InvalidArgumentException('Ya existe una carpeta con ese nombre.');
        }
        if (! @mkdir($full)) {
            throw new RuntimeException('No se pudo crear la carpeta.');
        }

        return $full;
    }

    /** Normaliza separadores y conserva la barra de raíz (C:\ o /). */
    private function normalizePath(string $path): string
    {
        $alt = DIRECTORY_SEPARATOR === '\\' ? '/' : '\\';
        $path = rtrim(str_replace($alt, DIRECTORY_SEPARATOR, trim($path)), DIRECTORY_SEPARATOR);

        if ($path === '') {
            return DIRECTORY_SEPARATOR;
        }
        if (preg_match('/^[A-Za-z]:$/', $path)) {
            return $path.DIRECTORY_SEPARATOR;
        }

        return $path;
    }

    /** Directorio contenedor; null en la raíz (C:\ o /). */
    private function parentPath(string $path): ?string
    {
        $parent = dirname($path);

        return ($parent === '' || $parent === $path) ? null : $parent;
    }

    /** Puntos de partida del explorador: unidades (Windows) o la raíz '/'. */
    private function roots(): array
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return [DIRECTORY_SEPARATOR];
        }

        $drives = [];
        foreach (range('A', 'Z') as $letter) {
            if (@is_dir($letter.':\\')) {
                $drives[] = $letter.':\\';
            }
        }

        return $drives;
    }

    // ------------------------------------------------------------------
    // Internos
    // ------------------------------------------------------------------

    /** Volcado con ifsnop/mysqldump-php (solo MySQL, sin binarios del SO). */
    protected function dumpDatabase(string $path): void
    {
        $name = config('database.default');
        $cfg = config("database.connections.{$name}") ?? [];

        if (($cfg['driver'] ?? '') !== 'mysql') {
            throw new RuntimeException('El volcado solo está soportado para conexiones MySQL.');
        }

        $base = sprintf(
            'dbname=%s;charset=%s',
            $cfg['database'],
            $cfg['charset'] ?? 'utf8mb4'
        );
        if (! empty($cfg['unix_socket'])) {
            $dsn = 'mysql:unix_socket='.$cfg['unix_socket'].';'.$base;
        } else {
            $dsn = 'mysql:host='.($cfg['host'] ?? '127.0.0.1').';'.$base;
            if (! empty($cfg['port'])) {
                $dsn .= ';port='.$cfg['port'];
            }
        }

        (new Mysqldump($dsn, $cfg['username'] ?? '', $cfg['password'] ?? '', [
            'compress' => Mysqldump::NONE,
            'add-drop-table' => true,
        ]))->start($path);
    }

    /**
     * Ejecuta el SQL del volcado sentencia por sentencia (multi-sentencia)
     * contra la conexión activa. En MySQL desactiva FK checks durante la carga.
     */
    protected function executeSql(string $sql): void
    {
        $isMysql = config('database.connections.'.config('database.default').'.driver') === 'mysql';
        $pdo = DB::connection()->getPdo();

        if ($isMysql) {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        }
        try {
            foreach ($this->splitStatements($sql) as $statement) {
                $pdo->exec($statement);
            }
        } finally {
            if ($isMysql) {
                $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            }
        }
    }

    /**
     * Divide un script SQL en sentencias respetando comillas (' " `),
     * comentarios (-- #) y comentarios condicionales /*! de MySQL
     * (estos SÍ deben ejecutarse: fijan charset/SET del volcado).
     */
    protected function splitStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $len = strlen($sql);
        $quote = null;

        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];

            if ($quote !== null) {
                $buffer .= $c;
                if ($c === '\\' && $quote !== '`' && $i + 1 < $len) {
                    $buffer .= $sql[++$i];
                } elseif ($c === $quote) {
                    if ($i + 1 < $len && $sql[$i + 1] === $quote) { // comilla duplicada escapada
                        $buffer .= $sql[++$i];
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }

            if ($c === "'" || $c === '"' || $c === '`') {
                $quote = $c;
                $buffer .= $c;
                continue;
            }

            // comentario -- hasta fin de línea (requiere espacio tras los guiones)
            if ($c === '-' && $i + 2 < $len && $sql[$i + 1] === '-' && ctype_space($sql[$i + 2])) {
                while ($i < $len && $sql[$i] !== "\n") {
                    $i++;
                }
                $buffer .= "\n";
                continue;
            }
            if ($c === '#') {
                while ($i < $len && $sql[$i] !== "\n") {
                    $i++;
                }
                $buffer .= "\n";
                continue;
            }
            if ($c === '/' && $i + 1 < $len && $sql[$i + 1] === '*') {
                if ($i + 2 < $len && $sql[$i + 2] === '!') { /*! condicional: se conserva */
                    $buffer .= $c;
                    continue;
                }
                $i += 2;
                while ($i + 1 < $len && ! ($sql[$i] === '*' && $sql[$i + 1] === '/')) {
                    $i++;
                }
                $i++;
                continue;
            }

            if ($c === ';') {
                if (($stmt = trim($buffer)) !== '') {
                    $statements[] = $stmt;
                }
                $buffer = '';
                continue;
            }

            $buffer .= $c;
        }

        if (($tail = trim($buffer)) !== '') {
            $statements[] = $tail;
        }

        return $statements;
    }

    /** Heurística: el subido debe parecer un volcado SQL. */
    private function looksLikeDump(string $sql): bool
    {
        return (bool) preg_match('/\b(CREATE\s+TABLE|INSERT\s+INTO|DROP\s+TABLE)\b/i', $sql);
    }

    /** Elimina las copias que superen el máximo configurado (las más antiguas) y su log. */
    protected function prune(string $dir): void
    {
        foreach (array_slice($this->files($dir), $this->maxBackups()) as $old) {
            @unlink($dir.DIRECTORY_SEPARATOR.$old);
            $this->removeLogEntry($old);
        }
    }

    /** Nombres .sql ordenados de más reciente a más antiguo. */
    private function files(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }
        $names = array_values(array_filter(scandir($dir) ?: [], fn ($f) => preg_match(self::FILE_PATTERN, $f)));
        rsort($names, SORT_STRING);

        return $names;
    }

    /**
     * Nombre único SIEMPRE con sufijo numérico de 6 dígitos (compatible con
     * FILE_PATTERN; si no lo fuera, prune() y files() no verían las copias).
     * Nota: date('u') devuelve 000000 en algunos builds, se usa microtime.
     */
    private function uniqueName(string $dir): string
    {
        $base = 'backup_'.date('Y-m-d_His').'_';

        for ($try = 0; $try < 25; $try++) {
            $micro = $try < 20
                ? (int) ((microtime(true) * 1000000) % 1000000)
                : random_int(0, 999999);
            $name = $base.sprintf('%06d', $micro).'.sql';
            if (! file_exists($dir.DIRECTORY_SEPARATOR.$name)) {
                return $name;
            }
            usleep(500);
        }

        throw new RuntimeException('No se pudo asignar un nombre único para la copia.');
    }

    /** Entrada de la lista: metadatos del archivo + auditoría del log. */
    private function entry(string $name, string $dir, ?array $log = null): array
    {
        $log ??= $this->readJson($this->logPath())[$name] ?? [];
        $path = $dir.DIRECTORY_SEPARATOR.$name;

        // backup_YYYY-MM-DD_HHMMSS_uuuuuu.sql → 2026-10-20 14:30:55
        $date = '—';
        if (preg_match('/^backup_(\d{4}-\d{2}-\d{2})_(\d{2})(\d{2})(\d{2})_\d{6}\.sql$/', $name, $m)) {
            $date = "{$m[1]} {$m[2]}:{$m[3]}:{$m[4]}";
        }

        $downloads = $log['downloads'] ?? [];
        $restores = $log['restores'] ?? [];

        return [
            'name' => $name,
            'date' => $date,
            'size' => is_file($path) ? filesize($path) : 0,
            'createdBy' => $log['created_by'] ?? null,
            'downloadCount' => count($downloads),
            'lastDownload' => $downloads ? $downloads[array_key_last($downloads)] : null,
            'restoreCount' => count($restores),
            'lastRestore' => $restores ? $restores[array_key_last($restores)] : null,
        ];
    }

    // ------------------------------------------------------------------
    // Log de auditoría (JSON append atómico con LOCK_EX)
    // ------------------------------------------------------------------

    private function logCreate(string $file, User $user): void
    {
        $log = $this->readJson($this->logPath());
        $log[$file] = ['created_by' => $this->actor($user), 'downloads' => [], 'restores' => []];
        $this->writeJson($this->logPath(), $log);
    }

    private function logAppend(string $file, string $key, User $user, ?string $source = null): void
    {
        $log = $this->readJson($this->logPath());
        if (! isset($log[$file]) && $file !== self::UPLOAD_KEY) {
            $log[$file] = ['created_by' => null, 'downloads' => [], 'restores' => []];
        }
        if ($file === self::UPLOAD_KEY && ! isset($log[$file]['restores'])) {
            $log[$file] = ['restores' => []];
        }

        $entry = $this->actor($user);
        if ($source !== null) {
            $entry['source'] = $source;
        }
        $log[$file][$key][] = $entry;

        $this->writeJson($this->logPath(), $log);
    }

    private function removeLogEntry(string $file): void
    {
        $log = $this->readJson($this->logPath());
        if (isset($log[$file])) {
            unset($log[$file]);
            $this->writeJson($this->logPath(), $log);
        }
    }

    private function actor(User $user): array
    {
        return [
            'id' => $user->getKey(),
            'username' => $user->username,
            'at' => date('Y-m-d H:i:s'),
        ];
    }

    private function readJson(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : []; // log corrupto → se reconstruye vacío
    }

    private function writeJson(string $path, array $data): void
    {
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
}
