import { Tag } from 'lucide-react';
import EntityManager from '@/components/EntityManager';

export default function BrandsPage() {
  return (
    <EntityManager
      table="brands"
      title="Mærker"
      description="Administrer de mærker, dine produkter tilhører."
      singular="Mærke"
      secondField={{ key: 'description', label: 'Beskrivelse', placeholder: 'Valgfri note om mærket' }}
      icon={<Tag className="h-6 w-6" />}
    />
  );
}