import React, { useState, useRef, useEffect } from 'react';
import { Link, router } from '@inertiajs/react';
import { MagnifyingGlassIcon, XMarkIcon } from '@heroicons/react/24/outline';
import { useSearchResults, formatAmount, formatSearchDate, getBadgeClasses } from '../../../hooks/useSearch';

interface GlobalSearchProps {
    placeholder?: string;
    className?: string;
}

export default function GlobalSearch({ 
    placeholder = 'Search jobs, invoices, expenses...', 
    className = '' 
}: GlobalSearchProps) {
    const [query, setQuery] = useState('');
    const [isOpen, setIsOpen] = useState(false);
    const [selectedIndex, setSelectedIndex] = useState(0);
    const searchRef = useRef<HTMLDivElement>(null);
    const inputRef = useRef<HTMLInputElement>(null);

    // Debounce search query
    const [debouncedQuery, setDebouncedQuery] = useState('');

    useEffect(() => {
        const timer = setTimeout(() => {
            setDebouncedQuery(query);
        }, 300); // 300ms debounce

        return () => clearTimeout(timer);
    }, [query]);

    // Fetch search results
    const { results, categorized, total, isLoading } = useSearchResults(
        debouncedQuery, 
        debouncedQuery.length >= 2
    );

    // Close dropdown when clicking outside
    useEffect(() => {
        function handleClickOutside(event: MouseEvent) {
            if (searchRef.current && !searchRef.current.contains(event.target as Node)) {
                setIsOpen(false);
            }
        }

        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    // Handle keyboard navigation
    const handleKeyDown = (e: React.KeyboardEvent) => {
        if (!isOpen || results.length === 0) return;

        switch (e.key) {
            case 'ArrowDown':
                e.preventDefault();
                setSelectedIndex((prev) => (prev + 1) % results.length);
                break;
            case 'ArrowUp':
                e.preventDefault();
                setSelectedIndex((prev) => (prev - 1 + results.length) % results.length);
                break;
            case 'Enter':
                e.preventDefault();
                if (results[selectedIndex]) {
                    router.visit(results[selectedIndex].url);
                }
                break;
            case 'Escape':
                e.preventDefault();
                setIsOpen(false);
                inputRef.current?.blur();
                break;
        }
    };

    const handleInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const value = e.target.value;
        setQuery(value);
        setIsOpen(value.length >= 2);
        setSelectedIndex(0);
    };

    const handleClear = () => {
        setQuery('');
        setDebouncedQuery('');
        setIsOpen(false);
        inputRef.current?.focus();
    };

    const handleResultClick = () => {
        setIsOpen(false);
        setQuery('');
        setDebouncedQuery('');
    };

    return (
        <div ref={searchRef} className={`relative ${className}`}>
            {/* Search Input */}
            <div className="relative">
                <div className="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                    <MagnifyingGlassIcon className="h-5 w-5 text-gray-400 dark:text-gray-500" />
                </div>
                
                <input
                    ref={inputRef}
                    type="text"
                    value={query}
                    onChange={handleInputChange}
                    onKeyDown={handleKeyDown}
                    onFocus={() => query.length >= 2 && setIsOpen(true)}
                    placeholder={placeholder}
                    className="w-full pl-10 pr-10 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg
                             bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100
                             placeholder-gray-500 dark:placeholder-gray-400
                             focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent
                             transition-colors"
                />

                {query && (
                    <button
                        onClick={handleClear}
                        className="absolute inset-y-0 right-0 flex items-center pr-3 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-r-lg transition-colors"
                        aria-label="Clear search"
                    >
                        <XMarkIcon className="h-4 w-4 text-gray-400 dark:text-gray-500" />
                    </button>
                )}
            </div>

            {/* Search Results Dropdown */}
            {isOpen && (
                <div className="absolute z-50 mt-2 w-full max-w-2xl bg-white dark:bg-gray-800 
                              rounded-lg shadow-xl border border-gray-200 dark:border-gray-700 
                              max-h-96 overflow-y-auto">
                    
                    {/* Loading State */}
                    {isLoading && (
                        <div className="px-4 py-8 text-center">
                            <div className="inline-block h-6 w-6 animate-spin rounded-full border-4 border-solid border-indigo-600 border-r-transparent"></div>
                            <p className="mt-2 text-sm text-gray-600 dark:text-gray-400">Searching...</p>
                        </div>
                    )}

                    {/* No Results */}
                    {!isLoading && debouncedQuery.length >= 2 && total === 0 && (
                        <div className="px-4 py-8 text-center">
                            <MagnifyingGlassIcon className="mx-auto h-12 w-12 text-gray-400 dark:text-gray-600" />
                            <p className="mt-2 text-sm font-medium text-gray-900 dark:text-gray-100">No results found</p>
                            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                Try searching with different keywords
                            </p>
                        </div>
                    )}

                    {/* Results by Category */}
                    {!isLoading && categorized && total > 0 && (
                        <div className="py-2">
                            {/* Jobs Section */}
                            {categorized.jobs.length > 0 && (
                                <div className="mb-2">
                                    <div className="px-4 py-2 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                        Job Orders ({categorized.jobs.length})
                                    </div>
                                    {categorized.jobs.map((result: any) => (
                                        <SearchResultItem
                                            key={`job-${result.id}`}
                                            result={result}
                                            isSelected={selectedIndex === results.findIndex((r: any) => r.id === result.id && r.type === result.type)}
                                            onClick={handleResultClick}
                                        />
                                    ))}
                                </div>
                            )}

                            {/* Invoices Section */}
                            {categorized.invoices.length > 0 && (
                                <div className="mb-2">
                                    <div className="px-4 py-2 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                        Invoices ({categorized.invoices.length})
                                    </div>
                                    {categorized.invoices.map((result: any) => (
                                        <SearchResultItem
                                            key={`invoice-${result.id}`}
                                            result={result}
                                            isSelected={selectedIndex === results.findIndex((r: any) => r.id === result.id && r.type === result.type)}
                                            onClick={handleResultClick}
                                        />
                                    ))}
                                </div>
                            )}

                            {/* Expenses Section */}
                            {categorized.expenses.length > 0 && (
                                <div className="mb-2">
                                    <div className="px-4 py-2 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                        Expenses ({categorized.expenses.length})
                                    </div>
                                    {categorized.expenses.map((result: any) => (
                                        <SearchResultItem
                                            key={`expense-${result.id}`}
                                            result={result}
                                            isSelected={selectedIndex === results.findIndex((r: any) => r.id === result.id && r.type === result.type)}
                                            onClick={handleResultClick}
                                        />
                                    ))}
                                </div>
                            )}

                            {/* Footer */}
                            <div className="px-4 py-2 border-t border-gray-200 dark:border-gray-700 text-xs text-gray-500 dark:text-gray-400">
                                <span className="font-medium">{total}</span> result{total !== 1 ? 's' : ''} found
                                <span className="mx-2">•</span>
                                Use ↑↓ to navigate, Enter to open, Esc to close
                            </div>
                        </div>
                    )}

                    {/* Query Too Short */}
                    {!isLoading && debouncedQuery.length < 2 && query.length >= 1 && (
                        <div className="px-4 py-6 text-center">
                            <p className="text-sm text-gray-600 dark:text-gray-400">
                                Type at least 2 characters to search
                            </p>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

interface SearchResultItemProps {
    result: {
        id: number;
        type: 'job' | 'invoice' | 'expense';
        title: string;
        subtitle: string;
        status: string;
        amount: number;
        date: string;
        url: string;
        icon: string;
        badge: string;
        badgeColor: 'green' | 'blue' | 'yellow' | 'red' | 'gray';
    };
    isSelected: boolean;
    onClick: () => void;
}

function SearchResultItem({ result, isSelected, onClick }: SearchResultItemProps) {
    return (
        <Link
            href={result.url}
            onClick={onClick}
            className={`block px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors
                      ${isSelected ? 'bg-indigo-50 dark:bg-indigo-900/20' : ''}`}
        >
            <div className="flex items-center gap-3">
                {/* Icon */}
                <div className="shrink-0 text-2xl">
                    {result.icon}
                </div>

                {/* Content */}
                <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2">
                        <p className="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">
                            {result.title}
                        </p>
                        <span className={getBadgeClasses(result.badgeColor)}>
                            {result.badge}
                        </span>
                    </div>
                    <p className="text-xs text-gray-500 dark:text-gray-400 truncate">
                        {result.subtitle}
                    </p>
                </div>

                {/* Amount and Date */}
                <div className="shrink-0 text-right">
                    <p className="text-sm font-medium text-gray-900 dark:text-gray-100">
                        {formatAmount(result.amount)}
                    </p>
                    <p className="text-xs text-gray-500 dark:text-gray-400">
                        {formatSearchDate(result.date)}
                    </p>
                </div>
            </div>
        </Link>
    );
}
