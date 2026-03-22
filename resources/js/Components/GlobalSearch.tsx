import React, { useState, useEffect, useRef, useCallback } from 'react';
import { router } from '@inertiajs/react';
import axios from 'axios';

// ─── Types ────────────────────────────────────────────────────────────────────

interface SearchResult {
    id: number;
    mrn: string;
    name: string;
    dob: string;
    age: number;
    enrollment_status: string;
    flags: string[];
}

const STATUS_COLORS: Record<string, string> = {
    enrolled:    'bg-green-100 text-green-800',
    disenrolled: 'bg-gray-100 text-gray-600',
    deceased:    'bg-slate-100 text-slate-500',
    pending:     'bg-yellow-100 text-yellow-800',
    intake:      'bg-blue-100 text-blue-800',
    referred:    'bg-purple-100 text-purple-800',
};

const FLAG_COLORS: Record<string, string> = {
    wheelchair:  'bg-blue-100 text-blue-700',
    stretcher:   'bg-indigo-100 text-indigo-700',
    oxygen:      'bg-cyan-100 text-cyan-700',
    behavioral:  'bg-orange-100 text-orange-700',
    fall_risk:   'bg-red-100 text-red-700',
    dnr:         'bg-red-200 text-red-800',
    hospice:     'bg-purple-100 text-purple-700',
    default:     'bg-gray-100 text-gray-600',
};

function flagColor(flag: string): string {
    return FLAG_COLORS[flag] ?? FLAG_COLORS.default;
}

function flagLabel(flag: string): string {
    return flag.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
}

// ─── GlobalSearch ─────────────────────────────────────────────────────────────

export default function GlobalSearch({
    open,
    onClose,
}: {
    open: boolean;
    onClose: () => void;
}) {
    const [query, setQuery]           = useState('');
    const [results, setResults]       = useState<SearchResult[]>([]);
    const [loading, setLoading]       = useState(false);
    const [activeIndex, setActiveIndex] = useState(0);
    const [error, setError]           = useState<string | null>(null);

    const inputRef    = useRef<HTMLInputElement>(null);
    const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const cancelRef   = useRef<AbortController | null>(null);

    // Focus input when modal opens
    useEffect(() => {
        if (open) {
            setQuery('');
            setResults([]);
            setActiveIndex(0);
            setError(null);
            setTimeout(() => inputRef.current?.focus(), 50);
        }
    }, [open]);

    // Debounced search
    const doSearch = useCallback((q: string) => {
        if (debounceRef.current) clearTimeout(debounceRef.current);
        if (cancelRef.current)   cancelRef.current.abort();

        if (q.trim().length < 2) {
            setResults([]);
            setLoading(false);
            return;
        }

        setLoading(true);
        setError(null);
        debounceRef.current = setTimeout(async () => {
            const controller = new AbortController();
            cancelRef.current = controller;
            try {
                const resp = await axios.get<SearchResult[]>('/participants/search', {
                    params: { q },
                    signal: controller.signal,
                });
                setResults(resp.data);
                setActiveIndex(0);
            } catch (err: unknown) {
                if (axios.isCancel(err)) return;
                setError('Search failed. Please try again.');
                setResults([]);
            } finally {
                setLoading(false);
            }
        }, 280);
    }, []);

    useEffect(() => {
        doSearch(query);
        return () => {
            if (debounceRef.current) clearTimeout(debounceRef.current);
            if (cancelRef.current)   cancelRef.current.abort();
        };
    }, [query, doSearch]);

    // Keyboard navigation
    const handleKeyDown = (e: React.KeyboardEvent) => {
        if (e.key === 'Escape') {
            onClose();
            return;
        }
        if (results.length === 0) return;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActiveIndex(i => Math.min(i + 1, results.length - 1));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActiveIndex(i => Math.max(i - 1, 0));
        } else if (e.key === 'Enter') {
            e.preventDefault();
            const selected = results[activeIndex];
            if (selected) navigate(selected.id);
        }
    };

    const navigate = (id: number) => {
        onClose();
        router.visit(`/participants/${id}`);
    };

    if (!open) return null;

    return (
        /* Backdrop */
        <div
            className="fixed inset-0 z-50 flex items-start justify-center pt-24 px-4"
            onClick={e => { if (e.target === e.currentTarget) onClose(); }}
        >
            {/* Dim overlay */}
            <div className="absolute inset-0 bg-black/40 backdrop-blur-sm" aria-hidden />

            {/* Panel */}
            <div className="relative w-full max-w-xl bg-white rounded-xl shadow-2xl ring-1 ring-black/10 flex flex-col overflow-hidden">
                {/* Search input */}
                <div className="flex items-center gap-3 px-4 py-3 border-b border-slate-200">
                    <svg className="w-5 h-5 text-slate-400 shrink-0" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 15.803 7.5 7.5 0 0015.803 15.803z" />
                    </svg>
                    <input
                        ref={inputRef}
                        type="text"
                        value={query}
                        onChange={e => setQuery(e.target.value)}
                        onKeyDown={handleKeyDown}
                        placeholder="Search by name, MRN, or date of birth (YYYY-MM-DD)…"
                        className="flex-1 bg-transparent text-sm text-slate-800 placeholder-slate-400 outline-none"
                        aria-label="Search participants"
                        autoComplete="off"
                    />
                    {loading && (
                        <svg className="w-4 h-4 text-slate-400 animate-spin shrink-0" fill="none" viewBox="0 0 24 24">
                            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z" />
                        </svg>
                    )}
                    <kbd className="hidden sm:inline-flex items-center gap-1 px-1.5 py-0.5 text-[10px] font-medium text-slate-500 bg-slate-100 border border-slate-200 rounded">
                        ESC
                    </kbd>
                </div>

                {/* Results */}
                {error && (
                    <div className="px-4 py-3 text-sm text-red-600">{error}</div>
                )}

                {!error && query.trim().length >= 2 && !loading && results.length === 0 && (
                    <div className="px-4 py-8 text-center text-sm text-slate-500">
                        No participants found matching <span className="font-medium text-slate-700">"{query}"</span>
                    </div>
                )}

                {results.length > 0 && (
                    <ul className="py-1 max-h-80 overflow-y-auto" role="listbox">
                        {results.map((r, idx) => (
                            <li
                                key={r.id}
                                role="option"
                                aria-selected={idx === activeIndex}
                                onMouseEnter={() => setActiveIndex(idx)}
                                onClick={() => navigate(r.id)}
                                className={`flex items-start gap-3 px-4 py-3 cursor-pointer transition-colors ${
                                    idx === activeIndex ? 'bg-blue-50' : 'hover:bg-slate-50'
                                }`}
                            >
                                {/* Avatar */}
                                <div className="w-9 h-9 rounded-full bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center text-white text-xs font-semibold shrink-0">
                                    {r.name.split(' ').map(p => p[0]).slice(0, 2).join('')}
                                </div>

                                {/* Details */}
                                <div className="flex-1 min-w-0">
                                    <div className="flex items-center gap-2 flex-wrap">
                                        <span className="text-sm font-medium text-slate-800 truncate">{r.name}</span>
                                        <span className="font-mono text-xs text-slate-500">{r.mrn}</span>
                                        <span className={`inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium ${STATUS_COLORS[r.enrollment_status] ?? 'bg-gray-100 text-gray-600'}`}>
                                            {r.enrollment_status}
                                        </span>
                                    </div>
                                    <div className="flex items-center gap-3 mt-0.5">
                                        <span className="text-xs text-slate-500">
                                            DOB {r.dob} · Age {r.age}
                                        </span>
                                        {r.flags.length > 0 && (
                                            <div className="flex gap-1 flex-wrap">
                                                {r.flags.slice(0, 3).map(flag => (
                                                    <span key={flag} className={`inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium ${flagColor(flag)}`}>
                                                        {flagLabel(flag)}
                                                    </span>
                                                ))}
                                                {r.flags.length > 3 && (
                                                    <span className="text-[10px] text-slate-500">+{r.flags.length - 3}</span>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                </div>

                                {/* Arrow indicator */}
                                {idx === activeIndex && (
                                    <svg className="w-4 h-4 text-blue-500 shrink-0 mt-2" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                                    </svg>
                                )}
                            </li>
                        ))}
                    </ul>
                )}

                {/* Footer hint */}
                {query.trim().length < 2 && (
                    <div className="px-4 py-3 flex items-center gap-4 text-[11px] text-slate-400">
                        <span><kbd className="px-1 py-0.5 bg-slate-100 border border-slate-200 rounded text-slate-500">↑↓</kbd> navigate</span>
                        <span><kbd className="px-1 py-0.5 bg-slate-100 border border-slate-200 rounded text-slate-500">↵</kbd> open</span>
                        <span><kbd className="px-1 py-0.5 bg-slate-100 border border-slate-200 rounded text-slate-500">ESC</kbd> close</span>
                    </div>
                )}
            </div>
        </div>
    );
}
