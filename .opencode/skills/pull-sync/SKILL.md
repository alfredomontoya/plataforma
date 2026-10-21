---
name: Pull Sync
description: Trae los cambios del remoto a la rama local con fast-forward, sin ejecutar migraciones ni seeders, y advierte solo en producción
---

## Reglas inviolables

- PROHIBIDO ejecutar cualquier comando que escriba en la base de datos:
  `php artisan migrate`, `migrate:fresh`, `migrate:refresh`, `migrate:rollback`,
  `migrate:reset`, `db:seed`, `db:wipe`, `platform:seed`, `platform:wipe`,
  `platform:entries-seed`, `platform:totals-seed`, `composer setup` o
  cualquier endpoint de restauración de backups.
- PROHIBIDO modificar `.env`, `database/*.sqlite` o archivos fuera del árbol de git.
- PROHIBIDO `git reset --hard`, `git clean`, `git push --force` y `git pull`
  con merge o rebase automático. Solo fast-forward.
- Si el árbol de trabajo no está limpio o hay stash: detenerse y reportar.
  No hacer stash, commit ni descarte por cuenta propia.
- Nunca resolver conflictos automáticamente.

## Workflow

1. **Pre-flight**
   - `git status --short` y `git branch --show-current`.
   - Si hay cambios sin commitear o stash pendiente: reportar y detenerse.
   - Detectar el entorno: leer `APP_ENV` del `.env` y confirmarlo con
     `php artisan about --only=environment` (solo lectura).
   - Clasificar:
     - `local` / `development` / `dev` / `testing` → **modo dev**: continuar.
     - `production` o valor vacío/desconocido → **producción**: ir al paso 3.

2. **Ver el remoto sin tocar el árbol**
   - `git fetch origin --prune`
   - `git log --oneline HEAD..@{u}` → commits que faltan.
   - `git diff --stat HEAD..@{u}` → archivos afectados.
   - `git diff --name-only HEAD..@{u} -- database/migrations database/seeders`
     - Si hay migraciones o seeders nuevos: avisar que **no se ejecutarán** y
       que deberán correrse a mano tras respaldar la BD.
     - Si alguna migración nueva contiene `dropIfExists`, `dropColumn`,
       `dropUnique`, `truncate` o `->delete()`, marcarla como **destructiva**
       y resaltarlo en el resumen (informativo en dev, bloqueante en producción).

3. **Advertencia según entorno**
   - **Producción:** detenerse y pedir confirmación escrita antes de cualquier
     `pull`. Recordar respaldo de BD, ventana de mantenimiento
     (`php artisan down`) y que el esquema quedará desactualizado hasta que un
     humano ejecute las migraciones.
   - **Modo dev:** no bloquear; solo mostrar el resumen del paso 2 y continuar.

4. **Aplicar el pull (solo fast-forward)**
   - `git pull --ff-only origin <rama-actual>`
   - Si falla por divergencia: NO merge, NO rebase, NO reset. Reportar
     `git log --oneline HEAD..@{u}` y `git log --oneline @{u}..HEAD` y pedir
     instrucciones.

5. **Post-pull (sin tocar la BD)**
   - Reportar commit anterior → nuevo y `git status --short`.
   - Listar migraciones pendientes **sin ejecutarlas**:
     `php artisan migrate:status` (solo lectura).
   - Si el diff tocó `composer.json`, `composer.lock`, `package.json` o
     `package-lock.json`, avisar que cambiaron dependencias y preguntar antes
     de ejecutar `composer install` o `npm install`. No ejecutar builds.
   - Si la rama actual es `deploy` y quedó atrás de `main`, sugerir la skill
     `Deploy Sync`.

6. **Reporte final**
   - Rama, commits traídos, archivos, migraciones/seeders pendientes y su
     carácter destructivo.
   - Comandos que el usuario debe ejecutar manualmente, en orden.
   - Confirmación explícita: "No se ejecutaron migraciones ni seeders".
