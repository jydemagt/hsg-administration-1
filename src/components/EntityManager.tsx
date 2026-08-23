import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { Pencil, Plus, Trash2, Loader2 } from 'lucide-react';
import { supabase } from '@/lib/supabase';
import { Modal } from '@/components/ui/Modal';
import { Button, Field, TextArea, TextInput } from '@/components/ui/Form';
import { PageHeader, EmptyState, Card } from '@/components/ui/Page';

interface EntityRow {
  id: string;
  name: string;
  description?: string;
  address?: string;
  created_at: string;
}

interface EntityManagerProps {
  table: 'brands' | 'categories' | 'locations';
  title: string;
  description: string;
  singular: string;
  secondField: { key: 'description' | 'address'; label: string; placeholder: string };
  icon: ReactNode;
}

export default function EntityManager({
  table,
  title,
  description,
  singular,
  secondField,
  icon,
}: EntityManagerProps) {
  const [rows, setRows] = useState<EntityRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState<EntityRow | null>(null);
  const [name, setName] = useState('');
  const [secondValue, setSecondValue] = useState('');
  const [saving, setSaving] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const [deletingId, setDeletingId] = useState<string | null>(null);

  async function load() {
    setLoading(true);
    const { data, error: err } = await supabase
      .from(table)
      .select('*')
      .order('name', { ascending: true });
    if (err) {
      setError('Kunne ikke hente data.');
    } else {
      setRows((data ?? []) as EntityRow[]);
      setError(null);
    }
    setLoading(false);
  }

  useEffect(() => {
    load();
  }, [table]);

  function openCreate() {
    setEditing(null);
    setName('');
    setSecondValue('');
    setFormError(null);
    setModalOpen(true);
  }

  function openEdit(row: EntityRow) {
    setEditing(row);
    setName(row.name);
    setSecondValue((row[secondField.key] as string) ?? '');
    setFormError(null);
    setModalOpen(true);
  }

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    if (!name.trim()) {
      setFormError('Navn er påkrævet.');
      return;
    }
    setSaving(true);
    setFormError(null);

    const payload = { name: name.trim(), [secondField.key]: secondValue.trim() };
    const { error: err } = editing
      ? await supabase.from(table).update(payload).eq('id', editing.id)
      : await supabase.from(table).insert(payload);

    setSaving(false);
    if (err) {
      setFormError(
        err.message.includes('duplicate')
          ? 'Der findes allerede et element med dette navn.'
          : 'Kunne ikke gemme. Prøv igen.'
      );
      return;
    }
    setModalOpen(false);
    load();
  }

  async function handleDelete(row: EntityRow) {
    if (!confirm(`Slet "${row.name}"? Dette kan ikke fortrydes.`)) return;
    setDeletingId(row.id);
    const { error: err } = await supabase.from(table).delete().eq('id', row.id);
    setDeletingId(null);
    if (err) {
      setError('Kunne ikke slette elementet.');
      return;
    }
    load();
  }

  return (
    <div>
      <PageHeader
        title={title}
        description={description}
        action={
          <Button onClick={openCreate}>
            <Plus className="h-4 w-4" />
            Tilføj {singular.toLowerCase()}
          </Button>
        }
      />

      {error && (
        <div className="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          {error}
        </div>
      )}

      {loading ? (
        <div className="flex justify-center py-16 text-slate-400">
          <Loader2 className="h-6 w-6 animate-spin" />
        </div>
      ) : rows.length === 0 ? (
        <EmptyState
          icon={icon}
          title={`Ingen ${title.toLowerCase()} endnu`}
          description={`Tilføj din første ${singular.toLowerCase()} for at komme i gang.`}
          action={
            <Button onClick={openCreate}>
              <Plus className="h-4 w-4" />
              Tilføj {singular.toLowerCase()}
            </Button>
          }
        />
      ) : (
        <Card>
          <ul className="divide-y divide-slate-100">
            {rows.map((row) => (
              <li
                key={row.id}
                className="flex items-center justify-between gap-4 px-5 py-4 transition hover:bg-slate-50"
              >
                <div className="min-w-0">
                  <p className="font-medium text-slate-900">{row.name}</p>
                  {(row[secondField.key] as string) && (
                    <p className="mt-0.5 truncate text-sm text-slate-500">
                      {row[secondField.key] as string}
                    </p>
                  )}
                </div>
                <div className="flex shrink-0 items-center gap-1">
                  <button
                    onClick={() => openEdit(row)}
                    className="rounded-lg p-2 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700"
                    title="Rediger"
                  >
                    <Pencil className="h-4 w-4" />
                  </button>
                  <button
                    onClick={() => handleDelete(row)}
                    disabled={deletingId === row.id}
                    className="rounded-lg p-2 text-slate-400 transition hover:bg-red-50 hover:text-red-600 disabled:opacity-50"
                    title="Slet"
                  >
                    {deletingId === row.id ? (
                      <Loader2 className="h-4 w-4 animate-spin" />
                    ) : (
                      <Trash2 className="h-4 w-4" />
                    )}
                  </button>
                </div>
              </li>
            ))}
          </ul>
        </Card>
      )}

      <Modal
        open={modalOpen}
        onClose={() => setModalOpen(false)}
        title={editing ? `Rediger ${singular.toLowerCase()}` : `Tilføj ${singular.toLowerCase()}`}
        footer={
          <>
            <Button variant="secondary" onClick={() => setModalOpen(false)}>
              Annuller
            </Button>
            <Button type="submit" form="entity-form" disabled={saving}>
              {saving && <Loader2 className="h-4 w-4 animate-spin" />}
              Gem
            </Button>
          </>
        }
      >
        <form id="entity-form" onSubmit={handleSubmit} className="space-y-4">
          <Field label="Navn">
            <TextInput value={name} onChange={(e) => setName(e.target.value)} autoFocus />
          </Field>
          <Field label={secondField.label}>
            <TextArea
              rows={3}
              value={secondValue}
              onChange={(e) => setSecondValue(e.target.value)}
              placeholder={secondField.placeholder}
            />
          </Field>
          {formError && (
            <div className="rounded-lg border border-red-200 bg-red-50 px-3.5 py-2.5 text-sm text-red-700">
              {formError}
            </div>
          )}
        </form>
      </Modal>
    </div>
  );
}