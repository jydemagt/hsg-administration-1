import { useEffect, useState, useCallback } from 'react';
import { Download, Loader2, RefreshCw, CheckCircle2, AlertCircle, GitBranch, GitCommit, Tag, ExternalLink, Settings, Check, Clock, History } from 'lucide-react';
import { supabase } from '@/lib/supabase';
import { useAuth } from '@/context/AuthContext';
import { formatRelative, formatDate } from '@/lib/format';
import { PageHeader, Card, EmptyState } from '@/components/ui/Page';
import { Button, Field, TextInput } from '@/components/ui/Form';

interface Commit { sha: string; message: string; author: string; date: string; html_url: string; is_installed: boolean; }
interface Release { tag_name: string; name: string; body: string; published_at: string; html_url: string; prerelease: boolean; }
interface UpdateLogEntry { id: string; version: string; commit_message: string; status: string; applied_by: string; created_at: string; }
interface GithubData { repo: string; branch: string; installed_version: string; latest_sha: string; up_to_date: boolean; commits: Commit[]; releases: Release[]; }
interface Settings { github_repo: string; github_branch: string; installed_version: string; installed_version_date: string; }

const functionUrl = `${import.meta.env.VITE_SUPABASE_URL}/functions/v1/github-updater`;

export default function UpdaterPage() {
  const { user } = useAuth();
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [data, setData] = useState<GithubData | null>(null);
  const [logs, setLogs] = useState<UpdateLogEntry[]>([]);
  const [settings, setSettings] = useState<Settings | null>(null);
  const [showSettings, setShowSettings] = useState(false);
  const [repoInput, setRepoInput] = useState('');
  const [branchInput, setBranchInput] = useState('');
  const [savingSettings, setSavingSettings] = useState(false);
  const [applyingSha, setApplyingSha] = useState<string | null>(null);
  const [tab, setTab] = useState<'commits' | 'releases' | 'history'>('commits');

  const fetchSettings = useCallback(async () => {
    const { data: s } = await supabase.from('system_settings').select('*').eq('id', 1).maybeSingle();
    return s as Settings | null;
  }, []);

  const fetchGithub = useCallback(async (showSpinner = false) => {
    if (showSpinner) setRefreshing(true);
    setError(null);
    const headers = { Authorization: `Bearer ${import.meta.env.VITE_SUPABASE_ANON_KEY}`, 'Content-Type': 'application/json' };
    const res = await fetch(`${functionUrl}?action=commits`, { headers });
    const json = await res.json();
    if (showSpinner) setRefreshing(false);
    if (!res.ok) { setError(json.error || 'Kunne ikke hente opdateringer fra GitHub.'); setData(null); return; }
    setData(json as GithubData);
  }, []);

  const fetchLogs = useCallback(async () => {
    const headers = { Authorization: `Bearer ${import.meta.env.VITE_SUPABASE_ANON_KEY}`, 'Content-Type': 'application/json' };
    const res = await fetch(`${functionUrl}?action=log`, { headers });
    const json = await res.json();
    if (res.ok && json.logs) setLogs(json.logs as UpdateLogEntry[]);
  }, []);

  useEffect(() => {
    (async () => {
      setLoading(true);
      const s = await fetchSettings();
      if (s) { setSettings(s); setRepoInput(s.github_repo); setBranchInput(s.github_branch); if (!s.github_repo) setShowSettings(true); }
      if (s && s.github_repo) await fetchGithub();
      await fetchLogs();
      setLoading(false);
    })();
  }, []);

  async function handleSaveSettings() {
    setSavingSettings(true);
    const { error } = await supabase.from('system_settings').update({ github_repo: repoInput.trim(), github_branch: branchInput.trim() || 'main', updated_at: new Date().toISOString() }).eq('id', 1);
    setSavingSettings(false);
    if (error) { setError('Kunne ikke gemme indstillinger.'); return; }
    setSettings({ github_repo: repoInput.trim(), github_branch: branchInput.trim() || 'main', installed_version: settings?.installed_version ?? '', installed_version_date: settings?.installed_version_date ?? '' });
    setShowSettings(false);
    await fetchGithub(true);
  }

  async function handleApplyVersion(sha: string, message: string) {
    setApplyingSha(sha);
    const headers = { Authorization: `Bearer ${import.meta.env.VITE_SUPABASE_ANON_KEY}`, 'Content-Type': 'application/json' };
    const res = await fetch(`${functionUrl}?action=apply`, { method: 'POST', headers, body: JSON.stringify({ version: sha, commit_message: message.split('\n')[0], applied_by: user?.email ?? '' }) });
    const json = await res.json();
    setApplyingSha(null);
    if (!res.ok) { setError(json.error || 'Kunne ikke registrere opdateringen.'); return; }
    await Promise.all([fetchGithub(true), fetchLogs()]);
  }

  function shortSha(sha: string) { return sha.substring(0, 7); }

  if (loading) return <div className="flex justify-center py-16 text-slate-400"><Loader2 className="h-6 w-6 animate-spin" /></div>;

  const hasRepo = settings?.github_repo;

  return (
    <div>
      <PageHeader title="Opdateringer" description="Hold systemet opdateret med de seneste ændringer fra GitHub." action={
        <div className="flex items-center gap-2">
          {hasRepo && <Button variant="secondary" onClick={() => fetchGithub(true)} disabled={refreshing}><RefreshCw className={`h-4 w-4 ${refreshing ? 'animate-spin' : ''}`} />Tjek for opdateringer</Button>}
          <Button variant="ghost" onClick={() => setShowSettings(!showSettings)}><Settings className="h-4 w-4" />Indstillinger</Button>
        </div>
      } />

      {error && <div className="mb-4 flex items-start gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><AlertCircle className="h-5 w-5 shrink-0" /><span>{error}</span></div>}

      {showSettings && (
        <Card className="mb-6 p-6">
          <h3 className="text-lg font-semibold text-slate-900 mb-1">GitHub-indstillinger</h3>
          <p className="text-sm text-slate-500 mb-4">Indstil hvilket repository og branch systemet skal hente opdateringer fra.</p>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Repository (f.eks. jydemagt/hsg-administration-1)"><TextInput value={repoInput} onChange={(e) => setRepoInput(e.target.value)} placeholder="brugernavn/repository" /></Field>
            <Field label="Branch"><TextInput value={branchInput} onChange={(e) => setBranchInput(e.target.value)} placeholder="main" /></Field>
          </div>
          <div className="mt-4 flex gap-3">
            <Button onClick={handleSaveSettings} disabled={savingSettings || !repoInput.trim()}>{savingSettings ? <Loader2 className="h-4 w-4 animate-spin" /> : <Check className="h-4 w-4" />}Gem indstillinger</Button>
            <Button variant="secondary" onClick={() => setShowSettings(false)}>Annuller</Button>
          </div>
        </Card>
      )}

      {!hasRepo && !showSettings ? (
        <EmptyState icon={<Download className="h-6 w-6" />} title="Ingen GitHub-repo forbundet" description="For at hente opdateringer skal du først indstille dit GitHub-repository." action={<Button onClick={() => setShowSettings(true)}><Settings className="h-4 w-4" />Konfigurer nu</Button>} />
      ) : data ? (
        <div className="space-y-6">
          <Card className="p-5">
            <div className="flex flex-wrap items-center justify-between gap-4">
              <div className="flex items-center gap-3">
                {data.up_to_date ? <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600"><CheckCircle2 className="h-6 w-6" /></div> : <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-blue-50 text-blue-600"><Download className="h-6 w-6" /></div>}
                <div><p className="font-semibold text-slate-900">{data.up_to_date ? 'Systemet er opdateret' : 'Ny opdatering tilgængelig'}</p><p className="text-sm text-slate-500">{data.up_to_date ? 'Du kører den nyeste version.' : 'Der er nye ændringer siden din sidste opdatering.'}</p></div>
              </div>
              <div className="flex flex-col items-end gap-1 text-sm">
                <div className="flex items-center gap-2 text-slate-600"><GitBranch className="h-4 w-4 text-slate-400" /><span className="font-medium">{data.branch}</span></div>
                {data.installed_version && <div className="flex items-center gap-2 text-slate-400"><GitCommit className="h-4 w-4" /><span>Installeret: {shortSha(data.installed_version)}</span></div>}
                {data.latest_sha && <div className="flex items-center gap-2 text-slate-400"><GitCommit className="h-4 w-4" /><span>Nyeste: {shortSha(data.latest_sha)}</span></div>}
              </div>
            </div>
          </Card>

          <div className="flex gap-1 border-b border-slate-200">
            {[{ id: 'commits' as const, label: 'Seneste ændringer', icon: GitCommit }, { id: 'releases' as const, label: 'Udgivelser', icon: Tag }, { id: 'history' as const, label: 'Historik', icon: History }].map((t) => {
              const Icon = t.icon;
              return <button key={t.id} onClick={() => setTab(t.id)} className={`flex items-center gap-2 border-b-2 px-4 py-2.5 text-sm font-medium transition ${tab === t.id ? 'border-blue-600 text-blue-600' : 'border-transparent text-slate-500 hover:text-slate-700'}`}><Icon className="h-4 w-4" />{t.label}</button>;
            })}
          </div>

          {tab === 'commits' && (
            <Card><ul className="divide-y divide-slate-100">
              {data.commits.map((c) => {
                const isApplied = c.is_installed;
                const isLatest = c.sha === data.latest_sha;
                return (
                  <li key={c.sha} className="flex items-start gap-4 px-5 py-4">
                    <div className={`mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ${isApplied ? 'bg-emerald-50 text-emerald-600' : 'bg-slate-100 text-slate-400'}`}>{isApplied ? <Check className="h-4 w-4" /> : <GitCommit className="h-4 w-4" />}</div>
                    <div className="min-w-0 flex-1">
                      <p className="font-medium text-slate-900">{c.message.split('\n')[0]}</p>
                      <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-400">
                        <span className="font-mono">{shortSha(c.sha)}</span><span>af {c.author}</span><span>{formatRelative(c.date)}</span>
                        {isApplied && <span className="rounded-full bg-emerald-50 px-2 py-0.5 font-medium text-emerald-600">Installeret</span>}
                        {isLatest && !isApplied && <span className="rounded-full bg-blue-50 px-2 py-0.5 font-medium text-blue-600">Nyeste</span>}
                        <a href={c.html_url} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 hover:text-slate-600"><ExternalLink className="h-3 w-3" />Se på GitHub</a>
                      </div>
                    </div>
                    {!isApplied && <Button variant="secondary" className="shrink-0" onClick={() => handleApplyVersion(c.sha, c.message)} disabled={applyingSha === c.sha}>{applyingSha === c.sha ? <Loader2 className="h-4 w-4 animate-spin" /> : <Download className="h-4 w-4" />}Markér som installeret</Button>}
                  </li>
                );
              })}
            </ul></Card>
          )}

          {tab === 'releases' && (
            <div className="space-y-4">
              {data.releases.length === 0 ? <EmptyState icon={<Tag className="h-6 w-6" />} title="Ingen udgivelser" description="Dette repository har ingen udgivelser endnu." /> : data.releases.map((r) => (
                <Card key={r.tag_name} className="p-5">
                  <div className="flex items-start justify-between gap-4"><div className="min-w-0">
                    <div className="flex items-center gap-2"><span className="flex items-center gap-1.5 rounded-lg bg-blue-50 px-2.5 py-1 text-sm font-semibold text-blue-700"><Tag className="h-3.5 w-3.5" />{r.tag_name}</span>{r.prerelease && <span className="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-600">Forudgivet</span>}</div>
                    <h3 className="mt-2 text-lg font-semibold text-slate-900">{r.name || r.tag_name}</h3>
                    {r.body && <p className="mt-2 whitespace-pre-wrap text-sm text-slate-600">{r.body}</p>}
                    <div className="mt-3 flex items-center gap-3 text-xs text-slate-400"><span className="flex items-center gap-1"><Clock className="h-3 w-3" />{formatDate(r.published_at)}</span><a href={r.html_url} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 hover:text-slate-600"><ExternalLink className="h-3 w-3" />Se på GitHub</a></div>
                  </div></div>
                </Card>
              ))}
            </div>
          )}

          {tab === 'history' && (
            <Card>{logs.length === 0 ? <p className="px-5 py-8 text-center text-sm text-slate-400">Ingen opdateringer er blevet registreret endnu.</p> : <ul className="divide-y divide-slate-100">{logs.map((log) => (
              <li key={log.id} className="flex items-start gap-4 px-5 py-4">
                <div className={`mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ${log.status === 'applied' ? 'bg-emerald-50 text-emerald-600' : log.status === 'failed' ? 'bg-red-50 text-red-600' : 'bg-amber-50 text-amber-600'}`}>{log.status === 'applied' ? <Check className="h-4 w-4" /> : log.status === 'failed' ? <AlertCircle className="h-4 w-4" /> : <Clock className="h-4 w-4" />}</div>
                <div className="min-w-0 flex-1"><p className="font-medium text-slate-900">{log.commit_message || 'Opdatering'}</p><div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-400"><span className="font-mono">{shortSha(log.version)}</span>{log.applied_by && <span>af {log.applied_by}</span>}<span>{formatDate(log.created_at)}</span><span className={`rounded-full px-2 py-0.5 font-medium ${log.status === 'applied' ? 'bg-emerald-50 text-emerald-600' : log.status === 'failed' ? 'bg-red-50 text-red-600' : 'bg-amber-50 text-amber-600'}`}>{log.status === 'applied' ? 'Anvendt' : log.status === 'failed' ? 'Fejlet' : 'Afventer'}</span></div></div>
              </li>
            ))}</ul>}</Card>
          )}
        </div>
      ) : null}
    </div>
  );
}