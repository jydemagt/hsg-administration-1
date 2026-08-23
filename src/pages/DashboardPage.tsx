import { useEffect, useState } from 'react';
import {
  Package,
  Tag,
  Warehouse,
  TrendingUp,
  AlertTriangle,
  Loader2,
  MapPin,
} from 'lucide-react';
import { supabase } from '@/lib/supabase';
import { formatCurrency, formatNumber } from '@/lib/format';
import { Card } from '@/components/ui/Page';
import type { View } from '@/components/Layout';

interface Stats {
  products: number;
  brands: number;
  locations: number;
  totalUnits: number;
  stockValue: number;
}

interface LowStock {
  id: string;
  name: string;
  sku: string;
  reorder_level: number;
  stock: number;
}

export default function DashboardPage({ onNavigate }: { onNavigate: (v: View) => void }) {
  const [stats, setStats] = useState<Stats | null>(null);
  const [lowStock, setLowStock] = useState<LowStock[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    (async () => {
      const [prodRes, brandRes, locRes] = await Promise.all([
        supabase
          .from('products')
          .select('id, sku, name, cost, reorder_level, inventory(quantity)'),
        supabase.from('brands').select('id'),
        supabase.from('locations').select('id'),
      ]);

      if (prodRes.error || brandRes.error || locRes.error) {
        setError('Kunne ikke hente oversigten.');
        setLoading(false);
        return;
      }

      const prods = (prodRes.data ?? []) as unknown as {
        id: string;
        sku: string;
        name: string;
        cost: number;
        reorder_level: number;
        inventory: { quantity: number }[];
      }[];

      let totalUnits = 0;
      let stockValue = 0;
      const low: LowStock[] = [];

      for (const p of prods) {
        const stock = p.inventory.reduce((s, r) => s + r.quantity, 0);
        totalUnits += stock;
        stockValue += stock * Number(p.cost);
        if (stock <= p.reorder_level) {
          low.push({ id: p.id, name: p.name, sku: p.sku, reorder_level: p.reorder_level, stock });
        }
      }

      low.sort((a, b) => a.stock - b.stock);

      setStats({
        products: prods.length,
        brands: brandRes.data?.length ?? 0,
        locations: locRes.data?.length ?? 0,
        totalUnits,
        stockValue,
      });
      setLowStock(low);
      setLoading(false);
    })();
  }, []);

  if (loading) {
    return (
      <div className="flex justify-center py-16 text-slate-400">
        <Loader2 className="h-6 w-6 animate-spin" />
      </div>
    );
  }

  if (error || !stats) {
    return (
      <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
        {error ?? 'Noget gik galt.'}
      </div>
    );
  }

  const cards = [
    { label: 'Produkter', value: formatNumber(stats.products), icon: Package, view: 'products' as View },
    { label: 'Varer på lager', value: formatNumber(stats.totalUnits), icon: Warehouse, view: 'inventory' as View },
    { label: 'Lagerværdi', value: formatCurrency(stats.stockValue), icon: TrendingUp, view: 'inventory' as View },
    { label: 'Mærker', value: formatNumber(stats.brands), icon: Tag, view: 'brands' as View },
    { label: 'Lokationer', value: formatNumber(stats.locations), icon: MapPin, view: 'locations' as View },
  ];

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-semibold tracking-tight text-slate-900">Oversigt</h1>
        <p className="mt-1 text-sm text-slate-500">Et hurtigt overblik over dit katalog og lager.</p>
      </div>

      <div className="grid grid-cols-2 gap-4 lg:grid-cols-5">
        {cards.map((c) => {
          const Icon = c.icon;
          return (
            <button
              key={c.label}
              onClick={() => onNavigate(c.view)}
              className="group flex flex-col items-start rounded-2xl border border-slate-200 bg-white p-5 text-left shadow-sm transition hover:border-blue-300 hover:shadow-md"
            >
              <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-50 text-blue-600 transition group-hover:bg-blue-100">
                <Icon className="h-5 w-5" />
              </div>
              <p className="mt-4 text-2xl font-semibold tracking-tight text-slate-900">{c.value}</p>
              <p className="mt-0.5 text-sm text-slate-500">{c.label}</p>
            </button>
          );
        })}
      </div>

      <div className="mt-8">
        <div className="mb-3 flex items-center gap-2">
          <AlertTriangle className="h-5 w-5 text-amber-500" />
          <h2 className="text-lg font-semibold text-slate-900">Lav lagerbeholdning</h2>
        </div>
        <Card>
          {lowStock.length === 0 ? (
            <p className="px-5 py-8 text-center text-sm text-slate-400">
              Alle produkter har tilstrækkelig beholdning.
            </p>
          ) : (
            <ul className="divide-y divide-slate-100">
              {lowStock.map((p) => (
                <li key={p.id} className="flex items-center justify-between gap-4 px-5 py-3.5">
                  <div className="min-w-0">
                    <p className="font-medium text-slate-900">{p.name}</p>
                    <p className="text-xs text-slate-400">{p.sku}</p>
                  </div>
                  <div className="text-right text-sm">
                    <span className="font-semibold text-amber-600">{p.stock} stk.</span>
                    <span className="text-slate-400"> / genbestil ved {p.reorder_level}</span>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>
    </div>
  );
}