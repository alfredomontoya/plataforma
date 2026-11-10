# Auditoría Integral de Seguridad — Plataforma de Trámites

**Proyecto:** `E:\progra\plataforma`
**Stack:** Laravel 13.30.1 · PHP 8.3 · Inertia.js 3 · React 19 · Sanctum · MySQL
**Fecha:** 2026-11-09
**Modalidad:** Auditoría estática y revisión de configuración, **solo lectura**. No se modificó código, configuración, base de datos ni dependencias.
**Alcance:** Backend Laravel, API, capa Inertia/React, autenticación/autorización, archivos, configuración y dependencias.

> Nota: este informe se generó en el directorio de planificación (`C:\Users\user\.opencode\plan`) porque la sesión está en **Plan mode**. Para dejar una copia en la raíz del proyecto (`SECURITY_AUDIT_REPORT.md`) debe cambiarse al agente de implementación.

---

## A. Resumen ejecutivo

### Estado general

La aplicación presenta una **postura de seguridad por encima de la media** para un proyecto Laravel/Inertia: usa consultas Eloquent con bindings, validación mediante Form Requests, autorización por roles en rutas API (`auth:sanctum` + `role:*`), rotación/revocación de tokens, control de acceso por propietario en trámites, plantillas e informes, validación estructural de `.docx` (anti zip-bomb), sanitización de rutas de backup y XML sin entidades externas (anti-XXE).

No se identificaron vulnerabilidades **CRÍTICAS** (RCE, SQLi explotable, bypass total de autenticación, path traversal directo). Sin embargo, existen **dos riesgos ALTOS** y varios **MEDIOS** que deben atenderse antes de una exposición a Internet:

1. El token de API se guarda en `localStorage` y no en una cookie `HttpOnly`, por lo que cualquier XSS permite robar una credencial válida durante 8 horas.
2. Un usuario desactivado conserva sus tokens de API activos: `isActive` solo se valida al iniciar sesión, no en cada petición autenticada.

### Nivel de riesgo global

**MEDIO-ALTO** para un despliegue en producción accesible por Internet. **MEDIO** para una red interna controlada.

### Conteo de hallazgos

| Severidad | Cantidad |
|---|---|
| CRÍTICO | 0 |
| ALTO | 2 |
| MEDIO | 4 |
| BAJO | 8 |
| INFORMATIVO | 4 |
| **Total** | **18** |

### Principales riesgos

- **SEC-001 (ALTO):** token Bearer persistido en `localStorage`; XSS = secuestro de sesión.
- **SEC-003 (ALTO):** tokens de usuarios desactivados siguen siendo aceptados hasta su expiración (480 min).
- **SEC-002 (MEDIO):** componentes Symfony desactualizados con avisos de seguridad (`composer audit`: 7 advisories / 4 paquetes).
- **SEC-004 (MEDIO):** política de contraseñas débil (mínimo 6 caracteres, sin complejidad ni verificación de filtraciones).
- **SEC-005 (MEDIO):** ausencia de cabeceras de seguridad HTTP (CSP, X-Frame-Options, HSTS, nosniff, Referrer-Policy).
- **SEC-006 (MEDIO):** previsualización de `.docx` con `docx-preview`; la sanitización actual solo filtra `href` inseguros.

---

## B. Inventario tecnológico

### Backend

| Componente | Versión / configuración |
|---|---|
| PHP | `^8.3` |
| Laravel Framework | `v13.30.1` |
| Laravel Sanctum | `v4.3.3` (expiración 480 min) |
| spatie/laravel-permission | `8.3.0` (roles ADMIN, JEFE, OPERATOR_INGRESO, OPERATOR_ENTREGA) |
| PhpWord / PhpSpreadsheet / dompdf | `^1.4` / `^5.10` / `^3.1` |
| ifsnop/mysqldump-php | `^2.13` |
| Symfony components | http-foundation `v7.4.21`, http-kernel `v7.4.18`, mime `v7.4.18`, routing `v7.4.18` |
| Base de datos | MySQL (`DB_CONNECTION=mysql`) |
| Sesiones | driver `database`, `secure=true`, `http_only=true`, `same_site=lax`, `encrypt=false` |
| Logs | canal `stack` → `single`, `LOG_LEVEL=debug` |

### Frontend

| Componente | Versión |
|---|---|
| React / React DOM | `^19.2.8` |
| Inertia React | `^3.7.0` |
| Vite | `^7.0.7` (devDependency) |
| docx-preview | `^0.4.0` |
| recharts / lucide-react / heroicons | `^3.10.1` / `^1.41.0` / `^2.2.0` |
| axios | `^1.11.0` |
| Tailwind CSS | `^4.0.0` |

### Arquitectura identificada

- **Monolito Laravel** con API REST bajo `/api/*` y frontend SPA Inertia servido por rutas web.
- Autenticación **Bearer token Sanctum** para la API. Las rutas web de Inertia **no usan guard de sesión** (decisión documentada en `routes/web.php`); la protección real está en la API y en el `RoleGuard` del cliente.
- Autorización por **rol Spatie** a nivel de ruta y validaciones de propiedad en servicios (trámites, plantillas, informes).
- Almacenamiento privado en `storage/app` para plantillas, informes y backups; no se sirven como `public/storage`.

---

## C. Hallazgos detallados

### SEC-001 — Token de API almacenado en `localStorage`

| Campo | Valor |
|---|---|
| Severidad | **ALTO** |
| Tipo | Vulnerabilidad / mala práctica confirmada |
| CVSS 4.0 estimado | `CVSS:4.0/AV:N/AC:L/AT:N/PR:N/UI:P/VC:H/VI:H/VA:N/SC:N/SI:N/SA:N` ≈ **7.1** |
| Archivos | `resources/js/lib/auth.jsx:25,41,53`; `resources/js/lib/api.js:5-8` |

**Evidencia**

```js
// resources/js/lib/auth.jsx
const login = async (username, password) => {
    const r = await api.post('/auth/login', { username, password });
    localStorage.setItem('accessToken', r.data.data.accessToken);
    ...
};

// resources/js/lib/api.js
const token = localStorage.getItem('accessToken');
if (token) config.headers.Authorization = `Bearer ${token}`;
```

**Descripción técnica**

El token de Sanctum (`plainTextToken`) se guarda en `localStorage`, accesible por cualquier JavaScript del origen. No existe cookie `HttpOnly` ni `SameSite` que lo proteja. La aplicación no define una Content-Security-Policy (ver SEC-005), lo que elimina una barrera adicional.

**Escenario de explotación**

1. Un atacante consigue ejecutar JavaScript en el origen (XSS almacenado/reflejado, dependencia comprometida, previsualización de `.docx` malicioso — ver SEC-006).
2. Ejecuta `localStorage.getItem('accessToken')` y exfiltra el token a un servidor externo.
3. Usa el token como `Authorization: Bearer ...` desde cualquier red durante los 480 minutos configurados, sin necesitar usuario ni contraseña.

**Impacto**

Suplantación completa de la víctima con su rol (incluido ADMIN), acceso a trámites, informes, usuarios y backups según privilegios. La revocación exige que el usuario cierre sesión o cambie contraseña.

**Remediación**

- Migrar a autenticación por **cookie de sesión `HttpOnly` + `Secure` + `SameSite=Lax/Strict`** usando el modo SPA stateful de Sanctum (`EnsureFrontendRequestsAreStateful`), eliminando el token de `localStorage`.
- Si se mantiene el token, reducirlo a memoria (variable JS) con refresh token en cookie `HttpOnly`, y añadir CSP estricta.
- Implementar **CSP** y revisar todos los puntos de renderizado dinámico.

**Ejemplo seguro (esquema)**

```js
// El navegador no lee el token: la cookie HttpOnly viaja automáticamente.
axios.create({ baseURL: '/api', withCredentials: true });
// Login: POST /api/auth/login con sesión Sanctum stateful.
```

---

### SEC-002 — Dependencias Symfony con avisos de seguridad

| Campo | Valor |
|---|---|
| Severidad | **MEDIO** |
| Tipo | Vulnerabilidad de componentes confirmada (`composer audit`) |
| CVSS 4.0 | Variable; el más relevante ≈ 6.3 |
| Archivos | `composer.lock` |

**Evidencia**

`composer audit` reportó **7 advisories que afectan a 4 paquetes**:

| Paquete | Versión instalada | Aviso | Rango corregido |
|---|---|---|---|
| symfony/http-foundation | v7.4.21 | CVE-2026-118514 (HEAD bypass de `methods` en access_control) | ≥ 7.4.23 |
| symfony/http-foundation | v7.4.21 | Path rules bypass / GHSA-3h27-hjv7-fqjx | ≥ 7.4.23 |
| symfony/http-foundation | v7.4.21 | `private_ranges` trusted proxies / GHSA-xg2q-4rrq-657q | ≥ 7.4.23 |
| symfony/http-foundation | v7.4.21 | URL host elegido por el cliente / GHSA-5mx8-mwrp-5693 | ≥ 7.4.23 |
| symfony/http-kernel | v7.4.18 | CVE-2026-123339 (Forwarded headers / host/scheme) | ≥ 7.4.23 |
| symfony/mime | v7.4.18 | CVE-2026-45070 (Email header injection) | ≥ 7.4.22 |
| symfony/routing | v7.4.18 | CVE-2026-118516 (Host placeholders / path bypass) | ≥ 7.4.23 |

**Descripción técnica**

La aplicación no usa el componente Symfony Security `access_control`, por lo que los bypass de firewall no son directamente aplicables. Sin embargo, los avisos de **Host header / Forwarded headers** sí pueden afectar la generación de URLs si el despliegue está detrás de un proxy sin `TrustProxies` correctamente configurado. La inyección de cabeceras en `symfony/mime` solo sería relevante si se envían correos con parámetros controlados por el usuario (actualmente `MAIL_MAILER=log`).

**Escenario de explotación**

- Host header poisoning: un atacante envía `X-Forwarded-Host`/`Host` manipulados y logra que URLs generadas por la app apunten a su dominio (phishing, enlaces de descarga, redirecciones).
- Si en el futuro se usa el componente Security o se envían correos con parámetros del usuario, los otros avisos pasan a ser explotables.

**Impacto**

Depende del despliegue; en el peor caso, redirección de enlaces y fuga de información. Riesgo real moderado.

**Remediación**

- Actualizar el framework/componentes Symfony a versiones corregidas (`composer update symfony/* laravel/framework` en una ventana controlada).
- Configurar `trustedproxies` y `trustedhosts` explícitos en `bootstrap/app.php` para el proxy real.
- Repetir `composer audit` en CI y bloquear builds con avisos altos.

---

### SEC-003 — Usuarios desactivados conservan tokens válidos

| Campo | Valor |
|---|---|
| Severidad | **ALTO** |
| Tipo | Vulnerabilidad confirmada (lógica de autorización) |
| CVSS 4.0 estimado | `CVSS:4.0/AV:N/AC:L/AT:N/PR:L/UI:N/VC:H/VI:H/VA:N/SC:N/SI:N/SA:N` ≈ **8.1** |
| Archivos | `app/Http/Controllers/Admin/UserController.php:97-103`; `app/Http/Controllers/Auth/AuthController.php:26-30`; `routes/api.php:29-36` |

**Evidencia**

```php
// UserController::destroy — solo marca isActive=false
public function destroy(string $id): JsonResponse
{
    $user = User::findOrFail($id);
    $user->update(['isActive' => false]);
    return ApiResponse::ok(['message' => 'Usuario desactivado.']);
}
```

```php
// AuthController::login — isActive solo se comprueba al iniciar sesión
if (! $user || ! Hash::check(...) || ! $user->isActive) {
    return ApiResponse::error('Credenciales inválidas.', 401);
}
```

No existe middleware ni comprobación por petición que valide `isActive`; `auth:sanctum` valida el token, no el estado del usuario.

**Escenario de explotación**

1. Un empleado es despedido y el ADMIN lo desactiva.
2. El empleado conserva el token emitido en su dispositivo (o lo copió previamente).
3. Sigue llamando a `/api/tramites`, `/api/reports`, etc. con `Authorization: Bearer ...` durante hasta 480 minutos (o hasta que el token se revoque manualmente).

**Impacto**

Acceso no autorizado continuado tras la revocación del vínculo laboral. En un rol JEFE/ADMIN podría incluir datos sensibles y operaciones administrativas.

**Remediación**

- Revocar todos los tokens al desactivar: `$user->tokens()->delete();` en `destroy`.
- Añadir un middleware global/API que rechace usuarios con `isActive === false` en cada petición autenticada.
- Añadir job/expiración corta y rotación forzada.

**Ejemplo**

```php
// app/Http/Middleware/EnsureUserIsActive.php
public function handle(Request $request, Closure $next)
{
    if ($request->user() && ! $request->user()->isActive) {
        $request->user()->tokens()->delete();
        return ApiResponse::error('Cuenta desactivada.', 403);
    }
    return $next($request);
}
```

---

### SEC-004 — Política de contraseñas débil

| Campo | Valor |
|---|---|
| Severidad | **MEDIO** |
| Tipo | Mala práctica / debilidad de autenticación |
| CVSS 4.0 estimado | ≈ **5.3** |
| Archivos | `app/Http/Requests/RegisterUserRequest.php:19`; `app/Http/Requests/ChangePasswordRequest.php:18`; `app/Http/Requests/UpdateUserRequest.php` |

**Evidencia**

```php
'password' => ['required', 'string', 'min:6', 'max:100'],   // RegisterUserRequest
'newPassword' => ['required', 'string', 'min:6', 'max:100'], // ChangePasswordRequest
```

**Descripción técnica**

Solo se exige longitud mínima de 6 caracteres. No hay requisitos de complejidad, longitud máxima razonable, lista de contraseñas comunes, verificación contra filtraciones (Have I Been Pwned) ni comparación con datos del usuario. Las contraseñas se almacenan correctamente con `bcrypt` (cast `hashed`), pero contraseñas débiles son triviales de romper por diccionario.

**Escenario de explotación**

Credential stuffing o fuerza bruta distribuida contra `/api/auth/login` (límite 10/min por IP, evadible con proxies) hasta acertar contraseñas como `123456`, `admin1`, etc.

**Impacto**

Toma de cuentas, incluida ADMIN si usa una contraseña débil.

**Remediación**

- Aplicar `Password::min(12)->letters()->mixedCase()->numbers()->symbols()->uncompromised()` en registro y cambio.
- Limitar intentos por **cuenta** además de por IP.
- Forzar cambio de contraseña tras reset administrativo.

---

### SEC-005 — Ausencia de cabeceras de seguridad HTTP

| Campo | Valor |
|---|---|
| Severidad | **MEDIO** |
| Tipo | Configuración insegura / defensa en profundidad |
| CVSS 4.0 estimado | ≈ **5.0** |
| Archivos | `bootstrap/app.php:19-30` (solo Inertia y aliases); búsqueda global sin resultados de `Content-Security-Policy`, `X-Frame-Options`, `Strict-Transport-Security`, `X-Content-Type-Options`, `Referrer-Policy` |

**Descripción técnica**

No se añade middleware de cabeceras ni se configuran en el servidor web del repositorio. La aplicación depende del navegador y de la configuración externa (Nginx/Apache), no versionada aquí.

**Impacto**

- **Clickjacking:** la app puede embeberse en un iframe y superponer acciones administrativas.
- **XSS:** sin CSP se amplifica el impacto de SEC-001/SEC-006.
- **MIME sniffing** y fuga de `Referer` hacia terceros.

**Remediación**

Añadir middleware global (o configuración del web server) con:

```
Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'
X-Frame-Options: DENY
X-Content-Type-Options: nosniff
Referrer-Policy: strict-origin-when-cross-origin
Strict-Transport-Security: max-age=31536000; includeSubDomains
Permissions-Policy: camera=(), microphone=(), geolocation=()
```

Ajustar CSP para Vite/Inertia (nonces o hashes) y validar en modo Report-Only antes de bloquear.

---

### SEC-006 — XSS en previsualización de plantillas `.docx`

| Campo | Valor |
|---|---|
| Severidad | **MEDIO** |
| Tipo | Riesgo potencial / superficie XSS |
| CVSS 4.0 estimado | ≈ **5.2** (requiere rol JEFE/ADMIN) |
| Archivos | `resources/js/pages/Templates.jsx:224-226`; `resources/js/pages/Reports.jsx:80-88`; `resources/js/pages/Dashboard.jsx:778-785`; `resources/js/pages/History.jsx:90-108`; `resources/js/lib/reportShared.js:27-36` |

**Evidencia**

```js
await renderAsync(await blob.arrayBuffer(), exampleRef.current);
sanitizeLinks(exampleRef.current);
```

```js
export const sanitizeLinks = (root) => {
    root.querySelectorAll('a[href]').forEach((a) => {
        const href = (a.getAttribute('href') || '').trim();
        if (!/^(https?:|mailto:|tel:|#|\/)/i.test(href)) {
            a.removeAttribute('href');
            a.removeAttribute('xlink:href');
        }
    });
};
```

**Descripción técnica**

`docx-preview` transforma el contenido del `.docx` en HTML y lo inyecta en el DOM. La mitigación actual solo recorre enlaces después del render; no elimina:

- atributos de evento inline (`onerror`, `onload`) si el renderer los produjera,
- etiquetas/atributos peligrosos insertados por un docx manipulado,
- recursos externos (imágenes/beacons) que exfiltran información,
- hojas de estilo o contenido SVG activo.

El `.docx` puede subirlo un JEFE o ADMIN (endpoint `POST /api/templates`, rol `ADMIN|JEFE`), por lo que un JEFE podría atacar a un ADMIN que previsualice la plantilla, o un ADMIN comprometido podría atacar a otros usuarios.

**Escenario de explotación**

1. Atacante con rol JEFE sube un `.docx` con contenido malicioso.
2. Un ADMIN abre la vista previa en `/jefe/plantillas`.
3. El HTML malicioso se renderiza; si logra ejecutar JS, roba el token de `localStorage` (SEC-001).

**Impacto**

Ejecución de JavaScript en el origen de la aplicación con privilegios de la víctima.

**Remediación**

- Renderizar la vista previa en un **iframe sandbox** (`sandbox="allow-same-origin"` mínimo, sin `allow-scripts`) o en un origen aislado.
- Aplicar una lista blanca estricta de etiquetas/atributos (DOMPurify) al HTML resultante, no solo a los enlaces.
- Bloquear recursos externos en la previsualización (CSP `img-src data: blob:`).
- Limitar la subida de plantillas a usuarios de confianza y registrar auditoría.

---

### SEC-007 — Rutas web sin autenticación (protección solo en cliente/API)

| Campo | Valor |
|---|---|
| Severidad | **BAJO** |
| Tipo | Debilidad de diseño / defensa en profundidad |
| Archivos | `routes/web.php:6-32`; `resources/js/components/layout/RoleGuard.jsx:4-16`; `resources/js/components/layout/Layout.jsx:97-122` |

**Evidencia**

```php
// routes/web.php — decisión documentada
Route::get('/admin/usuarios', fn () => Inertia::render('Users'));
Route::get('/admin/backups', fn () => Inertia::render('Backups'));
```

Cualquier persona puede solicitar el HTML/JS de páginas administrativas; los datos y acciones están protegidos por la API. El `RoleGuard` redirige en cliente, pero no es un control de seguridad.

**Impacto**

Divulgación de la estructura de la aplicación y de nombres de rutas; si en el futuro alguna página recibe props sensibles desde el controlador web, quedarían expuestas. Hoy no se comparten datos de negocio por esa vía.

**Remediación**

- Añadir `auth` de sesión o un guard basado en cookie para las rutas web, o al menos devolver `404/403` a no autenticados mediante un middleware que valide la cookie de sesión.
- Mantener la autorización de datos exclusivamente en la API (correcto).
- No incrustar datos sensibles en props de páginas web sin autorización de servidor.

---

### SEC-008 — Exposición del nombre de la base de datos y entorno al navegador

| Campo | Valor |
|---|---|
| Severidad | **BAJO** |
| Tipo | Divulgación de información |
| Archivos | `app/Http/Middleware/HandleInertiaRequests.php:13-24` |

**Evidencia**

```php
return array_merge(parent::share($request), [
    'env' => [
        'name' => app()->environment(),
        'isProduction' => app()->environment('production'),
        'db' => config("database.connections.{$connection}.database"),
    ],
]);
```

**Impacto**

Facilita fingerprinting y reconocimiento (nombre de BD, entorno). No expone credenciales, pero es información innecesaria para el cliente.

**Remediación**

En producción, compartir solo un booleano de entorno o eliminar `db`. No compartir nombres internos.

---

### SEC-009 — Rate limiting de login solo por IP

| Campo | Valor |
|---|---|
| Severidad | **BAJO** |
| Tipo | Debilidad de autenticación |
| Archivos | `routes/api.php:17`; `bootstrap/app.php:22` |

**Evidencia**

```php
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
$middleware->throttleApi('120,1');
```

**Descripción técnica**

El límite es por IP (y por ruta), no por cuenta. Un atacante con botnet o proxies rota IPs y evita el bloqueo. Tampoco hay backoff progresivo ni CAPTCHA.

**Impacto**

Fuerza bruta/credential stuffing sostenido contra cuentas concretas.

**Remediación**

- Limitar por combinación `username + IP` y por `username` global.
- Bloqueo temporal progresivo y alertas.
- MFA para roles ADMIN/JEFE.

---

### SEC-010 — Nivel de log `debug` y posible filtración de datos

| Campo | Valor |
|---|---|
| Severidad | **BAJO** |
| Tipo | Configuración / logging |
| Archivos | `.env:LOG_LEVEL=debug`, `LOG_CHANNEL=stack`; `config/logging.php:61-74` |

**Descripción técnica**

`APP_DEBUG=false` es correcto (no se exponen stack traces al cliente). Sin embargo, `LOG_LEVEL=debug` en el entorno revisado puede escribir consultas, payloads y datos personales en `storage/logs/laravel.log`. El archivo `.env` no está trackeado en git (verificado).

**Impacto**

Exposición de PII/secretos a quien acceda a los logs; crecimiento excesivo de disco.

**Remediación**

- `LOG_LEVEL=warning` (o `error`) en producción.
- `LOG_STACK=daily` con retención.
- Nunca registrar tokens, contraseñas ni cuerpos completos.

---

### SEC-011 — Permisos `0777` en el directorio de informes

| Campo | Valor |
|---|---|
| Severidad | **BAJO** |
| Tipo | Configuración insegura |
| Archivos | `app/Services/ReportService.php:179` |

**Evidencia**

```php
@mkdir(dirname($outputPath), 0777, true);
```

**Impacto**

En hosting compartido, otros usuarios del sistema podrían leer o modificar informes generados. El directorio temporal sí usa `0700` (correcto).

**Remediación**

Usar `0750` o `0700` según el usuario del proceso web y el grupo necesario.

---

### SEC-012 — `$fillable` excesivo en `User`

| Campo | Valor |
|---|---|
| Severidad | **BAJO** |
| Tipo | Mala práctica / riesgo de mass assignment |
| Archivos | `app/Models/User.php:29-43`; `app/Services/UserService.php:28-40` |

**Evidencia**

```php
protected $fillable = [
    'username','password','email','role','firstName','lastName',...
    'isActive','canBackfill','canBackfillEnabledAt','empleadoId','creadoPorUsuarioId',
];
```

**Descripción técnica**

`role`, `isActive` y `canBackfill` son asignables en masa. Hoy los endpoints que escriben están protegidos por `auth:sanctum` + `role:ADMIN` y validados por Form Requests, por lo que no se confirmó explotación. Aun así, el patrón es frágil: un futuro endpoint que pase `$request->all()` a `create/update` permitiría escalar privilegios.

**Remediación**

- Usar `$request->safe()->only([...])` y listas explícitas.
- Separar DTOs/acciones para cambios de rol/estado.
- Considerar `$guarded` o mutadores dedicados.

---

### SEC-013 — Explorador de archivos y restauración SQL con privilegios amplios

| Campo | Valor |
|---|---|
| Severidad | **INFORMATIVO** (riesgo aceptado de diseño) |
| Tipo | Superficie administrativa sensible |
| Archivos | `app/Services/BackupService.php:380-448, 495-688`; `routes/api.php:92-101` |

**Descripción técnica**

Un ADMIN puede listar directorios del servidor, crear carpetas y restaurar `.sql`. Existen defensas razonables y verificadas:

- rechazo de `..`, `public/` y raíces de sistema (`validateDirectory`, `assertDirectoryAllowed`),
- no se listan symlinks,
- nombres de backup validados por regex,
- denylist de sentencias peligrosas (`GRANT`, `LOAD_FILE`, `INTO OUTFILE`, comentarios condicionales, etc.),
- restauración solo para ADMIN.

El denylist SQL es heurístico y no un parser completo; un ADMIN comprometido ya tiene control del panel. El riesgo es de **cadena de suministro/insider**, no de un usuario común.

**Remediación**

- Mantener el mínimo privilegio del usuario MySQL (sin `FILE`, `SUPER`, `CREATE USER`).
- Ejecutar restauraciones en un proceso aislado con credenciales limitadas.
- Auditar y alertar cada restauración/creación de directorio.
- Considerar deshabilitar el explorador libre en producción.

---

### SEC-014 — Contraseña temporal sin cambio forzado

| Campo | Valor |
|---|---|
| Severidad | **BAJO** |
| Tipo | Debilidad de autenticación |
| Archivos | `app/Http/Controllers/Admin/UserController.php:105-115`; `app/Http/Controllers/Auth/AuthController.php:168-178` |

**Evidencia**

```php
$temporary = Str::random(10);
$user->update(['password' => $temporary]);
$user->tokens()->delete();
return ApiResponse::ok(['temporaryPassword' => $temporary]);
```

**Descripción técnica**

La contraseña temporal es aleatoria (10 caracteres, aceptable) y se devuelve una sola vez, lo cual es correcto. Pero no existe un flag `mustChangePassword` ni expiración: el usuario puede seguir usando esa contraseña indefinidamente. Además, se transmite en JSON (puede quedar en logs de proxy si se registran respuestas).

**Remediación**

- Añadir `password_changed_at`/`must_change_password` y forzar cambio en el primer login.
- Expirar la temporal (p. ej. 24 h).
- Evitar registrar cuerpos de respuesta con credenciales.

---

### SEC-015 — Falta de auditoría de eventos de autenticación

| Campo | Valor |
|---|---|
| Severidad | **INFORMATIVO** |
| Tipo | OWASP A09 — fallo de logging/monitoreo |
| Archivos | `app/Http/Controllers/Auth/AuthController.php` (sin `Log::` de login fallido/exitoso) |

**Descripción técnica**

Los backups sí tienen auditoría (`backup-log.json`), pero no hay registro estructurado de inicios de sesión fallidos, bloqueos, cambios de contraseña ni accesos denegados. No se pudo verificar si el web server registra estos eventos.

**Remediación**

- Registrar `login.success`, `login.failed`, `password.changed`, `role.changed`, `user.deactivated` con IP, user-agent y usuario (sin contraseñas).
- Alertas ante ráfagas de fallos y cambios de rol.

---

### SEC-016 — Configuración de sesión/tokens mejorable

| Campo | Valor |
|---|---|
| Severidad | **INFORMATIVO** |
| Archivos | `config/session.php:50,172,185,202`; `config/sanctum.php:53`; `.env` |

**Descripción técnica**

- `SESSION_ENCRYPT=false`: los datos de sesión no se cifran (la sesión no se usa para autenticar la API, pero sí para Inertia/CSRF).
- `SESSION_SECURE_COOKIE=true` con `APP_URL=http://localhost`: en local sobre HTTP la cookie `Secure` no se envía; es correcto para producción HTTPS, pero debe verificarse en el despliegue.
- `SANCTUM_TOKEN_EXPIRATION=480` (8 h) es razonable, aunque para ADMIN podría ser menor.
- `SANCTUM_STATEFUL_DOMAINS` vacío: coherente con el uso de Bearer, pero impide migrar a cookies sin configurarlo.

**Remediación**

- Fijar `SESSION_ENCRYPT=true` si la sesión transporta datos sensibles.
- Asegurar HTTPS y `APP_URL` real en producción.
- Acortar expiración para roles privilegiados y rotar tokens.

---

### SEC-017 — Wildcards `LIKE` no escapados en búsquedas

| Campo | Valor |
|---|---|
| Severidad | **BAJO** |
| Tipo | Mala práctica / disponibilidad |
| Archivos | `app/Http/Controllers/Jefe/TemplateController.php:24-25`; `app/Http/Controllers/Jefe/ReportController.php:48-51`; `app/Http/Controllers/Admin/ServiceController.php:34-36`; `app/Http/Controllers/Admin/TipoRegistroController.php:34-36`; `app/Http/Controllers/Admin/EntregaItemController.php:34-36` |

**Evidencia**

```php
$q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")
    ->orWhere('fileName', 'like', "%{$search}%"));
```

**Descripción técnica**

No hay SQL Injection: los valores van como bindings. Pero `%` y `_` del usuario no se escapan, permitiendo patrones comodín arbitrarios y búsquedas costosas. `UserController` y `TramiteRegistroService` sí escapan (buena práctica).

**Remedición**

Reutilizar el escape de `UserController::index` (`str_replace(['\\','%','_'], ...)`) y limitar longitud/palabras.

---

### SEC-018 — Dependencias JS con avisos (`npm audit`)

| Campo | Valor |
|---|---|
| Severidad | **BAJO** / INFORMATIVO |
| Tipo | Dependencias de desarrollo |
| Archivos | `package-lock.json`; `package.json` |

**Evidencia**

`npm audit` reportó **2 vulnerabilidades HIGH**: `follow-redirects` (transitiva vía axios) y `vite` (devDependency, múltiples avisos de `launch-editor`/`server.fs.allow`).

**Descripción técnica**

- `vite` no se incluye en el bundle de producción; sus avisos afectan al servidor de desarrollo local.
- `follow-redirects` se usa en el entorno Node de axios, no en el navegador.

**Impacto**

Bajo en producción; relevante si se expone el servidor de desarrollo Vite o se ejecuta tooling en CI con entradas no confiables.

**Remediación**

- Actualizar `vite` y `axios` en la próxima ventana.
- No exponer `npm run dev` fuera de localhost.
- Ejecutar `npm audit` en CI.

---

## D. Matriz de riesgos

| ID | Vulnerabilidad | Severidad | Probabilidad | Impacto | Prioridad |
|---|---|---|---|---|---|
| SEC-001 | Token en `localStorage` | ALTO | Media | Alto | **P1** |
| SEC-003 | Tokens de usuarios desactivados válidos | ALTO | Media | Alto | **P1** |
| SEC-002 | Dependencias Symfony vulnerables | MEDIO | Media | Medio | **P2** |
| SEC-004 | Política de contraseñas débil | MEDIO | Alta | Medio | **P2** |
| SEC-005 | Sin cabeceras de seguridad | MEDIO | Alta | Medio | **P2** |
| SEC-006 | XSS en previsualización `.docx` | MEDIO | Baja/Media | Alto | **P2** |
| SEC-007 | Rutas web sin auth | BAJO | Alta | Bajo | P3 |
| SEC-008 | Fuga de nombre de BD/entorno | BAJO | Alta | Bajo | P3 |
| SEC-009 | Throttle solo por IP | BAJO | Media | Medio | P3 |
| SEC-010 | `LOG_LEVEL=debug` | BAJO | Media | Bajo/Medio | P3 |
| SEC-011 | Directorio de informes `0777` | BAJO | Baja | Medio | P3 |
| SEC-012 | `$fillable` excesivo | BAJO | Baja | Alto (si cambia el código) | P3 |
| SEC-013 | Explorador/restore de backups | INFO | Baja | Alto (insider) | P3 |
| SEC-014 | Temporal sin cambio forzado | BAJO | Media | Medio | P3 |
| SEC-015 | Sin auditoría de auth | INFO | Alta | Bajo/Medio | P3 |
| SEC-016 | Config sesión/tokens | INFO | Baja | Bajo | P3 |
| SEC-017 | Wildcards `LIKE` | BAJO | Media | Bajo | P3 |
| SEC-018 | Dependencias JS dev | BAJO | Baja | Bajo | P3 |

---

## E. Evaluación OWASP Top 10 (2021)

| Categoría | Estado | Justificación |
|---|---|---|
| **A01 Broken Access Control** | **Parcial** | Autorización por rol y propiedad correcta en trámites, plantillas, informes y backups. Hallazgo ALTO por tokens de usuarios desactivados (SEC-003); rutas web sin auth (SEC-007) como defensa en profundidad. |
| **A02 Cryptographic Failures** | **Aceptable** | Contraseñas con bcrypt, HTTPS asumido, cookies `Secure`/`HttpOnly`/`SameSite=Lax`. Punto débil: token en `localStorage` (SEC-001) y `SESSION_ENCRYPT=false`. |
| **A03 Injection** | **Bueno** | Eloquent/Query Builder con bindings; `DB::raw` solo con expresiones fijas (`COUNT(*)`). Sin SQLi identificada. `LIKE` con wildcards no escapados (SEC-017, solo disponibilidad). |
| **A04 Insecure Design** | **Parcial** | Buenas decisiones (token rotation, auditoría de backups, guards de archivos). Faltan límites por cuenta, MFA para privilegiados y cambio forzado de contraseña. |
| **A05 Security Misconfiguration** | **Parcial** | `APP_DEBUG=false`, `.env` no trackeado, CORS restringido. Faltan cabeceras de seguridad (SEC-005) y `LOG_LEVEL=debug` (SEC-010). |
| **A06 Vulnerable Components** | **Deficiente** | `composer audit` con 7 advisories en Symfony (SEC-002); `npm audit` con 2 HIGH en dev (SEC-018). |
| **A07 Auth Failures** | **Parcial** | Hash correcto, error genérico, throttling, rotación/revocación de tokens. Contraseñas débiles (SEC-004), throttle solo por IP (SEC-009), sin MFA. |
| **A08 Data Integrity Failures** | **Aceptable** | Validación estructural de `.docx`, UUID para archivos, XML blindado. Riesgo residual en restauración SQL (admin) y previsualización docx (SEC-006). |
| **A09 Logging & Monitoring** | **Deficiente** | Sin auditoría de login/cambios de rol (SEC-015); logs en debug. |
| **A10 SSRF** | **Bueno** | No se identificaron peticiones HTTP salientes controladas por el usuario. Las conversiones usan binarios locales y rutas internas; `SafeXml` bloquea red. |

### Otros vectores

| Vector | Estado |
|---|---|
| CSRF | **Aceptable** — API con Bearer token (no cookies de sesión); Sanctum stateful activo y CSRF de Laravel para web. |
| Clickjacking | **Vulnerable** — sin `X-Frame-Options`/`frame-ancestors` (SEC-005). |
| Open Redirect | **No identificado** — no hay redirecciones con parámetros de usuario. |
| CORS | **Correcto** — solo `/api/*` y `sanctum/csrf-cookie`, orígenes restringidos a `APP_URL`/`CORS_ALLOWED_ORIGINS`, `supports_credentials=false`. |
| Cookies | **Correcto** — `HttpOnly=true`, `SameSite=lax`, `Secure` configurable; `Secure=true` en `.env`. |
| Stack traces | **Correcto** — `APP_DEBUG=false`; envelope de errores API propio. |
| Fuga en logs | **Riesgo bajo** — `LOG_LEVEL=debug` (SEC-010). |

---

## F. Plan de remediación

### Prioridad 1 — Críticas y altas (inmediato, antes de exponer a Internet)

1. **SEC-001:** migrar a cookie de sesión `HttpOnly` de Sanctum o, como mínimo, reducir la vida del token, añadir CSP estricta y eliminar el token de `localStorage`.
2. **SEC-003:** revocar tokens al desactivar usuarios + middleware `isActive` por petición.
3. Verificar `trustedproxies`/`trustedhosts` y HTTPS en el despliegue.

### Prioridad 2 — Medias (próximo ciclo)

4. **SEC-002:** actualizar Laravel/Symfony a versiones corregidas y añadir `composer audit` a CI.
5. **SEC-004:** política de contraseñas robusta (`Password::min(12)->uncompromised()`), bloqueo por cuenta y MFA para ADMIN/JEFE.
6. **SEC-005:** middleware de cabeceras de seguridad (CSP, HSTS, X-Frame-Options, nosniff, Referrer-Policy).
7. **SEC-006:** aislar la previsualización de `.docx` en iframe sandbox y sanear HTML con DOMPurify.

### Prioridad 3 — Bajas e informativas (backlog)

8. **SEC-007:** proteger rutas web con sesión o middleware de autenticación.
9. **SEC-008:** dejar de compartir `db`/entorno detallado al navegador.
10. **SEC-009:** rate limit por cuenta además de por IP.
11. **SEC-010:** `LOG_LEVEL=warning`, rotación y no registrar datos sensibles.
12. **SEC-011:** permisos `0750/0700` en el directorio de informes.
13. **SEC-012:** reducir `$fillable` y usar DTOs/acciones para rol/estado.
14. **SEC-013:** mínimo privilegio en el usuario MySQL y auditoría de restauraciones.
15. **SEC-014:** forzar cambio de contraseña temporal y expirarla.
16. **SEC-015:** logging estructurado de eventos de autenticación y alertas.
17. **SEC-016:** revisar cifrado de sesión y expiración de tokens por rol.
18. **SEC-017:** escapar `%`/`_` en búsquedas `LIKE`.
19. **SEC-018:** actualizar `vite`/`axios` y no exponer el dev server.

---

## G. Aspectos revisados sin hallazgos

Se revisaron y **no presentaron vulnerabilidades explotables**:

- **SQL Injection:** todas las consultas usan bindings de Eloquent/Query Builder; `DB::raw` solo con `COUNT(*)` y columnas fijas. Búsquedas parametrizadas.
- **IDOR/BOLA en trámites:** `baseQuery` restringe a `userId` para operadores; `update` permite solo propietario o ADMIN/JEFE; `deactivate` solo ADMIN/JEFE.
- **IDOR en informes:** `download`/`pdf`/`destroy` verifican `generatedById === user->id || role === ADMIN`.
- **IDOR en plantillas:** mutaciones limitadas a ADMIN o al propietario (`assertCanMutate`); descarga disponible para ADMIN/JEFE (por diseño).
- **Path traversal en backups:** nombres validados por regex, rechazo de `..`, `public/`, raíces de sistema y symlinks.
- **Path traversal en plantillas:** nombre original saneado con `basename` + regex; archivo físico con UUID.
- **XXE / billion laughs:** `SafeXml` usa `LIBXML_NONET`, no `LIBXML_NOENT` y desactiva el entity loader.
- **Zip bomb:** `assertValidDocx` limita a 2000 entradas y 100 MB descomprimidos.
- **Upload de archivos:** validación MIME/extensión/tamaño en plantillas (`docx/zip`, 10 MB) y en restauración (`.sql`, heurística de dump).
- **SSRF:** no hay peticiones salientes con URL controlada por el usuario.
- **Mass assignment explotable:** no se confirmó; los endpoints de escritura están protegidos y validados (riesgo residual en SEC-012).
- **CSRF:** API con Bearer token; Sanctum stateful y CSRF de Laravel en web.
- **CORS:** orígenes restringidos, sin credenciales, solo rutas API.
- **Secretos en git:** `.env` no está trackeado; `.env.example` sin secretos reales; `APP_KEY` definida pero no versionada.
- **Debug/stack traces:** `APP_DEBUG=false`; errores API con envelope controlado.
- **Contraseñas:** hasheadas con bcrypt (cast `hashed`), nunca devueltas en `me`/listados (`$hidden`).
- **Tokens:** rotación en refresh, borrado en logout, revocación de otros tokens al cambiar contraseña y al reset administrativo.
- **Generación de informes:** `nroCI` saneado para el nombre de archivo; gráficos base64 validados como imagen y limitados a 6 MB.
- **Conversión PDF:** binario local, timeout de 60 s, directorio temporal `0700`, sin `shell_exec`.
- **Autorización de rutas API:** todas las rutas sensibles tienen `auth:sanctum` + `role:*`; catálogos con roles acotados.

---

## H. Limitaciones

- **Sin pruebas dinámicas:** no se ejecutaron ataques, fuzzing ni pruebas de penetración contra la aplicación en ejecución.
- **Sin acceso a producción:** no se verificó la configuración real del web server, proxy, TLS, firewall ni cabeceras inyectadas fuera del repositorio.
- **Sin acceso a la base de datos:** no se validaron datos reales, índices, permisos del usuario MySQL ni integridad de registros.
- **Herramientas no instaladas:** no se ejecutó PHPStan/Larastan (no configurado), Pest/PHPUnit (no se ejecutaron suites) ni un escáner SAST dedicado.
- **`composer audit` / `npm audit`:** dependen de las bases de datos públicas de avisos en la fecha de ejecución; pueden aparecer nuevos avisos.
- **docx-preview:** no se verificó en navegador si un `.docx` malicioso concreto logra ejecutar JavaScript; el hallazgo SEC-006 es un riesgo potencial basado en la superficie de renderizado y la sanitización parcial.
- **LibreOffice:** no se probó la conversión PDF ni el comportamiento ante documentos manipulados.
- **Configuración de producción:** `.env` revisado corresponde a `APP_ENV=local`; los valores de producción (HTTPS, logs, proxies) deben confirmarse por separado.
- **Cumplimiento normativo:** no se evaluó cumplimiento legal (GDPR, protección de datos local) ni retención de PII.

---

## Conclusión

La plataforma tiene una base de seguridad sólida en el backend: autorización por roles y propiedad, consultas parametrizadas, validación de archivos, protección XXE/zip-bomb y buena gestión de tokens. Los riesgos más relevantes no son fallos clásicos de inyección, sino **decisiones de arquitectura de autenticación** (token en `localStorage` y vigencia de tokens de usuarios desactivados) y **endurecimiento incompleto** (cabeceras, política de contraseñas, dependencias y auditoría).

Atender la **Prioridad 1** reduce el riesgo global de ALTO a MEDIO; completar la **Prioridad 2** lo lleva a un nivel aceptable para producción.

---

## I. Estado de remediación aplicado (2026-11-09)

Tras la auditoría se aplicaron las siguientes correcciones de bajo riesgo, con **63 pruebas pasando** (`php artisan test`) y build de frontend correcto (`npm run build`):

| ID | Estado | Cambio aplicado |
|---|---|---|
| SEC-003 | **Corregido** | Nuevo middleware `app/Http/Middleware/EnsureUserIsActive.php` (rechaza y revoca tokens de usuarios inactivos en cada petición API). `UserController::destroy` ahora ejecuta `$user->tokens()->delete()`. Registrado en el grupo `api`. |
| SEC-005 | **Mitigado** | Nuevo middleware `app/Http/Middleware/SecurityHeaders.php`: `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy`, `Permissions-Policy`, `Cross-Origin-Opener-Policy`, HSTS si la petición es HTTPS y CSP en modo `Report-Only` en producción. Aplicado a `web` y `api`. |
| SEC-008 | **Corregido** | `HandleInertiaRequests` ya no comparte el nombre de la base de datos; `EnvBadge.jsx` actualizado. |
| SEC-011 | **Corregido** | Directorio de informes creado con permisos `0750` en lugar de `0777`. |
| SEC-017 | **Corregido** | Nuevo helper `app/Support/LikeSearch.php`; búsquedas de plantillas, informes, servicios, tipos de registro e ítems ahora escapan `%`, `_` y `\`. |
| SEC-018 | **Pendiente** | Actualización de `vite`/`axios` no aplicada (requiere ventana de dependencias). |
| SEC-001 | **Pendiente (arquitectura)** | Requiere migrar a cookie `HttpOnly`/Sanctum stateful o implementar refresh tokens en cookie. Cambio de mayor alcance; no se aplicó para no romper el flujo actual de login. |
| SEC-002 | **Pendiente** | Actualización de Laravel/Symfony; requiere `composer update` controlado y revalidación. |
| SEC-004, SEC-006, SEC-007, SEC-009, SEC-010, SEC-012, SEC-013, SEC-014, SEC-015, SEC-016 | **Pendiente** | Backlog P2/P3 según el plan de la sección F. |

### Pruebas añadidas

- `AuthTest::test_usuario_desactivado_no_puede_usar_su_token` — verifica 403 y revocación del token.
- `AuthTest::test_cabeceras_de_seguridad_en_respuestas_web` — verifica cabeceras.
- `BackupsTest` ajustado para confirmar que `env.db` ya no se comparte.
- `UsersServicesTest` alineado con el seeder actual (`amontoya`).

**Resultado:** el riesgo ALTO de SEC-003 queda cerrado. SEC-001 sigue siendo el riesgo residual más importante y requiere una decisión de arquitectura antes de exponer la aplicación a Internet.
