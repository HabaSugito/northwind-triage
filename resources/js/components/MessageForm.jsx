import { useState } from 'react';

export default function MessageForm({ onSubmit, loading }) {
  const [form, setForm] = useState({
    body: '',
    channel: 'email',
    sender_name: '',
    subject: '',
  });

  function handleChange(e) {
    setForm((prev) => ({ ...prev, [e.target.name]: e.target.value }));
  }

  function handleSubmit(e) {
    e.preventDefault();
    if (!form.body.trim()) return;
    onSubmit(form);
  }

  return (
    <form onSubmit={handleSubmit} className="bg-white rounded-lg shadow p-6 space-y-4">
      <div>
        <label className="block text-sm font-medium text-gray-700 mb-1">Channel</label>
        <select
          name="channel"
          value={form.channel}
          onChange={handleChange}
          className="w-full border border-gray-300 rounded px-3 py-2 text-sm"
        >
          <option value="email">Email</option>
          <option value="webform">Web Form</option>
          <option value="sms">SMS</option>
        </select>
      </div>

      <div className="grid grid-cols-2 gap-4">
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">
            Sender name <span className="text-gray-400">(optional)</span>
          </label>
          <input
            name="sender_name"
            value={form.sender_name}
            onChange={handleChange}
            placeholder="e.g. Sarah Patel"
            className="w-full border border-gray-300 rounded px-3 py-2 text-sm"
          />
        </div>
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">
            Subject <span className="text-gray-400">(optional)</span>
          </label>
          <input
            name="subject"
            value={form.subject}
            onChange={handleChange}
            placeholder="e.g. Dripping tap"
            className="w-full border border-gray-300 rounded px-3 py-2 text-sm"
          />
        </div>
      </div>

      <div>
        <label className="block text-sm font-medium text-gray-700 mb-1">
          Message body <span className="text-red-500">*</span>
        </label>
        <textarea
          name="body"
          value={form.body}
          onChange={handleChange}
          rows={6}
          required
          placeholder="Paste the customer message here..."
          className="w-full border border-gray-300 rounded px-3 py-2 text-sm resize-none"
        />
      </div>

      <button
        type="submit"
        disabled={loading || !form.body.trim()}
        className="w-full bg-blue-600 hover:bg-blue-700 disabled:bg-gray-300 text-white font-medium py-2 rounded text-sm transition-colors"
      >
        {loading ? 'Analysing...' : 'Triage Message'}
      </button>
    </form>
  );
}
