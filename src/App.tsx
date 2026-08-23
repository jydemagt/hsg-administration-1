import { useState } from 'react';
import { Loader2 } from 'lucide-react';
import { useAuth } from '@/context/AuthContext';
import AuthScreen from '@/components/AuthScreen';
import Layout, { type View } from '@/components/Layout';
import DashboardPage from '@/pages/DashboardPage';
import ProductsPage from '@/pages/ProductsPage';
import InventoryPage from '@/pages/InventoryPage';
import BrandsPage from '@/pages/BrandsPage';
import CategoriesPage from '@/pages/CategoriesPage';
import LocationsPage from '@/pages/LocationsPage';
import UpdaterPage from '@/pages/UpdaterPage';

function App() {
  const { user, loading } = useAuth();
  const [view, setView] = useState<View>('dashboard');

  if (loading) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-slate-50 text-slate-400">
        <Loader2 className="h-6 w-6 animate-spin" />
      </div>
    );
  }

  if (!user) {
    return <AuthScreen />;
  }

  return (
    <Layout view={view} onNavigate={setView}>
      {view === 'dashboard' && <DashboardPage onNavigate={setView} />}
      {view === 'products' && <ProductsPage />}
      {view === 'inventory' && <InventoryPage />}
      {view === 'brands' && <BrandsPage />}
      {view === 'categories' && <CategoriesPage />}
      {view === 'locations' && <LocationsPage />}
      {view === 'updater' && <UpdaterPage />}
    </Layout>
  );
}

export default App;