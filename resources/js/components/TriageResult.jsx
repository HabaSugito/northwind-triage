import CategoryBadge from './CategoryBadge';
import PriorityBadge from './PriorityBadge';

export default function TriageResult({ result }) {
  return (
    <div className="bg-white rounded-lg shadow p-6 space-y-4">
      {result.needs_human_review && (
        <div className="bg-red-600 text-white rounded px-4 py-3 text-sm font-semibold">
          Requires human review — do not send this reply without checking first.
        </div>
      )}

      <div className="flex flex-wrap gap-3 items-center">
        <CategoryBadge category={result.category} />
        <PriorityBadge priority={result.priority} />
        <span className="text-sm text-gray-600">
          Route to: <span className="font-medium text-gray-900">{result.route_to}</span>
        </span>
      </div>

      <div>
        <p className="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">
          Draft Reply
        </p>
        <div className="bg-gray-50 border border-gray-200 rounded px-4 py-3 text-sm text-gray-800 whitespace-pre-wrap">
          {result.draft_reply || (
            <span className="text-gray-400 italic">No reply drafted (spam or garbled message)</span>
          )}
        </div>
      </div>

      <div>
        <p className="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">
          Reasoning
        </p>
        <p className="text-sm text-gray-600">{result.reasoning}</p>
      </div>
    </div>
  );
}
