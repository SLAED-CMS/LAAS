# Bootstrap Pipeline

The bootstrap pipeline is opt-in and only runs when bootstraps are enabled.
Default behavior is unchanged unless explicitly enabled.

## Order (default)
Security -> Observability -> Modules -> Routing -> View

## Flags
Environment variables and their config keys:
- APP_BOOTSTRAPS_ENABLED (app.bootstraps_enabled)
- APP_BOOTSTRAPS (app.bootstraps; comma-separated tokens or known FQCNs)
- APP_BOOTSTRAPS_MODULES_TAKEOVER (app.bootstraps_modules_takeover)
- APP_ROUTING_CACHE_WARM (app.routing_cache_warm)
- APP_ROUTING_CACHE_WARM_FORCE (app.routing_cache_warm_force)
- APP_VIEW_SANITY_STRICT (app.view_sanity_strict)

## Example .env
All flags are off by default. Example enabling in dev:

```env
APP_BOOTSTRAPS_ENABLED=1
APP_BOOTSTRAPS="security,observability,modules,routing,view"
APP_BOOTSTRAPS_MODULES_TAKEOVER=1
APP_ROUTING_CACHE_WARM=1
APP_ROUTING_CACHE_WARM_FORCE=0
APP_VIEW_SANITY_STRICT=0
```

## Notes
- Default behavior is unchanged unless enabled.
- Modules takeover is experimental and opt-in.
- Routing cache warm is optional.

## Migration checklist PR1–PR8
- PR1: config flags in app.php
- PR2: move Bootstrap to src/Bootstrap
- PR3: SecurityBootstrap scaffold
- PR4: ObservabilityBootstrap + RequestId single source
- PR5: ModulesBootstrap scaffold/takeover
- PR6: resolver extracted + ordering hardened
- PR7: APP_BOOTSTRAPS parsing + FQCN support
- PR8: docs finalization
