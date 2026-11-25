import { ReactNode } from 'react';

interface EmptyStateProps {
  icon?: ReactNode;
  title: string;
  description?: string;
  actionLabel?: string;
  onAction?: () => void;
  actionHref?: string;
}

export default function EmptyState({
  icon,
  title,
  description,
  actionLabel,
  onAction,
  actionHref,
}: EmptyStateProps) {
  const ActionTag = actionHref ? 'a' : 'button';

  return (
    <div className="flex flex-col items-center justify-center py-16 px-4 text-center">
      {icon ? (
        <div className="mb-4 text-gray-400 dark:text-gray-500">{icon}</div>
      ) : (
        <div className="mb-4">
          <svg
            className="w-20 h-20 text-gray-300 dark:text-gray-600 mx-auto"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
          >
            <path
              strokeLinecap="round"
              strokeLinejoin="round"
              strokeWidth={1}
              d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"
            />
          </svg>
        </div>
      )}
      <h3 className="text-xl font-bold text-gray-700 dark:text-gray-300 mb-2">
        {title}
      </h3>
      {description && (
        <p className="text-gray-500 dark:text-gray-400 max-w-md mb-6">
          {description}
        </p>
      )}
      {actionLabel && (
        <ActionTag
          href={actionHref}
          onClick={onAction}
          className="inline-flex items-center gap-2 bg-primary text-white px-6 py-3 rounded-lg font-semibold hover:bg-primary/90 transition-colors"
        >
          {actionLabel}
        </ActionTag>
      )}
    </div>
  );
}
