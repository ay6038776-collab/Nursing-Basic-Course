import { useState, useEffect, useCallback, useRef } from 'react';

interface SearchBarProps {
  placeholder?: string;
  onSearch: (query: string) => void;
  debounceMs?: number;
  defaultValue?: string;
}

export default function SearchBar({
  placeholder = 'ابحث هنا...',
  onSearch,
  debounceMs = 400,
  defaultValue = '',
}: SearchBarProps) {
  const [value, setValue] = useState(defaultValue);
  const timerRef = useRef<ReturnType<typeof setTimeout>>();

  const debouncedSearch = useCallback(
    (query: string) => {
      if (timerRef.current) clearTimeout(timerRef.current);
      timerRef.current = setTimeout(() => {
        onSearch(query);
      }, debounceMs);
    },
    [onSearch, debounceMs]
  );

  useEffect(() => {
    return () => {
      if (timerRef.current) clearTimeout(timerRef.current);
    };
  }, []);

  const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const q = e.target.value;
    setValue(q);
    debouncedSearch(q);
  };

  const handleClear = () => {
    setValue('');
    onSearch('');
  };

  return (
    <div className="relative w-full max-w-xl">
      <div className="absolute inset-y-0 right-0 flex items-center pr-4 pointer-events-none">
        <svg
          className="w-5 h-5 text-gray-400"
          fill="none"
          viewBox="0 0 24 24"
          stroke="currentColor"
        >
          <path
            strokeLinecap="round"
            strokeLinejoin="round"
            strokeWidth={2}
            d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
          />
        </svg>
      </div>
      <input
        type="text"
        value={value}
        onChange={handleChange}
        placeholder={placeholder}
        className="w-full pr-12 pl-10 py-3 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-primary/50 focus:border-primary transition-all"
      />
      {value && (
        <button
          onClick={handleClear}
          className="absolute inset-y-0 left-0 flex items-center pl-4 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
          aria-label="مسح البحث"
        >
          <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      )}
    </div>
  );
}
