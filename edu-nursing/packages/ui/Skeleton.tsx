interface SkeletonProps {
  className?: string;
}

export function Skeleton({ className = '' }: SkeletonProps) {
  return (
    <div
      className={`animate-pulse bg-gray-200 dark:bg-gray-700 rounded ${className}`}
    />
  );
}

export function CardSkeleton() {
  return (
    <div className="bg-white dark:bg-gray-800 rounded-xl overflow-hidden shadow-md">
      <Skeleton className="h-48 w-full rounded-none" />
      <div className="p-5 space-y-3">
        <Skeleton className="h-4 w-3/4" />
        <Skeleton className="h-3 w-1/2" />
        <div className="flex items-center gap-2 pt-2">
          <Skeleton className="h-3 w-16" />
          <Skeleton className="h-3 w-20" />
        </div>
        <div className="flex justify-between items-center pt-3">
          <Skeleton className="h-6 w-24" />
          <Skeleton className="h-9 w-28 rounded-lg" />
        </div>
      </div>
    </div>
  );
}

export function TextSkeleton({ lines = 3 }: { lines?: number }) {
  return (
    <div className="space-y-2">
      {Array.from({ length: lines }).map((_, i) => (
        <Skeleton
          key={i}
          className={`h-4 ${i === lines - 1 ? 'w-2/3' : 'w-full'}`}
        />
      ))}
    </div>
  );
}

export function HeroSkeleton() {
  return (
    <div className="animate-pulse bg-gradient-to-l from-secondary to-primary min-h-[500px] flex items-center">
      <div className="max-w-7xl mx-auto px-4 w-full space-y-6">
        <Skeleton className="h-10 w-2/3 bg-white/20" />
        <Skeleton className="h-6 w-1/2 bg-white/20" />
        <div className="flex gap-4">
          <Skeleton className="h-12 w-40 rounded-lg bg-white/20" />
          <Skeleton className="h-12 w-40 rounded-lg bg-white/20" />
        </div>
      </div>
    </div>
  );
}

export function CourseGridSkeleton({ count = 4 }: { count?: number }) {
  return (
    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
      {Array.from({ length: count }).map((_, i) => (
        <CardSkeleton key={i} />
      ))}
    </div>
  );
}

export function DetailPageSkeleton() {
  return (
    <div className="max-w-7xl mx-auto px-4 py-8 space-y-8">
      <Skeleton className="h-64 w-full rounded-xl" />
      <Skeleton className="h-8 w-2/3" />
      <TextSkeleton lines={5} />
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <div className="lg:col-span-2 space-y-4">
          <Skeleton className="h-12 w-full rounded-lg" />
          <Skeleton className="h-12 w-full rounded-lg" />
          <Skeleton className="h-12 w-full rounded-lg" />
        </div>
        <div>
          <Skeleton className="h-60 w-full rounded-xl" />
        </div>
      </div>
    </div>
  );
}
