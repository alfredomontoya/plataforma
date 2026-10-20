---
name: Git Sync
description: Sube los cambios locales al remoto con git add, git commit y push en la rama actual
---

## Workflow

1. Ejecutar `git status --short` y `git branch --show-current` para ver los cambios
   y la rama activa.
2. Si no hay cambios, detenerse y reportarlo. No ejecutar nada más.
3. Ejecutar `git add -A` para incluir todos los cambios locales (rastreados, no
   rastreados y borrados).
4. Redactar un mensaje de commit conciso en español que resuma los cambios,
   siguiendo la convención del historial del repo.
5. Ejecutar `git commit -m "<mensaje>"`.
6. Subir al remoto:
   - Si la rama tiene upstream: `git push`
   - Si no lo tiene: `git push -u origin <rama-actual>`
7. Si el push es rechazado (remoto adelantado), NO hacer pull ni rebase: reportar
   el estado y pedir instrucciones al usuario.
8. Reportar el hash del commit y el resultado del push.
