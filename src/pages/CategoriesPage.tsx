import { FolderTree } from 'lucide-react';
import EntityManager from '@/components/EntityManager';

export default function CategoriesPage() {
  return (
    <EntityManager
      table="categories"
      title="Kategorier"
      description="Grupper dine produkter i kategorier."
      singular="Kategori"
      secondField={{ key: 'description', label: 'Beskrivelse', placeholder: 'Valgfri note om kategorien' }}
      icon={<FolderTree className="h-6 w-6" />}
    />
  );
}