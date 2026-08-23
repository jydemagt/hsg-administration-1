import { useEffect, useState } from 'react';
import { Warehouse, Loader2, Check, Package, MapPin } from 'lucide-react';
import { supabase } from '@/lib/supabase';
import { Select } from '@/components/ui/Form';
import { PageHeader, EmptyState, Card } from '@/components/ui/Page';

interface Loc { id: string; name: string; }
interface Prod { id: string; sku: string; name: string; reorder_level: number; }

export default function InventoryPage() {
  const [locations, setLocations] = useState<Loc[]>([]);
  const [products, setProducts] = useState<Prod[]>([]);
  const [quantities, setQuantities] = useState<Record<string, number>>({});
  const [locationId, setLocationId] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [savingId, setSavingId] = useState<string | null>(null);
  const [savedId, setSavedId] = useState<string | null>(null);
  const [drafts, setDrafts] = useState<Record<string, string>>({});

  useEffect(() => {
    (async () => {
      const [locRes, prodRes] = await Promise.all([
        supabase.from('locations').select('id, name').order('name'),
        supabase.from('products').select('id, sku, name, reorder_level').order('name'),
      ]);
      if (locRes.error || prodRes.error) { setError('Kunne ikke hente data.'); setLoading(false); return; }
      const locs = (locRes.data ?? []) as Loc[];
      setLocations(locs);
      setProducts((prodRes.data ?? []) as Prod[]);
      if (locs.length > 0) setLocationId(locs[0].id);
      setLoading(false);
    })();
  }, []);

  useEffect(() => {
    if (!locationId) return;
    (async () => {
      const { data, error: err } = await supabase.from('inventory').select('product_id, quantity').eq('location_id', locationId);
      if (err) { setError('Kunne ikke hente lagerbeholdning.'); return; }
      const map: Record<string, number> = {};
      (data ?? []).forEach((r) => { map[r.product_id as string] = r.quantity as number; });
      setQuantities(map);
      setDrafts({});
      setError(null);
    })();
  }, [locationId]);

  async function save(productId: string) {
    const raw = drafts[productId];
    const qty = Math.max(0, parseInt(raw) || 0);
    setSavingId(productId);
    const { error: err } = await supabase.from('inventory').upsert(
      { product_id: productId, location_id: locationId, quantity: qty, updated_at: new Date().toISOString() },
      { onConflict: 'product_id,location_id' }
    );
    setSavingId(null);
    if (err) { setError('Kunne ikke gemme antallet.'); return; }
    setQuantities((q) => ({ ...q, [productId]: qty }));
    setDrafts((d) => { const next = { ...d }; delete next[productId]; return next; });
    setSavedId(productId);
    setTimeout(() => setSavedId((s) => (s === productId ? null : s)), 1500);
  }

  const totalUnits = Object.values(quantities).reduce((a, b) => a + b, 0);

  if (loading) return <div className="flex justify-center py-16 text-slate-400"><Loader2 className="h-6 w-6 animate-spin" /></div>;

  if (locations.length === 0 || products.length === 0) {
    return (
      <div>
        <PageHeader title="Lager" description="Registrer antal varer på hver lokation." />
        <EmptyState icon={<Warehouse className="h-6 w-6" />} title="Mangler opsætning" description={locations.length === 0 ? 'Opret mindst én lokation, før du kan registrere lager.' : 'Opret mindst ét produkt, før du kan registrere lager.'} />
      </div>
    );
  }

  return (
    <div>
      <PageHeader title="Lager" description="Registrer og justér antal varer på den valgte lokation." />
      {error && <div className="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{error}</div>}
      <div className="mb-4 flex flex-wrap items-center justify-between gap-4">
        <div className="flex items-center gap-2">
          <MapPin className="h-4 w-4 text-slate-400" />
          <div className="w-56"><Select value={locationId} onChange={(e) => setLocationId(e.target.value)}>{locations.map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}</Select></div>
        </div>
        <p className="text-sm text-slate-500">I alt på denne lokation: <span className="font-semibold text-slate-900">{totalUnits}</span> stk.</p>
      </div>
      <Card className="overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-slate-100 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                <th className="px-5 py-3">Produkt</th>
                <th className="px-5 py-3 text-right">Nuværende</th>
                <th className="px-5 py-3 w-48 text-right">Justér antal</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {products.map((p) => {
                const current = quantities[p.id] ?? 0;
                const draft = drafts[p.id];
                const dirty = draft !== undefined && String(current) !== draft;
                const low = current <= p.reorder_level;
                return (
                  <tr key={p.id} className="transition hover:bg-slate-50">
                    <td className="px-5 py-3"><div className="flex items-center gap-3"><div className="flex h-9 w-9 items-center justify-center rounded-lg bg-slate-100 text-slate-400"><Package className="h-4 w-4" /></div><div><p className="font-medium text-slate-900">{p.name}</p><p className="text-xs text-slate-400">{p.sku}</p></div></div></td>
                    <td className="px-5 py-3 text-right"><span className={`inline-block rounded-full px-2.5 py-0.5 text-xs font-medium ${low ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-slate-600'}`}>{current}</span></td>
                    <td className="px-5 py-3"><div className="flex items-center justify-end gap-2"><input type="number" min="0" value={draft ?? String(current)} onChange={(e) => setDrafts((d) => ({ ...d, [p.id]: e.target.value }))} className="w-24 rounded-lg border border-slate-300 bg-white px-3 py-2 text-right text-sm shadow-sm outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20" /><button onClick={() => save(p.id)} disabled={!dirty || savingId === p.id} className="flex h-9 w-9 items-center justify-center rounded-lg bg-blue-600 text-white transition hover:bg-blue-700 disabled:bg-slate-200 disabled:text-slate-400" title="Gem">{savingId === p.id ? <Loader2 className="h-4 w-4 animate-spin" /> : <Check className="h-4 w-4" />}</button></div></td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </Card>
    </div>
  );
}