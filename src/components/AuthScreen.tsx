import { useState, type FormEvent } from 'react';
import { Boxes, Loader2 } from 'lucide-react';
import { useAuth } from '@/context/AuthContext';

export default function AuthScreen() {
  const { signIn, signUp } = useAuth();
  const [mode, setMode] = useState<'signin' | 'signup'>('signin');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);

    if (password.length < 6) {
      setError('Adgangskoden skal være mindst 6 tegn.');
      return;
    }

    setSubmitting(true);
    const action = mode === 'signin' ? signIn : signUp;
    const { error: err } = await action(email.trim(), password);
    setSubmitting(false);

    if (err) {
      if (err.toLowerCase().includes('invalid login')) {
        setError('Forkert e-mail eller adgangskode.');
      } else if (err.toLowerCase().includes('already registered')) {
        setError('Der findes allerede en bruger med denne e-mail.');
      } else {
        setError(err);
      }
    }
  }

  return (
    <div className="min-h-screen flex bg-slate-50">
      <div className="hidden lg:flex lg:w-1/2 flex-col justify-between bg-slate-900 text-white p-12">
        <div className="flex items-center gap-3">
          <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-blue-600">
            <Boxes className="h-6 w-6" />
          </div>
          <span className="text-xl font-semibold tracking-tight">HSG Administration</span>
        </div>
        <div className="max-w-md">
          <h1 className="text-4xl font-semibold leading-tight tracking-tight">
            Styr på hele dit varekatalog og lager
          </h1>
          <p className="mt-6 text-lg leading-relaxed text-slate-300">
            Administrer produkter, mærker, kategorier og lagerbeholdning på tværs af
            alle dine lokationer – samlet ét sted.
          </p>
        </div>
        <p className="text-sm text-slate-400">Kun for autoriseret personale.</p>
      </div>

      <div className="flex w-full lg:w-1/2 items-center justify-center p-6">
        <div className="w-full max-w-sm">
          <div className="mb-8 lg:hidden flex items-center gap-3">
            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-600 text-white">
              <Boxes className="h-5 w-5" />
            </div>
            <span className="text-lg font-semibold text-slate-900">HSG Administration</span>
          </div>

          <h2 className="text-2xl font-semibold tracking-tight text-slate-900">
            {mode === 'signin' ? 'Log ind' : 'Opret konto'}
          </h2>
          <p className="mt-1 text-sm text-slate-500">
            {mode === 'signin'
              ? 'Log ind for at administrere kataloget.'
              : 'Opret en medarbejderkonto for at komme i gang.'}
          </p>

          <form onSubmit={handleSubmit} className="mt-8 space-y-4">
            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1.5">E-mail</label>
              <input
                type="email"
                required
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                className="w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                placeholder="navn@firma.dk"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1.5">Adgangskode</label>
              <input
                type="password"
                required
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                className="w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-sm outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                placeholder="••••••••"
              />
            </div>

            {error && (
              <div className="rounded-lg bg-red-50 border border-red-200 px-3.5 py-2.5 text-sm text-red-700">
                {error}
              </div>
            )}

            <button
              type="submit"
              disabled={submitting}
              className="flex w-full items-center justify-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-blue-700 disabled:opacity-60"
            >
              {submitting && <Loader2 className="h-4 w-4 animate-spin" />}
              {mode === 'signin' ? 'Log ind' : 'Opret konto'}
            </button>
          </form>

          <p className="mt-6 text-center text-sm text-slate-500">
            {mode === 'signin' ? 'Har du ikke en konto?' : 'Har du allerede en konto?'}{' '}
            <button
              onClick={() => {
                setMode(mode === 'signin' ? 'signup' : 'signin');
                setError(null);
              }}
              className="font-medium text-blue-600 hover:text-blue-700"
            >
              {mode === 'signin' ? 'Opret konto' : 'Log ind'}
            </button>
          </p>
        </div>
      </div>
    </div>
  );
}