import { useEffect, useState, type FormEvent } from 'react';
import { Package, Plus, Pencil, Trash2, Loader2, Search, AlertTriangle } from 'lucide-react';
import { supabase } from '@/lib/supabase';
import type { Brand, Category } from '@/lib/types';
import { formatCurrency } from '@/lib/format';
import { Modal } from '@/components/ui/Modal';
import { Button, Field, Select, TextArea, TextInput } from '@/components/ui/Form';
import { PageHeader, EmptyState, Card } from '@/components/ui/Page';

interface ProductListRow {
  id: string;
  sku: string;
  name: string;
  description: string;
  brand_id: string | null;
  category_id: string | null;
  price: number;
  cost: number;
  reorder_level: number;
  image_url: string;
  brand: { id: string; name: string } | null;
  category: { id: string; name: string } | null;
  inventory: { quantity: number }[];
}

const emptyForm = {
  sku: '',
  name: '',
  description: '',
  brand_id: '',
  category_id: '',
  price: '',
  cost: '',
  reorder_level: '',
  image_url: '',
};

export default function ProductsPage() {
  const [products, setProducts] = useState<ProductListRow[]>([]);
  const [brands, setBrands] = useState<Brand[]>([]);
  const [categories, setCategories] = useState<Category[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState('');

  const [modalOpen, setModalOpen] = useState(false);
  const [editingId, setEditingId] = useState<string | null>(null);
  const [form, setForm] = useState(emptyForm);
  const [saving, setSaving] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const [deletingId, setDeletingId] = useState<string | null>(null);

  async function load() {
    setLoading(true);
    const [prodRes, brandRes, catRes] = await Promise.all([
      supabase
        .from('products')
        .select(
          'id, sku, name, description, brand_id, category_id, price, cost, reorder_level, image_url, brand:brands(id,name), category:categories(id,name), inventory(quantity)'
        )
        .order('name', { ascending: true }),
      supabase.from('brands').select('*').order('name'),
      supabase.from('categories').select('*').order('name'),
    ]);

    if (prodRes.error || brandRes.error || catRes.error) {
      setError('Kunne ikke hente produkter.');
      setLoading(false);
      return;
    }
    setProducts((prodRes.data ?? []) as unknown as ProductListRow[]);
    setBrands((brandRes.data ?? []) as Brand[]);
    setCategories((catRes.data ?? []) as Category[]);
    setError(null);
    setLoading(false);
  }

  useEffect(() => {
    load();
  }, []);

  const filtered = (() => {
    const q = search.trim().toLowerCase();
    if (!q) return products;
    return products.filter(
      (p) =>
        p.name.toLowerCase().includes(q) ||
        p.sku.toLowerCase().includes(q) ||
        p.brand?.name.toLowerCase().includes(q)
    );
  })();

  function totalStock(p: ProductListRow) {
    return p.inventory.reduce((sum, r) => sum + r.quantity, 0);
  }

  function openCreate() {
    setEditingId(null);
    setForm(emptyForm);
    setFormError(null);
    setModalOpen(true);
  }

  function openEdit(p: ProductListRow) {
    setEditingId(p.id);
    setForm({
      sku: p.sku,
      name: p.name,
      description: p.description ?? '',
      brand_id: p.brand_id ?? '',
      category_id: p.category_id ?? '',
      price: String(p.price),
      cost: String(p.cost),
      reorder_level: String(p.reorder_level),
      image_url: p.image_url ?? '',
    });
    setFormError(null);
    setModalOpen(true);
  }

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    if (!form.sku.trim() || !form.name.trim()) {
      setFormError('Varenummer og navn er påkrævet.');
      return;
    }
    setSaving(true);
    setFormError(null);

    const payload = {
      sku: form.sku.trim(),
      name: form.name.trim(),
      description: form.description.trim(),
      brand_id: form.brand_id || null,
      category_id: form.category_id || null,
      price: Number(form.price) || 0,
      cost: Number(form.cost) || 0,
      reorder_level: parseInt(form.reorder_level) || 0,
      image_url: form.image_url.trim(),
      updated_at: new Date().toISOString(),
    };

    const { error: err } = editingId
      ? await supabase.from('products').update(payload).eq('id', editingId)
      : await supabase.from('products').insert(payload);

    setSaving(false);
    if (err) {
      setFormError(
        err.message.includes('duplicate')
          ? 'Der findes allerede et produkt med dette varenummer.'
          : 'Kunne ikke gemme produktet.'
      );
      return;
    }
    setModalOpen(false);
    load();
  }

  async function handleDelete(p: ProductListRow) {
    if (!confirm(`Slet "${p.name}"? Lagerposter for produktet slettes også.`)) return;
    setDeletingId(p.id);
    const { error: err } = await supabase.from('products').delete().eq('id', p.id);
    setDeletingId(null);
    if (err) {
      setError('Kunne ikke slette produktet.');
      return;
    }
    load();
  }

  return (
    <div>
      <PageHeader
        title="Produkter"
        description="Dit fulde varekatalog med priser og lagerbeholdning."
        action={
          <Button onClick={openCreate}>
            <Plus className="h-4 w-4" />
            Nyt produkt
          </Button>
        }
      />

      {error && (
        <div className="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          {error}
        </div>
      )}

      {!loading && products.length > 0 && (
        <div className="relative mb-4 max-w-sm">
          <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
          <input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Søg efter navn, varenummer eller mærke"
            className="w-full rounded-lg border border-slate-300 bg-white py-2.5 pl-9 pr-3 text-sm shadow-sm outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
          />
        </div>
      )}

      {loading ? (
        <div className="flex justify-center py-16 text-slate-400">
          <Loader2 className="h-6 w-6 animate-spin" />
        </div>
      ) : products.length === 0 ? (
        <EmptyState
          icon={<Package className="h-6 w-6" />}
          title="Ingen produkter endnu"
          description="Opret dit første produkt for at begynde at opbygge kataloget."
          action={
            <Button onClick={openCreate}>
              <Plus className="h-4 w-4" />
              Nyt produkt
            </Button>
          }
        />
      ) : (
        <Card className="overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                  <th className="px-5 py-3">Produkt</th>
                  <th className="px-5 py-3">Mærke</th>
                  <th className="px-5 py-3">Kategori</th>
                  <th className="px-5 py-3 text-right">Pris</th>
                  <th className="px-5 py-3 text-right">Lager</th>
                  <th className="px-5 py-3"></th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {filtered.map((p) => {
                  const stock = totalStock(p);
                  const low = stock <= p.reorder_level;
                  return (
                    <tr key={p.id} className="transition hover:bg-slate-50">
                      <td className="px-5 py-3">
                        <div className="flex items-center gap-3">
                          {p.image_url ? (
                            <img src={p.image_url} alt="" className="h-10 w-10 rounded-lg object-cover ring-1 ring-slate-200" />
                          ) : (
                            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-slate-100 text-slate-400">
                              <Package className="h-4 w-4" />
                            </div>
                          )}
                          <div className="min-w-0">
                            <p className="font-medium text-slate-900">{p.name}</p>
                            <p className="text-xs text-slate-400">{p.sku}</p>
                          </div>
                        </div>
                      </td>
                      <td className="px-5 py-3 text-slate-600">{p.brand?.name ?? '—'}</td>
                      <td className="px-5 py-3 text-slate-600">{p.category?.name ?? '—'}</td>
                      <td className="px-5 py-3 text-right font-medium text-slate-900">{formatCurrency(p.price)}</td>
                      <td className="px-5 py-3 text-right">
                        <span className={`inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium ${low ? 'bg-amber-50 text-amber-700' : 'bg-emerald-50 text-emerald-700'}`}>
                          {low && <AlertTriangle className="h-3 w-3" />}
                          {stock}
                        </span>
                      </td>
                      <td className="px-5 py-3">
                        <div className="flex items-center justify-end gap-1">
                          <button onClick={() => openEdit(p)} className="rounded-lg p-2 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700" title="Rediger">
                            <Pencil className="h-4 w-4" />
                          </button>
                          <button onClick={() => handleDelete(p)} disabled={deletingId === p.id} className="rounded-lg p-2 text-slate-400 transition hover:bg-red-50 hover:text-red-600 disabled:opacity-50" title="Slet">
                            {deletingId === p.id ? <Loader2 className="h-4 w-4 animate-spin" /> : <Trash2 className="h-4 w-4" />}
                          </button>
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
          {filtered.length === 0 && <p className="px-5 py-8 text-center text-sm text-slate-400">Ingen produkter matcher din søgning.</p>}
        </Card>
      )}

      <Modal
        open={modalOpen}
        onClose={() => setModalOpen(false)}
        title={editingId ? 'Rediger produkt' : 'Nyt produkt'}
        footer={
          <>
            <Button variant="secondary" onClick={() => setModalOpen(false)}>Annuller</Button>
            <Button type="submit" form="product-form" disabled={saving}>
              {saving && <Loader2 className="h-4 w-4 animate-spin" />}
              Gem
            </Button>
          </>
        }
      >
        <form id="product-form" onSubmit={handleSubmit} className="space-y-4">
          <div className="grid grid-cols-2 gap-4">
            <Field label="Varenummer (SKU)">
              <TextInput value={form.sku} onChange={(e) => setForm({ ...form, sku: e.target.value })} autoFocus />
            </Field>
            <Field label="Navn">
              <TextInput value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
            </Field>
          </div>
          <Field label="Beskrivelse">
            <TextArea rows={2} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
          </Field>
          <div className="grid grid-cols-2 gap-4">
            <Field label="Mærke">
              <Select value={form.brand_id} onChange={(e) => setForm({ ...form, brand_id: e.target.value })}>
                <option value="">Intet mærke</option>
                {brands.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
              </Select>
            </Field>
            <Field label="Kategori">
              <Select value={form.category_id} onChange={(e) => setForm({ ...form, category_id: e.target.value })}>
                <option value="">Ingen kategori</option>
                {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
              </Select>
            </Field>
          </div>
          <div className="grid grid-cols-3 gap-4">
            <Field label="Salgspris (kr.)">
              <TextInput type="number" min="0" step="0.01" value={form.price} onChange={(e) => setForm({ ...form, price: e.target.value })} />
            </Field>
            <Field label="Kostpris (kr.)">
              <TextInput type="number" min="0" step="0.01" value={form.cost} onChange={(e) => setForm({ ...form, cost: e.target.value })} />
            </Field>
            <Field label="Genbestil ved">
              <TextInput type="number" min="0" value={form.reorder_level} onChange={(e) => setForm({ ...form, reorder_level: e.target.value })} />
            </Field>
          </div>
          <Field label="Billed-URL">
            <TextInput value={form.image_url} onChange={(e) => setForm({ ...form, image_url: e.target.value })} placeholder="https://..." />
          </Field>
          {formError && <div className="rounded-lg border border-red-200 bg-red-50 px-3.5 py-2.5 text-sm text-red-700">{formError}</div>}
        </form>
      </Modal>
    </div>
  );
}