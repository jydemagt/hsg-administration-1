# HSG Administration

Et webbaseret administrationssystem til styring af varekatalog og lager for HSG.

## Funktioner

- **Login-system** - Sikker adgang med e-mail og adgangskode (kun personale)
- **Oversigt/Dashboard** - Nøgletal for produkter, lager, lagerværdi og mærker
- **Produkter** - Fuldt varekatalog med varenummer, mærke, kategori, priser og lagerbeholdning
- **Lager** - Registrer og justér antal varer på tværs af lokationer
- **Mærker** - Administrer produktmærker
- **Kategorier** - Grupper produkter i kategorier
- **Lokationer** - Administrer lagre og butikker
- **Opdateringer** - Hent og installer opdateringer direkte fra GitHub

## Teknologi

- **Frontend:** React + TypeScript + Vite + Tailwind CSS
- **Backend:** Supabase (PostgreSQL, Auth, Edge Functions)
- **Icons:** Lucide React

## Opsætning

```bash
npm install
cp .env.example .env  # Udfyld Supabase-nøgler
npm run dev
```

## Miljøvariabler

```
VITE_SUPABASE_URL=...
VITE_SUPABASE_ANON_KEY=...
```

## Database

Migrations findes i `supabase/migrations/`. Tabeller:

- `brands` - Produktmærker
- `categories` - Produktkategorier
- `locations` - Lagerlokationer
- `products` - Produkter med pris, kostpris og genbestil-grænse
- `inventory` - Lagerbeholdning pr. produkt og lokation
- `system_settings` - Konfiguration af GitHub-opdateringer
- `update_log` - Historik over installerede opdateringer

Alle tabeller har Row Level Security aktiveret (kun for authenticated brugere).

## Edge Functions

- `github-updater` - Proxy for GitHub API til at hente commits, releases og registrere opdateringer