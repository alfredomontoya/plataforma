---
name: Deploy Sync
description: Replica los cambios de main en deploy (local y remoto), forzando que deploy coincida exactamente con main
---

## Workflow

1. Ejecutar `git branch --show-current` y `git status --short`. Si hay cambios
   sin commitear o no se está en main, reportar y detenerse.
2. `git fetch origin` para tener el estado real del remoto.
3. Verificar que main local esté al día con origin/main:
   - Si origin/main está adelantado, avisar al usuario y esperar confirmación
     antes de hacer pull.
4. Actualizar deploy en el remoto: `git push --force-with-lease origin main:deploy`
   - `--force-with-lease` solo fuerza si nadie más subió commits que no veamos;
     si se rechaza, reportar y pedir instrucciones.
5. Actualizar deploy local:
   - Si deploy NO está checked out: `git branch -f deploy main`
   - Si deploy está checked out: reportar y pedir instrucciones (no forzar).
6. Verificar el resultado: `git log --oneline main..deploy` y
   `git log --oneline deploy..main` deben quedar vacíos en ambas ramas.
7. Reportar los commits replicados y el resultado del push.
