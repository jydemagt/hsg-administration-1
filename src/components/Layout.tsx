import type { ReactNode } from 'react';
import {
  Boxes,
  LayoutDashboard,
  Package,
  Tag,
  FolderTree,
  MapPin,
  Warehouse,
  Download,
  LogOut,
} from 'lucide-react';
import { useAuth } from '@/context/AuthContext';

export type View =
  | 'dashboard'
  | 'products'
  | 'brands'
  | 'categories'
  | 'locations'
  | 'inventory'
  | 'updater';

const navItems: { id: View; label: string; icon: typeof LayoutDashboard }[] = [
  { id: 'dashboard', label: 'Oversigt', icon: LayoutDashboard },
  { id: 'products', label: 'Produkter', icon: Package },
  { id: 'inventory', label: 'Lager', icon: Warehouse },
  { id: 'brands', label: 'Mærker', icon: Tag },
  { id: 'categories', label: 'Kategorier', icon: FolderTree },
  { id: 'locations', label: 'Lokationer', icon: MapPin },
  { id: 'updater', label: 'Opdateringer', icon: Download },
];

interface LayoutProps {
  view: View;
  onNavigate: (view: View) => void;
  children: ReactNode;
}

export default function Layout({ view, onNavigate, children }: LayoutProps) {
  const { user, signOut } = useAuth();

  return (
    <div className="min-h-screen bg-slate-50">
      <aside className="fixed inset-y-0 left-0 z-30 hidden w-64 flex-col border-r border-slate-200 bg-white lg:flex">
        <div className="flex items-center gap-3 px-6 py-5 border-b border-slate-100">
          <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-blue-600 text-white">
            <Boxes className="h-5 w-5" />
          </div>
          <span className="font-semibold tracking-tight text-slate-900">HSG Admin</span>
        </div>

        <nav className="flex-1 space-y-1 overflow-y-auto px-3 py-4">
          {navItems.map((item) => {
            const Icon = item.icon;
            const active = view === item.id;
            return (
              <button
                key={item.id}
                onClick={() => onNavigate(item.id)}
                className={`flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition ${
                  active
                    ? 'bg-blue-50 text-blue-700'
                    : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'
                }`}
              >
                <Icon className={`h-5 w-5 ${active ? 'text-blue-600' : 'text-slate-400'}`} />
                {item.label}
              </button>
            );
          })}
        </nav>

        <div className="border-t border-slate-100 p-3">
          <div className="flex items-center gap-3 rounded-lg px-3 py-2">
            <div className="flex h-8 w-8 items-center justify-center rounded-full bg-slate-200 text-xs font-semibold text-slate-600">
              {user?.email?.[0]?.toUpperCase() ?? '?'}
            </div>
            <div className="min-w-0 flex-1">
              <p className="truncate text-sm font-medium text-slate-700">{user?.email}</p>
            </div>
            <button
              onClick={signOut}
              title="Log ud"
              className="rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700"
            >
              <LogOut className="h-4 w-4" />
            </button>
          </div>
        </div>
      </aside>

      <header className="sticky top-0 z-20 flex items-center justify-between border-b border-slate-200 bg-white px-4 py-3 lg:hidden">
        <div className="flex items-center gap-2">
          <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-blue-600 text-white">
            <Boxes className="h-4 w-4" />
          </div>
          <span className="font-semibold text-slate-900">HSG Admin</span>
        </div>
        <button onClick={signOut} className="rounded-lg p-2 text-slate-400 hover:bg-slate-100">
          <LogOut className="h-4 w-4" />
        </button>
      </header>

      <nav className="fixed bottom-0 left-0 right-0 z-20 flex overflow-x-auto border-t border-slate-200 bg-white lg:hidden">
        {navItems.map((item) => {
          const Icon = item.icon;
          const active = view === item.id;
          return (
            <button
              key={item.id}
              onClick={() => onNavigate(item.id)}
              className={`flex min-w-[64px] flex-1 flex-col items-center gap-1 py-2 text-[10px] font-medium ${
                active ? 'text-blue-600' : 'text-slate-400'
              }`}
            >
              <Icon className="h-5 w-5" />
              {item.label}
            </button>
          );
        })}
      </nav>

      <main className="lg:pl-64">
        <div className="mx-auto max-w-6xl px-4 py-6 pb-24 sm:px-6 lg:px-8 lg:py-8">
          {children}
        </div>
      </main>
    </div>
  );
}