import Link from 'next/link';
import type { Course } from '../utils/types';

interface CourseCardProps {
  course: Course;
}

export default function CourseCard({ course }: CourseCardProps) {
  const hasDiscount = course.discount_price != null && course.discount_price < course.price;
  const displayPrice = hasDiscount ? course.discount_price! : course.price;
  const discountPercent = hasDiscount
    ? Math.round(((course.price - course.discount_price!) / course.price) * 100)
    : 0;

  const totalLessons = course.modules?.reduce(
    (acc, m) => acc + (m.lessons?.length || 0),
    0
  ) || 0;

  return (
    <div className="group bg-white dark:bg-gray-800 rounded-xl overflow-hidden shadow-md hover:shadow-xl transition-all duration-300 border border-gray-100 dark:border-gray-700 flex flex-col">
      {/* Cover Image */}
      <div className="relative h-48 bg-gradient-to-l from-primary/80 to-secondary/80 overflow-hidden">
        {course.cover_url ? (
          <img
            src={course.cover_url}
            alt={course.title}
            className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"
          />
        ) : (
          <div className="w-full h-full flex items-center justify-center">
            <svg className="w-16 h-16 text-white/40" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
            </svg>
          </div>
        )}

        {/* Category Badge */}
        {course.category && (
          <span className="absolute top-3 right-3 bg-white/90 dark:bg-gray-900/90 text-primary text-xs font-bold px-3 py-1 rounded-full">
            {course.category.name_ar}
          </span>
        )}

        {/* Discount Badge */}
        {hasDiscount && (
          <span className="absolute top-3 left-3 bg-red-500 text-white text-xs font-bold px-3 py-1 rounded-full">
            خصم {discountPercent}%
          </span>
        )}

        {/* Featured Badge */}
        {course.is_featured && (
          <span className="absolute bottom-3 right-3 bg-accent text-white text-xs font-bold px-3 py-1 rounded-full flex items-center gap-1">
            <svg className="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
              <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
            </svg>
            مميز
          </span>
        )}
      </div>

      {/* Content */}
      <div className="p-5 flex flex-col flex-1">
        <h3 className="text-lg font-bold text-gray-900 dark:text-white mb-2 line-clamp-2 group-hover:text-primary transition-colors">
          {course.title}
        </h3>

        {/* Instructor */}
        {course.instructor && (
          <div className="flex items-center gap-2 mb-3">
            <div className="w-6 h-6 rounded-full bg-primary/10 flex items-center justify-center">
              <svg className="w-3.5 h-3.5 text-primary" fill="currentColor" viewBox="0 0 20 20">
                <path fillRule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clipRule="evenodd" />
              </svg>
            </div>
            <span className="text-sm text-gray-500 dark:text-gray-400">
              {course.instructor.full_name}
            </span>
          </div>
        )}

        {/* Stats Row */}
        <div className="flex items-center gap-4 text-xs text-gray-500 dark:text-gray-400 mb-4">
          {course.duration_hours && (
            <span className="flex items-center gap-1">
              <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              {course.duration_hours} ساعة
            </span>
          )}
          {totalLessons > 0 && (
            <span className="flex items-center gap-1">
              <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
              </svg>
              {totalLessons} درس
            </span>
          )}
        </div>

        {/* Price + CTA */}
        <div className="flex items-center justify-between mt-auto pt-4 border-t border-gray-100 dark:border-gray-700">
          <div className="flex items-center gap-2">
            <span className="text-lg font-bold text-primary">
              {displayPrice} ج.م
            </span>
            {hasDiscount && (
              <span className="text-sm text-gray-400 line-through">
                {course.price} ج.م
              </span>
            )}
          </div>
          <Link
            href={`/courses/${course.slug}`}
            className="inline-flex items-center gap-1 bg-primary text-white text-sm px-4 py-2 rounded-lg hover:bg-primary/90 transition-colors font-semibold"
          >
            عرض التفاصيل
            <svg className="w-4 h-4 rtl:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
            </svg>
          </Link>
        </div>
      </div>
    </div>
  );
}
