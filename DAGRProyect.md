# 🗺️ DAGR Project — Mapa Tactico en la Web

**Autor:** [=TFR=] Task Force Recon  
**Fecha:** Mayo 2026  
**Estado:** Fase 1 y 2 completadas

---

## Objetivo

Mapa tactico de Arma Reforger en la web con posiciones de jugadores en tiempo real y marcadores de objetivos via ORBAT Link.

---

## ✅ Implementado

### Shortcode `[rmm_tactical_map]`

```
[rmm_tactical_map]                    # Auto-detecta partida activa
[rmm_tactical_map map="everon"]       # Mapa manual
[rmm_tactical_map map="everon" height="800px"]
```

- Handler: `includes/class-dagr-handler.php`
- Documentado en admin (36 shortcodes)

### Base de datos

- Tabla: `wp_rmm_dagr_maps`
- Defaults: Everon (12800x12800) y Arland (4000x4000)
- Admin: Reforger MILSIM → 🗺️ Mapas DAGR

### LeafletJS

- CRS personalizado, TileLayer Y invertido, zoom reverso (LOD)
- Tiles locales con fallback a CDN recoil.org
- 660 tiles de Everon descargados y en servidor

### Toggle Personal / Global

- Botones en el mapa: `👤 Yo` | `🌍 Global`
- Verde (tu) / Azul (otros)
- Guardado en localStorage

### Marcadores

- Iconos CSS: rombo, circulo, triangulo, cuadrado
- Tipos: objective, completed, danger, info, marker
- REST: GET/POST `/wp-json/clan/v1/dagr/markers`
- Procesado automatico desde el payload de telemetria

### Posiciones

- REST: `GET /wp-json/clan/v1/dagr/positions?map=everon`
- Polling cada 10s
- Campos: pos_x, pos_y, pos_z, heading, map

### Payload completo ORBAT Link

```json
{
  "token": "TFR_...",
  "scenario_name": "TFR DOBRAVKA OKHOTA",
  "map": "everon",
  "markers": [
    { "id": "obj1", "type": "objective", "label": "Capturar base", "pos_x": 5420, "pos_y": 3210 }
  ],
  "players": [{
    "steamid": "76561198...",
    "kills": 4, "deaths": 1,
    "pos_x": 5420.5, "pos_y": 3210.8, "pos_z": 15.2, "heading": 180,
    "...": "..."
  }]
}
```

---

## Archivos

| Archivo | Rol |
|---|---|
| `includes/class-dagr-handler.php` | Shortcode, REST, admin |
| `includes/class-db-handler.php` | Tabla wp_rmm_dagr_maps |
| `includes/class-telemetry-handler.php` | Procesa posiciones y marcadores |
| `tiles/everon-d012/LODS/` | Tiles Everon (.gitignored) |
| `download-tiles.sh` | Descarga tiles desde CDN |
| `EnfusionMapMaker-main/` | Referencia (.gitignored) |

---

## Pendiente

- [ ] Capas de POIs (suministros, vehiculos)
- [ ] Rotacion de iconos por heading
- [ ] Tiles de Arland y Kolguyev
- [ ] Control de visibilidad de posiciones
- [ ] Marcadores persistentes (no solo transient)
