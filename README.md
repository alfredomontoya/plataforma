# Plataforma RO

Plataforma de registro operativo de servicios **INGRESO / ENTREGA**:
los operadores cargan cantidades diarias por servicio, los jefes ven el
dashboard y generan informes oficiales `.docx`, y los administradores
gestionan usuarios, servicios y plantillas.

## Stack

- **Backend:** Laravel 13 (PHP 8.3), Sanctum (tokens API),
  Spatie Permission (roles), PhpOffice/PhpWord (informes).
- **Frontend:** Inertia.js 3 + React 19 + Tailwind CSS 4 + Vite.
- **BD por defecto:** SQLite (`database/database.sqlite`).

## Roles y módulos

| Rol | Rutas | Qué hace |
| --- | ----- | -------- |
| `OPERATOR_INGRESO` / `OPERATOR_ENTREGA` | `/operador/entrada` | Registro diario por servicio (precarga lo guardado, resumen con totales) |
| `ADMIN` | `/admin/usuarios`, `/admin/servicios` | CRUD usuarios, servicios y reseteo de claves |
| `JEFE` | `/jefe/dashboard`, `/jefe/reportes`, `/jefe/reportes/historial` | Agregados día/semana/rango, informes `.docx` con plantillas y gráficos |

API bajo `/api`: `auth/*`, `users/*` (ADMIN), `services/*`,
`entries/*`, `templates/*`, `reports/*`.

## Puesta en marcha

```bash
composer setup     # install + .env + key + migrate + build
composer dev       # serve + queue + logs + vite en paralelo
```

## Datos de prueba

```bash
# Seed completo (wipe → roles → usuarios → servicios → plantillas → entradas)
php artisan platform:seed --force

# Solo entradas (limpia entries antes):
php artisan platform:entries-seed --force                                        # hoy, 5-10 filas/usuario
php artisan platform:entries-seed --from=2026-08-01 --to=2026-08-31 --force      # rango, N/día/usuario
php artisan platform:entries-seed --legacy --month=2026-08 --force               # modo original día×servicio
```

Usuarios base: `admin`, `jefe`, `ing1-5`, `ent1-5` (clave `password`).

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
