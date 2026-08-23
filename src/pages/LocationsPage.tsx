import { MapPin } from 'lucide-react';
import EntityManager from '@/components/EntityManager';

export default function LocationsPage() {
  return (
    <EntityManager
      table="locations"
      title="Lokationer"
      description="De lagre og butikker hvor dine varer opbevares."
      singular="Lokation"
      secondField={{ key: 'address', label: 'Adresse', placeholder: 'Valgfri adresse' }}
      icon={<MapPin className="h-6 w-6" />}
    />
  );
}