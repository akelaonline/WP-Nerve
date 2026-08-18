# WPNerve

**The native agent gateway for WordPress.**

[![CI](https://img.shields.io/github/actions/workflow/status/akelaonline/WP-Nerve/ci.yml?label=CI&color=16a34a)](https://github.com/akelaonline/WP-Nerve/actions)
[![Tests](https://img.shields.io/badge/tests-114%20%E2%80%94%20303%20assertions-16a34a)](https://github.com/akelaonline/WP-Nerve/actions)
[![Coverage](https://img.shields.io/badge/coverage-96%25-16a34a)](https://github.com/akelaonline/WP-Nerve/actions)
[![WordPress](https://img.shields.io/badge/WordPress-6.9%2B-21759b)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777bb3)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-GPL--2.0-orange)](LICENSE)
[![Instagram](https://img.shields.io/badge/Instagram-%40akelaonline-E4405F)](https://www.instagram.com/akelaonline/)

WPNerve is a self-hosted WordPress plugin that exposes carefully selected native
WordPress **Abilities** as **Model Context Protocol (MCP)** tools. It runs entirely
inside the WordPress installation: no relay, no SaaS control plane, no external
database.

> 🇬🇧 Full docs in English below — 🇪🇸 Documentación completa en español más abajo.

> **Project status:** early alpha. The protocol foundation, policy engine, audit
> log, and four read-only abilities are implemented. Do not install this branch
> on a production site before the security review and beta milestone are complete.

---

## English

### What is WPNerve?

WPNerve turns WordPress into a first-class citizen for AI agents. Instead of
punching holes into `wp-admin` or using a SaaS relay that stores your credentials,
you install one plugin and get a **private, authenticated MCP endpoint** on your
own site. An agent can then safely inspect and, in future milestones, manage
content using the same WordPress capabilities and permissions you already
manage in the admin.

- **Native.** Built on the WordPress 6.9+ Abilities API instead of a parallel
  action registry.
- **Self-hosted.** Runs inside WordPress. No external service ever sees your
  requests or credentials.
- **Secure by default.** HTTPS required in production, Application Password
  authentication, mirrored-header validation, and a central policy engine that
  denies destructive and privileged actions.

### Why WPNerve

- WordPress 6.9+ native Abilities API, not a parallel action registry.
- MCP `2026-07-28` stateless HTTP plus compatibility with `2025-11-25` and
  `2025-06-18` clients.
- WordPress Application Password authentication over HTTPS.
- A central policy gate separate from ability business logic.
- Least-privilege tool discovery: each user sees only the abilities they can
  execute.
- Privacy-preserving audit events without credentials or tool arguments.
- Destructive and privileged risk classes denied by default.

### Architecture

```mermaid
flowchart LR
    A["MCP client"] --> B["HTTP transport"]
    B --> C["Authentication"]
    C --> D["Policy engine"]
    D --> E["Abilities API"]
    E --> F["WordPress"]
    D --> G["Audit log"]
```

The protocol, transport, policy, and WordPress ability layers are deliberately
separate. A future protocol revision can replace the transport and dispatcher
without rewriting content operations. See
[Architecture](docs/architecture.md) and [Threat model](docs/threat-model.md).

### Quick start

1. **Install and activate** the plugin on a WordPress 6.9+ site (PHP 8.1+).
2. Open **Tools → WPNerve** to see your MCP endpoint:
   `https://your-site.com/wp-json/wp-nerve/v1/mcp`
3. In **Tools → WPNerve**, select a dedicated WordPress user with only the
   capabilities the agent needs and click **Generate WPNerve credential**.
4. Copy the one-time secret or the ready-to-use client configuration. WPNerve
   verifies the credential against its MCP endpoint without persisting it.
5. Revoke WPNerve credentials from the same screen when a client is retired or
   a device is lost.

Never commit or share the Application Password. If a client or device is lost,
revoke the password immediately.

### Tool catalog

| Tool | Description | Risk |
|---|---|---|
| `wp_nerve_site_status` | Non-sensitive site and WPNerve runtime diagnostics | Read |
| `wp_nerve_list_content_types` | Public content types with REST and supports info | Read |
| `wp_nerve_search_content` | Search content with post type, status, and pagination controls | Read |
| `wp_nerve_get_content` | Single post with full content, gated by status and capability | Read |

Planned for the v1 milestone: revisions, safe draft creation, content updates,
taxonomies, media, and comments. Destructive and privileged operations stay
denied until they pass the policy, preview, and recovery review. See the
[full ability catalog](docs/abilities-v1.md).

### Example requests

Replace the host, username, and Application Password. Never commit the password.

**Discovery (modern protocol):**

```bash
curl --user 'USERNAME:APPLICATION_PASSWORD' \
  --header 'Content-Type: application/json' \
  --header 'MCP-Protocol-Version: 2026-07-28' \
  --header 'Mcp-Method: server/discover' \
  --data '{
    "jsonrpc": "2.0",
    "id": 1,
    "method": "server/discover",
    "params": {
      "_meta": {
        "io.modelcontextprotocol/protocolVersion": "2026-07-28",
        "io.modelcontextprotocol/clientCapabilities": {},
        "io.modelcontextprotocol/clientInfo": {
          "name": "manual-test",
          "version": "1.0.0"
        }
      }
    }
  }' \
  'https://example.com/wp-json/wp-nerve/v1/mcp'
```

**List tools:**

```bash
curl --user 'USERNAME:APPLICATION_PASSWORD' \
  --header 'Content-Type: application/json' \
  --header 'MCP-Protocol-Version: 2026-07-28' \
  --header 'Mcp-Method: tools/list' \
  --data '{
    "jsonrpc": "2.0",
    "id": 2,
    "method": "tools/list",
    "params": {
      "_meta": {
        "io.modelcontextprotocol/protocolVersion": "2026-07-28",
        "io.modelcontextprotocol/clientCapabilities": {},
        "io.modelcontextprotocol/clientInfo": {
          "name": "manual-test",
          "version": "1.0.0"
        }
      }
    }
  }' \
  'https://example.com/wp-json/wp-nerve/v1/mcp'
```

**Call a tool:**

```bash
curl --user 'USERNAME:APPLICATION_PASSWORD' \
  --header 'Content-Type: application/json' \
  --header 'MCP-Protocol-Version: 2026-07-28' \
  --header 'Mcp-Method: tools/call' \
  --header 'Mcp-Name: wp_nerve_site_status' \
  --data '{
    "jsonrpc": "2.0",
    "id": 3,
    "method": "tools/call",
    "params": {
      "name": "wp_nerve_site_status",
      "arguments": {},
      "_meta": {
        "io.modelcontextprotocol/protocolVersion": "2026-07-28",
        "io.modelcontextprotocol/clientCapabilities": {},
        "io.modelcontextprotocol/clientInfo": {
          "name": "manual-test",
          "version": "1.0.0"
        }
      }
    }
  }' \
  'https://example.com/wp-json/wp-nerve/v1/mcp'
```

### Security posture

- The endpoint is private and requires an authenticated WordPress user.
- Production HTTP without TLS is rejected.
- Tool discovery and execution both pass through the same policy engine.
- MCP mirrored headers are checked against the JSON-RPC body.
- Unknown external WordPress abilities are not exposed automatically.
- Tool arguments, authorization headers, and Application Passwords are never
  written to the WPNerve audit table.

Report vulnerabilities privately according to [SECURITY.md](SECURITY.md).

### Requirements

- WordPress 6.9 or newer.
- PHP 8.1 or newer.
- HTTPS in production.
- Pretty permalinks and the WordPress REST API available.

### Development

```bash
composer install
composer check        # lint + PHPCS + PHPStan level 8 + PHPUnit
composer test         # PHPUnit only
```

`composer check` runs PHP syntax validation, coding standards, PHPStan level 8,
and the unit test suite (114 tests, ~96% line coverage). CI runs the same checks
on PHP 8.1, 8.3, and 8.5, verifies version consistency, and publishes a coverage
report on every push.

### Documentation

- [Architecture](docs/architecture.md)
- [Threat model](docs/threat-model.md)
- [Ability catalog v1](docs/abilities-v1.md)
- [Architecture decision records](docs/adr/)

### License

GPL-2.0-or-later. See [LICENSE](LICENSE).

---

## Español

### ¿Qué es WPNerve?

WPNerve convierte a WordPress en un ciudadano de primera clase para los agentes
de IA. En lugar de abrir agujeros en `wp-admin` o usar un relay SaaS que guarda
tus credenciales, instalás un plugin y obtenés un **endpoint MCP privado y
autenticado** en tu propio sitio. Un agente puede entonces inspeccionar de forma
segura —y en futuros milestones, gestionar— contenido usando las mismas
capacidades y permisos de WordPress que ya administrás en el panel.

- **Nativo.** Construido sobre la Abilities API nativa de WordPress 6.9+, no
  sobre un registro de acciones paralelo.
- **Self-hosted.** Corre dentro de WordPress. Ningún servicio externo ve tus
  pedidos o credenciales.
- **Seguro por defecto.** HTTPS obligatorio en producción, autenticación con
  Application Passwords, validación de headers reflejados y un policy engine
  central que deniega acciones destructivas y privilegiadas.

### Por qué WPNerve

- Abilities API nativa de WordPress 6.9+, no un registro paralelo de acciones.
- MCP `2026-07-28` stateless HTTP más compatibilidad con clientes `2025-11-25`
  y `2025-06-18`.
- Autenticación con Application Password de WordPress sobre HTTPS.
- Un gate central de políticas separado de la lógica de negocio de las abilities.
- Descubrimiento de herramientas con mínimo privilegio: cada usuario ve solo las
  abilities que puede ejecutar.
- Eventos de auditoría que preservan la privacidad: sin credenciales ni
  argumentos de herramientas.
- Clases de riesgo destructivas y privilegiadas denegadas por defecto.

### Arquitectura

```mermaid
flowchart LR
    A["Cliente MCP"] --> B["Transporte HTTP"]
    B --> C["Autenticación"]
    C --> D["Policy engine"]
    D --> E["Abilities API"]
    E --> F["WordPress"]
    D --> G["Audit log"]
```

Las capas de protocolo, transporte, políticas y abilities están separadas a
propósito. Una revisión futura del protocolo puede reemplazar el transporte y el
dispatcher sin reescribir las operaciones de contenido. Ver
[Arquitectura](docs/architecture.md) y [Modelo de amenazas](docs/threat-model.md).

### Inicio rápido

1. **Instalá y activá** el plugin en un sitio con WordPress 6.9+ (PHP 8.1+).
2. Abrí **Herramientas → WPNerve** para ver tu endpoint MCP:
   `https://tu-sitio.com/wp-json/wp-nerve/v1/mcp`
3. En **Herramientas → WPNerve**, seleccioná un usuario de WordPress dedicado
   con sólo las capacidades necesarias y pulsá **Generate WPNerve credential**.
4. Copiá el secreto de única visualización o la configuración lista para usar.
   WPNerve verifica la credencial sin persistirla.
5. Revocá las credenciales desde la misma pantalla cuando retires un cliente o
   pierdas un dispositivo.

Nunca commitees ni compartas la Application Password. Si perdés un cliente o
dispositivo, revocá la contraseña de inmediato.

### Catálogo de herramientas

| Herramienta | Descripción | Riesgo |
|---|---|---|
| `wp_nerve_site_status` | Diagnóstico no sensible del sitio y de WPNerve | Lectura |
| `wp_nerve_list_content_types` | Tipos de contenido públicos con info de REST y supports | Lectura |
| `wp_nerve_search_content` | Búsqueda de contenido con filtros de tipo, estado y paginación | Lectura |
| `wp_nerve_get_content` | Post individual con contenido completo, según estado y capacidad | Lectura |

Planeado para el milestone v1: revisiones, creación segura de borradores,
actualizaciones de contenido, taxonomías, medios y comentarios. Las operaciones
destructivas y privilegiadas siguen denegadas hasta pasar la revisión de
política, preview y recuperación. Ver el
[catálogo completo de abilities](docs/abilities-v1.md).

### Postura de seguridad

- El endpoint es privado y requiere un usuario de WordPress autenticado.
- HTTP sin TLS en producción es rechazado.
- El descubrimiento y la ejecución de herramientas pasan por el mismo policy
  engine.
- Los headers MCP reflejados se verifican contra el cuerpo JSON-RPC.
- Las abilities externas desconocidas de WordPress no se exponen automáticamente.
- Los argumentos de herramientas, headers de autorización y Application
  Passwords nunca se escriben en la tabla de auditoría.

Reportá vulnerabilidades de forma privada según [SECURITY.md](SECURITY.md).

### Requisitos

- WordPress 6.9 o superior.
- PHP 8.1 o superior.
- HTTPS en producción.
- Permalinks bonitos y REST API de WordPress disponible.

### Desarrollo

```bash
composer install
composer check        # lint + PHPCS + PHPStan nivel 8 + PHPUnit
composer test         # solo PHPUnit
```

`composer check` corre validación de sintaxis PHP, estándares de código, PHPStan
nivel 8 y la suite de tests unitarios (114 tests, ~96% de cobertura de líneas).
La CI corre los mismos chequeos en PHP 8.1, 8.3 y 8.5, verifica la consistencia
de versiones y publica un reporte de cobertura en cada push.

### Licencia

GPL-2.0-or-later. Ver [LICENSE](LICENSE).

---

## Autor

Creado por **Akela** · [@akelaonline](https://www.instagram.com/akelaonline/) · [akela.dev](https://akela.dev/seo)

- **Instagram:** [@akelaonline](https://www.instagram.com/akelaonline/)
- **Email:** [adjose@gmail.com](mailto:adjose@gmail.com)
