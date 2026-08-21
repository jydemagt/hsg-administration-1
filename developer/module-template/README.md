# Nyt HSG-modul

1. Kopiér mappen til `modules/<nyt_id>/`.
2. Omdøb `.example`-filerne.
3. Ret manifest og migrations.
4. Tilføj modulets PHP-sider i roden eller en dedikeret modulcontroller.
5. Brug `require_capability()`/`require_admin()` på alle sider.
6. Forøg modulversionen, når databasen ændres.

Modulet registreres automatisk af platformen.
