/*
# Updater: System Settings & Update Log
*/

CREATE TABLE IF NOT EXISTS system_settings (
  id integer PRIMARY KEY DEFAULT 1 CHECK (id = 1),
  github_repo text DEFAULT '',
  github_branch text DEFAULT 'main',
  installed_version text DEFAULT '',
  installed_version_date timestamptz DEFAULT now(),
  auto_update boolean DEFAULT false,
  updated_at timestamptz DEFAULT now()
);

CREATE TABLE IF NOT EXISTS update_log (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  version text NOT NULL DEFAULT '',
  commit_message text DEFAULT '',
  status text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'applied', 'failed')),
  applied_by text DEFAULT '',
  created_at timestamptz DEFAULT now()
);

INSERT INTO system_settings (id) VALUES (1) ON CONFLICT (id) DO NOTHING;

ALTER TABLE system_settings ENABLE ROW LEVEL SECURITY;
ALTER TABLE update_log ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS "select_settings" ON system_settings;
CREATE POLICY "select_settings" ON system_settings FOR SELECT TO authenticated USING (true);
DROP POLICY IF EXISTS "insert_settings" ON system_settings;
CREATE POLICY "insert_settings" ON system_settings FOR INSERT TO authenticated WITH CHECK (true);
DROP POLICY IF EXISTS "update_settings" ON system_settings;
CREATE POLICY "update_settings" ON system_settings FOR UPDATE TO authenticated USING (true) WITH CHECK (true);
DROP POLICY IF EXISTS "delete_settings" ON system_settings;
CREATE POLICY "delete_settings" ON system_settings FOR DELETE TO authenticated USING (true);

DROP POLICY IF EXISTS "select_update_log" ON update_log;
CREATE POLICY "select_update_log" ON update_log FOR SELECT TO authenticated USING (true);
DROP POLICY IF EXISTS "insert_update_log" ON update_log;
CREATE POLICY "insert_update_log" ON update_log FOR INSERT TO authenticated WITH CHECK (true);
DROP POLICY IF EXISTS "update_update_log" ON update_log;
CREATE POLICY "update_update_log" ON update_log FOR UPDATE TO authenticated USING (true) WITH CHECK (true);
DROP POLICY IF EXISTS "delete_update_log" ON update_log;
CREATE POLICY "delete_update_log" ON update_log FOR DELETE TO authenticated USING (true);

CREATE INDEX IF NOT EXISTS idx_update_log_created_at ON update_log(created_at DESC);