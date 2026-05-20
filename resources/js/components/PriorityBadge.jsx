// Solid fills (vs. the light tints used for category) make priority immediately
// distinguishable at a glance — P1 red is the same hue as the human-review banner.
const COLOURS = {
  P1: 'bg-red-600 text-white',
  P2: 'bg-amber-500 text-white',
  P3: 'bg-green-600 text-white',
};

export default function PriorityBadge({ priority }) {
  const colour = COLOURS[priority] ?? 'bg-gray-400 text-white';
  return (
    <span className={`rounded px-2 py-0.5 text-xs font-bold ${colour}`}>
      {priority}
    </span>
  );
}
