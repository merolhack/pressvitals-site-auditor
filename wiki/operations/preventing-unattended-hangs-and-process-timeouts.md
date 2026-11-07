# Prevención de Bloqueos Desatendidos, Timeouts y Estándar de Ejecución Continua (Zero-Hang Protocol)

> [!IMPORTANT]
> **REGLA DE ORO DE AUTOMATIZACIÓN DESATENDIDA:**
> Queda terminantemente PROHIBIDO lanzar tareas en segundo plano que puedan requerir interacción de teclado, solicitud de contraseñas (`sudo`), confirmaciones interactivas (`[y/N]`) o que carezcan de un límite estricto de tiempo (*timeout*). Todo script, comando Docker o tarea desatendida debe ser 100% autónomo, inmune a bloqueos en `stdin` y contar con límites de ejecución defensivos.

---

## 1. Contexto Operativo y Riesgos de Bloqueo en PressVitals

El agente de IA y los entornos de automatización operan de manera desatendida sobre el repositorio `pressvitals-site-auditor`. Si un subproceso entra en estado de espera interactiva (`WAITING FOR INPUT`) o se cuelga indefinidamente en la red:
1. **Consumo de 0% CPU**: El proceso permanece dormido esperando entrada en `stdin`.
2. **Suspensión Reactiva del Agente**: Siguiendo el ciclo de esperar la conclusión de subprocesos antes de continuar, el agente no avanza ni reporta error.
3. **Pérdida Crítica de Tiempo**: El flujo de trabajo queda paralizado durante horas hasta que un operador humano interviene manualmente.

---

## 2. Los 5 Principios Inmutables del Protocolo "Zero-Hang"

Para blindar este repositorio contra cualquier bloqueo desatendido, todo agente, desarrollador o script automatizado debe aplicar estrictamente las siguientes 5 reglas operativas:

### Principio 1: Prohibición Absoluta de `sudo` (Delegación Root en Docker)
* **Regla:** NUNCA utilizar `sudo` en comandos ejecutados en el host (WSL o Linux).
* **Solución Canónica para Docker:** Si se requiere manipular permisos de archivos creados por contenedores (por ejemplo, en `vendor/`, tests o `.wordpress-org/`), se DEBE ejecutar con el usuario `root` (`-u 0`) dentro del contenedor:
  ```bash
  # CORRECTO: Ejecuta como root dentro del contenedor sin solicitar password en el host
  docker compose exec -T -u 0 wp-latest chown -R www-data:www-data /var/www/html/wp-content/plugins/pressvitals-site-auditor

  # PROHIBIDO: Requiere password interactivo en el host y congela subprocesos desatendidos
  sudo chown -R www-data:www-data .
  ```

### Principio 2: Timeouts Defensivos Obligatorios en Tareas en Background
* **Regla:** Ningún comando de pruebas, análisis de código, auditoría masiva o petición de red puede ejecutarse de forma indefinida. Todo comando susceptible de demorarse debe envolverse en la utilidad `timeout` de GNU coreutils.
* **Sintaxis Mandatoria:**
  ```bash
  # Límite estricto de 180s para PHPUnit en contenedores de prueba
  timeout 180s docker compose exec -T wp-71 vendor/bin/phpunit < /dev/null

  # Límite estricto de 120s para PHPCS
  timeout 120s docker compose exec -T wp-latest vendor/bin/phpcs < /dev/null

  # Límite estricto de 30s en peticiones cURL a la API de WordPress.org o SVN
  curl -s --connect-timeout 10 --max-time 30 "https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=pressvitals-site-auditor"
  ```

### Principio 3: Desacople de Entrada Estándar (`< /dev/null`) y Banderas No-Interactivas
* **Regla:** Todo proceso desatendido debe desacoplar el pseudo-TTY y cerrar su canal de entrada estándar para abortar con error en milisegundos si alguna herramienta intenta solicitar confirmación interactiva:
  * **Docker Compose:** Usar siempre `exec -T` (inhabilita la asignación de TTY) acompañado de `< /dev/null`:
    ```bash
    docker compose exec -T wp-latest vendor/bin/phpunit < /dev/null
    ```
  * **Docker Prune:** Usar siempre la bandera forzada `-f`:
    ```bash
    docker container prune -f && docker image prune -f && docker builder prune -a -f
    ```
  * **Git:** Desactivar prompts de credenciales con `-c core.askPass=true` y pasar la URL con PAT explícito:
    ```bash
    git -c core.askPass=true push https://merolhack:<PAT>@github.com/merolhack/pressvitals-site-auditor.git main
    ```
  * **GitHub CLI:** Usar comandos directos sin abrir editores de texto:
    ```bash
    gh release create <version> --title "<version>" --notes "<release notes>"
    ```

### Principio 4: Bitácora de Latido Persistente (Heartbeat Watchdog)
* **Regla:** Cuando se ejecute la validación de la matriz completa de hosting (`.docker/docker-compose.hosting.yml` con sus 8 stacks: cPanel, LEMP, OpenLiteSpeed, LAMP, etc.):
  * El script orquestador debe registrar checkpoints frecuentes en un archivo JSON (`.hosting_test_progress.json`) y en logs.
  * Si un contenedor no reporta progreso en más de 3 minutos, se considera en estado zombi, se termina de forma forzada y se continúa con el siguiente.

### Principio 5: Timeouts Estrictos y Prevención de Bloqueos en Código PHP (Runtime & Probes)
* **Regla de Peticiones HTTP en Sondas:**
  * Toda sonda que invoque `wp_remote_get()` o `wp_remote_head()` DEBE especificar un `timeout` explícito no mayor a 3-5 segundos y limitar redirecciones (`'redirection' => 2`).
  * NUNCA dejar llamadas HTTP con el valor por defecto sin especificar timeout.
* **Regla de Candado Atómico en WP-Cron:**
  * En ejecuciones programadas en segundo plano (`pvsa_run_scheduled_check()`), implementar un candado transitorio (`set_transient( 'pvsa_audit_in_progress', time(), 600 )`) para impedir que peticiones concurrentes solapen auditorías completas del sitio.

---

## 3. Matriz de Prevención de Fallos Comunes

| Escenario de Riesgo | Error o Síntoma Detectado | Medida Preventiva Inmediata |
| :--- | :--- | :--- |
| **Permisos de archivos en host WSL** | `Permission denied` tras montajes de Docker | Ejecutar `docker compose exec -T -u 0 <container> chown/chmod` (NUNCA `sudo`) |
| **Petición interactiva en Docker** | Subproceso congelado esperando TTY | Usar `docker compose exec -T <container> <cmd> < /dev/null` |
| **Petición interactiva en Git Push** | Terminal colgada solicitando usuario/PAT | Usar URL con PAT explícito y flag `-c core.askPass=true` |
| **Docker Prune interactivo** | Proceso congelado en `Are you sure? (y/n)` | Usar siempre la bandera forzada `-f` |
| **API de WordPress.org lenta o caída** | cURL esperando respuesta indefinidamente | Usar `--connect-timeout 10 --max-time 30` en verificaciones de release |
| **Sonda HTTP probando URL no responsiva** | WP-Cron o REST API alcanza `max_execution_time` | Forzar `'timeout' => 3` y capturar `is_wp_error()` con estado 'pass' o 'warn' |
| **Ejecuciones concurrentes de WP-Cron** | Duplicación de alertas y saturación de CPU | Usar transient lock atómico durante la ejecución de la auditoría |

---

## 4. Verificación de Conformidad Previa a Tareas Desatendidas
Antes de ausentarte y dejar una tarea corriendo:
1. Comprobar que ningún comando en cola o script incluya `sudo`.
2. Comprobar que los comandos en segundo plano cuenten con `timeout <Xs>` y `< /dev/null`.
3. Comprobar que los comandos de Docker lleven `exec -T` y las limpiezas lleven `-f`.
4. Comprobar que las peticiones cURL tengan límites `--max-time`.

