const COLOURS = {
  EMERGENCY: 'bg-red-100 text-red-700 border-red-300',
  COMPLAINT: 'bg-orange-100 text-orange-700 border-orange-300',
  BOOKING: 'bg-blue-100 text-blue-700 border-blue-300',
  QUOTE: 'bg-purple-100 text-purple-700 border-purple-300',
  BILLING: 'bg-yellow-100 text-yellow-700 border-yellow-300',
  OUT_OF_SCOPE: 'bg-gray-100 text-gray-600 border-gray-300',
};

export default function CategoryBadge({ category }) {
  const colour = COLOURS[category] ?? COLOURS.OUT_OF_SCOPE;
  return (
    <span className={`border rounded px-2 py-0.5 text-xs font-semibold ${colour}`}>
      {category}
    </span>
  );
}
