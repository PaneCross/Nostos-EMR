# NostosEMR — React Reference Codebase (Frozen)

This repository is the **original React 18 + Laravel 11** version of NostosEMR.

## Status: FROZEN — not actively run

This codebase is preserved as a reference only. It is not wired up to run locally
and should not be modified going forward.

## What to use instead

The active codebase has been migrated to **Vue 3.5 + Laravel 12**:

- **Active repo:** https://github.com/PaneCross/Nostos-EMR-Vue
- **WSL2 path:** `/home/tj/projects/nostosemr-vue`
- **Windows path:** `C:\Users\TJ\Desktop\PACE EMR\nostosemr-vue\`

## Why this exists

The React version was fully built out through Wave 5 (1,560+ tests, 63 pages,
105 migrations, complete PACE EMR feature set). It serves as a reference for:

- The original React component structure and logic
- PHP backend patterns (all backend code was copied to the Vue repo unchanged)
- Historical phase-by-phase development record

## What changed in the Vue migration

Only the frontend changed. The PHP backend (models, controllers, services, migrations,
tests) is identical between both repos. The Vue repo replaces all `.tsx` React pages
with `.vue` Single File Components.

## Contact

tj@nostos.tech
