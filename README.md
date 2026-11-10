# Galileo

Galileo es una plataforma de registro operativo de servicios **INGRESO / ENTREGA**:
los operadores registran trámites uno a uno (fecha, tipo, placa/póliza y
observaciones), los jefes ven el dashboard y generan informes oficiales
`.docx`, y los administradores gestionan usuarios, servicios y plantillas.

## Stack

- **Backend:** Laravel 13 (PHP 8.3), Sanctum (tokens API),
  Spatie Permission (roles), PhpOffice/PhpWord (informes).
- **Frontend:** Inertia.js 3 + React 19 + Tailwind CSS 4 + Vite.
- **BD por defecto:** SQLite (`database/database.sqlite`).

## Roles y módulos

| Rol | Rutas | Qué hace |
| --- | ----- | -------- |
| `OPERATOR_INGRESO` / `OPERATOR_ENTREGA` | `/operador/entrada` | Registro individual de trámites (fecha, tipo, placa/póliza, observaciones; edición propia) |
| `ADMIN` | `/admin/usuarios`, `/admin/servicios` | CRUD usuarios, servicios y reseteo de claves |
| `JEFE` | `/jefe/dashboard`, `/jefe/reportes`, `/jefe/reportes/historial` | Agregados día/semana/rango, informes `.docx` con plantillas y gráficos + sección total diario |
| `ADMIN`/`JEFE` | `/jefe/plantillas` | Módulo de plantillas: subir/inspeccionar (marcadores), guía de datos, vista de ejemplo, edición de textos fijos, duplicar |
| `JEFE` | informe semanal (plantilla3 por defecto) | `{TABLA_INGRESO}` = tabla del total diario; `{GRAFICO_DISTRIBUCION_TRAMITE}`, `{GRAFICO_TOTAL_TRAMITE}`, `{GRAFICO_TENDENCIA_DIA}` = gráficos del dashboard en alta resolución |

API bajo `/api`: `auth/*`, `users/*` (ADMIN), `services/*`,
`tramites/*` (registro de trámites; lectura `ADMIN|JEFE` vía `totals/summary/*`),
`templates/*` (incluye `inspect`, `paragraphs`, `duplicate`, `download`), `reports/*`.
La tabla `entries` se conserva como histórico no contabilizado.

## Puesta en marcha

```bash
composer setup     # install + .env + key + migrate + build
composer dev       # serve + queue + logs + vite en paralelo
```

En este equipo la URL oficial es `https://splaadm23p-10:8084`. El puerto 443
redirige ahí para no tener dos orígenes distintos. Recuerda incluir el puerto en
`APP_URL`.

## Producción

Usa `.env.production.example` como plantilla (copiar a `.env` en el servidor;
nunca versionar el `.env`).

Puntos clave:

- `APP_ENV=production`, `APP_DEBUG=false` y `APP_URL` con el dominio/puerto real.
- Generar una `APP_KEY` propia en el servidor con `php artisan key:generate`
  (no reutilizar la de desarrollo).
- MySQL con un usuario dedicado (no `root`) y contraseña fuerte.
- `SESSION_SECURE_COOKIE=true` solo cuando el sitio se sirva por HTTPS.
- Apache: `DocumentRoot` apuntando a `public/`, `mod_rewrite` activo y
  `AllowOverride All`.
- `storage/` y `bootstrap/cache/` deben ser escribibles por el usuario del
  servicio web.

Despliegue:

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan optimize
```

- `public/build` está en `.gitignore`: hay que generarlo en el servidor o
  copiarlo como artefacto de build.
- Mantenimiento: `php artisan down --retry=60` antes del despliegue y
  `php artisan up` al terminar.
- Si se usan colas, `QUEUE_CONNECTION=database` y un worker `queue:work`
  gestionado como servicio (NSSM o Programador de tareas).
- Verificar `/up`, login, generación de informes y las páginas de error
  (`404`, `500` y `php artisan down`).

### Servidor interno (este equipo)

- Base de datos: `plataforma-pro`, usuario MySQL `galileo_app` (solo permisos
  sobre esa base).
- URL pública: `https://splaadm23p-10:8084` (URL oficial). El puerto 443 solo
  responde con un `301` hacia ella para que no haya dos orígenes; el 8084 habla
  únicamente HTTPS.
- Respaldo del entorno de desarrollo anterior: `.env.backup` (ignorado por git).
- Respaldos de BD y `storage/app/private` en `C:\wamp64\backups\`.

### HTTPS interno (mkcert)

El certificado lo emite una CA local de [mkcert](https://github.com/FiloSottile/mkcert):

- Certificado: `C:\wamp64\bin\apache\apache2.4.65\conf\ssl\galileo.crt`
  (clave privada `galileo.key`), con SAN para `splaadm23p-10`,
  `splaadm23p-10.gmsantacruz.gov.bo`, `localhost`, `10.80.2.230`,
  `172.16.0.2`, `127.0.0.1` y `::1`.
- Vhost principal: `conf\extra\httpd-vhosts.conf` (`*:8084`), el que sirve la
  aplicación. `conf\extra\httpd-ssl-galileo.conf` (`*:443`) solo redirige.
- CA local para distribuir: `C:\wamp64\backups\mkcert-rootCA.pem`
  (copia lista para instalar: `C:\wamp64\backups\mkcert-rootCA.crt`).

En cada PC cliente, instalar la CA una sola vez (sin esto el navegador muestra
advertencia de certificado). Se puede descargar desde el propio servidor:

```
https://splaadm23p-10:8084/mkcert-rootCA.crt
```

El primer acceso muestra la advertencia de certificado (es justo el que falta
instalar): *Avanzado* → *Continuar a splaadm23p-10 (no seguro)*. Chrome puede
pedir una confirmación extra para descargar desde un sitio no confiable; si lo
bloquea, usar Edge o copiar el archivo por USB desde
`C:\wamp64\backups\mkcert-rootCA.crt`.

Luego doble clic → *Instalar certificado* → *Equipo local* → *Entidades de
certificación raíz de confianza*. O por PowerShell como administrador:

```powershell
certutil -addstore -f Root mkcert-rootCA.crt
```

El archivo se sirve como estático desde `public/mkcert-rootCA.crt` (ignorado por
git). Solo se publica el certificado público; la clave `rootCA-key.pem` nunca
sale del servidor.

La CA no cambia al regenerar el certificado del servidor, así que solo se
instala una vez en cada equipo.

La aplicación envía `Strict-Transport-Security` en respuestas HTTPS, de modo
que el navegador convierte solo `http://splaadm23p-10:8084` en
`https://splaadm23p-10:8084`.

El certificado caduca el **10/02/2029**. Para renovarlo:

```powershell
mkcert -cert-file C:\wamp64\bin\apache\apache2.4.65\conf\ssl\galileo.crt `
       -key-file  C:\wamp64\bin\apache\apache2.4.65\conf\ssl\galileo.key `
       splaadm23p-10 splaadm23p-10.gmsantacruz.gov.bo 10.80.2.230 `
       172.16.0.2 localhost 127.0.0.1 ::1
Restart-Service wampapache64
```

HSTS está desactivado por ahora (ver `app/Http/Middleware/SecurityHeaders.php`):
se activará cuando todos los equipos confíen en la CA y usen la URL sin puerto.

## Datos de prueba

```bash
# Seed completo (wipe → roles → usuarios → servicios → plantillas → trámites demo)
php artisan platform:seed --force

# Trámites demo (mes actual → hoy):
php artisan platform:totals-seed --force
php artisan platform:totals-seed --from=2026-09-01 --to=2026-09-18 --force

# Solo histórico `entries` (no cuenta en dashboard/reportes):
php artisan platform:entries-seed --force
```

Usuarios base: `admin`, `chuachumanca`, `yvelazco` (JEFE) y los operadores
`jcamacho`, `jcarreno`, `arodriguez`, `gchoque`, `rsuarez`, `rflores`, `cvargas`.

> ⚠️ **Solo para desarrollo.** Los seeders generan datos demo y, sin
> `--no-wipe`, borran todos los datos. En producción están **bloqueados**:
> requieren `--force` explícito y `UsersSeeder` no crea las cuentas demo.
> Para el primer ADMIN en producción usa `php artisan tinker` o pide a un
> ADMIN que registre el usuario desde la interfaz con su propia contraseña.

## Tests y calidad

```bash
php artisan test
npx prettier --check resources/js  # (si aplica)
```

## Notas

- Día de negocio = medianoche UTC; display en `America/La_Paz`.
- `entries` usa upsert por `usuario × servicio × día`.
- No versionar: `.env`, `vendor/`, `node_modules/`, `public/build/`,
  `database/*.sqlite`, contenido generado en `storage/`.
