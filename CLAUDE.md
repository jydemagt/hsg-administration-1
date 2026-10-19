# HSG Administration – projektregler for alle agenter

Denne fil læses automatisk af Claude Code og af alle agenter i `.claude/agents/`.
Den er "grundloven": agenterne må ikke bryde reglerne herunder.

## Hvad systemet er
PHP 8.1+/MySQL-administrations- og lagersystem for HSG Whisky ApS. Kører på et
almindeligt dansk webhotel. **Ingen Docker, Composer, Node eller serverprocesser i produktion.**
Arkitektur: `docs/ARCHITECTURE.md`. Forretningskrav: `JULES-HANDOFF/HSG_Jules_Handoff.md`.

## Mappestruktur
- `core/` – fælles platform (rettigheder, moduler, hooks, audit, backup, updater)
- `modules/<id>/module.php` – manifest pr. forretningsområde, evt. `migrations.php`, `hooks.php`
- Sider ligger i roden (`products.php`, `stock.php` …)
- `tools/build-release.php` – bygger release-ZIP og `hsg-package.json`
- `tests/` – udviklingstests (kommer ALDRIG med i release)

## Ufravigelige regler
1. **Byg aldrig om fra bunden.** Bevar database, installflow, updater og eksisterende funktioner. Bagudkompatibelt.
2. **Databaseændringer kun via migrations** (`modules/<id>/migrations.php` eller `migrations.php`) og hæv modulversionen.
3. **Sikkerhed på hver side:** `require_capability()` eller `require_admin()`. Alle POST-formularer: `csrf_field()` + `verify_csrf()`. Al SQL med brugerinput: prepared statements. Al output: `h()`.
4. **Direkte brugerlinks er read-only** undtagen det admin eksplicit har givet (fx reservationer).
5. **Rør aldrig** `config.php`, `config.local.php`, `.env`, `uploads/`, `storage/backups/`, `storage/data/`.
6. Administratorhandlinger logges i audit-loggen.
7. Mobile-first på lagerdelen. Brugerflade og tekster er på **dansk**.

## Release-tjekliste (hver ny version)
- `bash tests/run.sh` er grøn
- Version opdateret konsekvent (`app_version.php`, README, manifest)
- `hsg-package.json` regenereret via `php tools/build-release.php <version>`
- Ingen test-/dev-filer i ZIP'en (fejlen med `test-catalog-v151.pdf` må ikke gentage sig)

## Arbejdsgang med agenter
Brug `/feature <beskrivelse>` til nye ønsker. Flowet er:
**designer → (din godkendelse) → udvikler → tester → reviewer → (din godkendelse af merge)**.
Hver agent arbejder på en feature-branch, aldrig direkte på `main`.
Specs gemmes i `docs/specs/<dato>-<navn>.md`, så beslutninger kan findes igen.
