import Link from 'next/link';
import type { Book } from '../utils/types';

interface BookCardProps {
  book: Book;
}

const typeLabels: Record<string, string> = {
  print: 'مطبوع',
  digital: 'رقمي',
  both: 'مطبوع + رقمي',
};

const typeBadgeColors: Record<string, string> = {
  print: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
  digital: 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400',
  both: 'bg-accent/10 text-accent dark:bg-accent/20',
};

export default function BookCard({ book }: BookCardProps) {
  const hasDiscount = book.discount_price != null && book.discount_price < book.price;
  const displayPrice = hasDiscount ? book.discount_price! : book.price;
  const inStock = book.type === 'digital' || book.stock > 0;

  return (
    <div className="group bg-white dark:bg-gray-800 rounded-xl overflow-hidden shadow-md hover:shadow-xl transition-all duration-300 border border-gray-100 dark:border-gray-700 flex flex-col">
      {/* Cover Image */}
      <div className="relative h-56 bg-gradient-to-bl from-primary/60 to-secondary/60 overflow-hidden">
        {book.cover_url ? (
          <img
            src={book.cover_url}
            alt={book.title_ar}
            className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"
          />
        ) : (
          <div className="w-full h-full flex items-center justify-center">
            <svg className="w-16 h-16 text-white/40" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
            </svg>
          </div>
        )}

        {/* Type Badge */}
        <span className={`absolute top-3 right-3 text-xs font-bold px-3 py-1 rounded-full ${typeBadgeColors[book.type] || ''}`}>
          {typeLabels[book.type] || book.type}
        </span>

        {/* Discount Badge */}
        {hasDiscount && (
          <span className="absolute top-3 left-3 bg-red-500 text-white text-xs font-bold px-3 py-1 rounded-full">
            خصم
          </span>
        )}
      </div>

      {/* Content */}
      <div className="p-5 flex flex-col flex-1">
        <h3 className="text-lg font-bold text-gray-900 dark:text-white mb-2 line-clamp-2 group-hover:text-primary transition-colors">
          {book.title_ar}
        </h3>

        {book.title_en && (
          <p className="text-sm text-gray-400 dark:text-gray-500 mb-3 line-clamp-1" dir="ltr">
            {book.title_en}
          </p>
        )}

        {/* Stock Status */}
        <div className="mb-4">
          {inStock ? (
            <span className="text-xs font-semibold text-accent flex items-center gap-1">
              <svg className="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
              </svg>
              {book.type === 'digital' ? 'متاح للتحميل' : `متوفر (${book.stock})`}
            </span>
          ) : (
            <span className="text-xs font-semibold text-red-500 flex items-center gap-1">
              <svg className="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clipRule="evenodd" />
              </svg>
              غير متوفر حاليًا
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
                {book.price} ج.م
              </span>
            )}
          </div>
          <Link
            href={`/books/${book.slug}`}
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
