import { useState } from 'react';
import MessageForm from './MessageForm';
import TriageResult from './TriageResult';

export default function App() {
  const [loading, setLoading] = useState(false);
  const [result, setResult] = useState(null);
  const [error, setError] = useState(null);

  async function handleSubmit(formData) {
    setLoading(true);
    setResult(null);
    setError(null);

    try {
      const res = await fetch('/api/triage', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(formData),
      });

      const data = await res.json();

      if (!res.ok) {
        throw new Error(data.error || `HTTP ${res.status}`);
      }

      setResult(data);
    } catch (e) {
      setError(e.message);
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="min-h-screen bg-gray-50 py-10 px-4">
      <div className="max-w-2xl mx-auto space-y-6">
        <h1 className="text-2xl font-bold text-gray-900">
          Northwind — Triage Agent
        </h1>
        <MessageForm onSubmit={handleSubmit} loading={loading} />
        {error && (
          <div className="rounded bg-red-50 border border-red-300 text-red-700 px-4 py-3 text-sm">
            {error}
          </div>
        )}
        {result && <TriageResult result={result} />}
      </div>
    </div>
  );
}
