# Plataforma RO

Plataforma de registro operativo de servicios **INGRESO / ENTREGA**:
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
> Nunca se siembran contraseñas por defecto en el servidor real: cree el
> primer ADMIN con `php artisan platform:user` (o pídale a un ADMIN que lo
> registre desde la interfaz con una contraseña propia).

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
