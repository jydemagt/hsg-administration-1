import { createClient } from 'npm:@supabase/supabase-js@2.57.4';

const corsHeaders = {
  "Access-Control-Allow-Origin": "*",
  "Access-Control-Allow-Methods": "GET, POST, PUT, DELETE, OPTIONS",
  "Access-Control-Allow-Headers": "Content-Type, Authorization, X-Client-Info, Apikey",
};

interface GitHubCommit {
  sha: string;
  commit: { message: string; author: { name: string; date: string }; };
  html_url: string;
}

interface GitHubRelease {
  tag_name: string;
  name: string;
  body: string;
  published_at: string;
  html_url: string;
  prerelease: boolean;
  draft: boolean;
}

Deno.serve(async (req: Request) => {
  if (req.method === "OPTIONS") {
    return new Response(null, { status: 200, headers: corsHeaders });
  }

  try {
    const url = new URL(req.url);
    const action = url.searchParams.get('action') ?? 'commits';

    const supabaseUrl = Deno.env.get('SUPABASE_URL') ?? '';
    const serviceKey = Deno.env.get('SUPABASE_SERVICE_ROLE_KEY') ?? '';
    const supabase = createClient(supabaseUrl, serviceKey);

    const { data: settings, error: settingsErr } = await supabase
      .from('system_settings').select('github_repo, github_branch, installed_version').eq('id', 1).maybeSingle();

    if (settingsErr || !settings || !settings.github_repo) {
      return new Response(JSON.stringify({ error: 'GitHub-repo er ikke konfigureret. Indstil det under Opdateringer.' }), { status: 400, headers: { ...corsHeaders, "Content-Type": "application/json" } });
    }

    const repo = settings.github_repo;
    const branch = settings.github_branch || 'main';
    const installedVersion = settings.installed_version || '';
    const githubToken = Deno.env.get('GITHUB_TOKEN') ?? '';
    const ghHeaders: Record<string, string> = { 'Accept': 'application/vnd.github+json', 'X-GitHub-Api-Version': '2022-11-28', 'User-Agent': 'HSG-Admin-Updater' };
    if (githubToken) ghHeaders['Authorization'] = `Bearer ${githubToken}`;

    if (action === 'commits') {
      const commitsUrl = `https://api.github.com/repos/${repo}/commits?sha=${encodeURIComponent(branch)}&per_page=15`;
      const commitsRes = await fetch(commitsUrl, { headers: ghHeaders });
      if (!commitsRes.ok) {
        const errMsg = commitsRes.status === 404 ? `Repository "${repo}" blev ikke fundet, eller branch "${branch}" findes ikke.` : `Kunne ikke hente commits fra GitHub (status ${commitsRes.status}).`;
        return new Response(JSON.stringify({ error: errMsg }), { status: commitsRes.status, headers: { ...corsHeaders, "Content-Type": "application/json" } });
      }
      const commits = (await commitsRes.json()) as GitHubCommit[];
      const releasesUrl = `https://api.github.com/repos/${repo}/releases?per_page=5`;
      const releasesRes = await fetch(releasesUrl, { headers: ghHeaders });
      const releases = releasesRes.ok ? ((await releasesRes.json()) as GitHubRelease[]) : [];
      const branchUrl = `https://api.github.com/repos/${repo}/branches/${encodeURIComponent(branch)}`;
      const branchRes = await fetch(branchUrl, { headers: ghHeaders });
      const branchInfo = branchRes.ok ? await branchRes.json() : null;
      const latestSha = (branchInfo as { commit?: { sha?: string } })?.commit?.sha ?? '';
      const upToDate = installedVersion && latestSha && installedVersion === latestSha;

      return new Response(JSON.stringify({
        repo, branch, installed_version: installedVersion, latest_sha: latestSha, up_to_date: !!upToDate,
        commits: commits.map((c) => ({ sha: c.sha, message: c.commit.message, author: c.commit.author.name, date: c.commit.author.date, html_url: c.html_url, is_installed: installedVersion === c.sha })),
        releases: releases.filter((r) => !r.draft).map((r) => ({ tag_name: r.tag_name, name: r.name, body: r.body, published_at: r.published_at, html_url: r.html_url, prerelease: r.prerelease })),
      }), { headers: { ...corsHeaders, "Content-Type": "application/json" } });
    }

    if (action === 'apply') {
      const body = await req.json().catch(() => ({}));
      const { version, commit_message, applied_by } = body as { version?: string; commit_message?: string; applied_by?: string };
      if (!version) return new Response(JSON.stringify({ error: 'Version er påkrævet.' }), { status: 400, headers: { ...corsHeaders, "Content-Type": "application/json" } });
      const { error: logErr } = await supabase.from('update_log').insert({ version, commit_message: commit_message || '', status: 'applied', applied_by: applied_by || '' });
      if (logErr) return new Response(JSON.stringify({ error: 'Kunne ikke registrere opdateringen.' }), { status: 500, headers: { ...corsHeaders, "Content-Type": "application/json" } });
      const { error: updErr } = await supabase.from('system_settings').update({ installed_version: version, installed_version_date: new Date().toISOString(), updated_at: new Date().toISOString() }).eq('id', 1);
      if (updErr) return new Response(JSON.stringify({ error: 'Kunne ikke opdatere installeret version.' }), { status: 500, headers: { ...corsHeaders, "Content-Type": "application/json" } });
      return new Response(JSON.stringify({ success: true, version }), { headers: { ...corsHeaders, "Content-Type": "application/json" } });
    }

    if (action === 'log') {
      const { data: logs, error: logErr } = await supabase.from('update_log').select('*').order('created_at', { ascending: false }).limit(20);
      if (logErr) return new Response(JSON.stringify({ error: 'Kunne ikke hente opdateringslog.' }), { status: 500, headers: { ...corsHeaders, "Content-Type": "application/json" } });
      return new Response(JSON.stringify({ logs: logs ?? [] }), { headers: { ...corsHeaders, "Content-Type": "application/json" } });
    }

    return new Response(JSON.stringify({ error: 'Ukendt handling.' }), { status: 400, headers: { ...corsHeaders, "Content-Type": "application/json" } });
  } catch (err) {
    return new Response(JSON.stringify({ error: err instanceof Error ? err.message : 'Uventet fejl.' }), { status: 500, headers: { ...corsHeaders, "Content-Type": "application/json" } });
  }
});