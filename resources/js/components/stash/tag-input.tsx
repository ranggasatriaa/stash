import { usePage } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import { useId, useMemo, useRef, useState } from 'react';
import type { KeyboardEvent } from 'react';
import { cn } from '@/lib/utils';

function normalize(name: string): string {
    return name
        .normalize('NFKC')
        .trim()
        .replace(/\s+/g, ' ')
        .replace(/^#+/, '')
        .toLowerCase();
}

/**
 * Accessible multi-tag combobox. Values are tag *names*; new names are
 * created server-side (unique per owner after case/space normalization).
 */
export function TagInput({
    value,
    onChange,
    id,
    label = 'Tags',
    placeholder = 'Add tags…',
    invalid,
    describedBy,
}: {
    value: string[];
    onChange: (tags: string[]) => void;
    id?: string;
    label?: string;
    placeholder?: string;
    invalid?: boolean;
    describedBy?: string;
}) {
    const autoId = useId();
    const inputId = id ?? `tags-${autoId}`;
    const listId = `${inputId}-list`;
    const known = usePage().props.nav?.tags ?? [];
    const [query, setQuery] = useState('');
    const [open, setOpen] = useState(false);
    const [active, setActive] = useState(0);
    const inputRef = useRef<HTMLInputElement>(null);

    const selected = useMemo(() => new Set(value.map(normalize)), [value]);
    const q = normalize(query);
    const suggestions = useMemo(() => {
        const list = known
            .filter(
                (t) =>
                    !selected.has(normalize(t.name)) &&
                    (q === '' || normalize(t.name).includes(q)),
            )
            .sort((a, b) => {
                const ap = normalize(a.name).startsWith(q) ? 0 : 1;
                const bp = normalize(b.name).startsWith(q) ? 0 : 1;

                return ap - bp || b.count - a.count;
            })
            .slice(0, 8)
            .map((t) => ({ name: t.name, create: false }));
        if (
            q &&
            !known.some((t) => normalize(t.name) === q) &&
            !selected.has(q)
        ) {
            list.push({ name: query.trim().replace(/^#+/, ''), create: true });
        }

        return list;
    }, [known, selected, q, query]);

    const add = (name: string) => {
        const clean = name
            .trim()
            .replace(/\s+/g, ' ')
            .replace(/^#+/, '')
            .slice(0, 64);
        if (!clean || selected.has(normalize(clean)) || value.length >= 50)
            return;
        onChange([...value, clean]);
        setQuery('');
        setActive(0);
    };

    const remove = (name: string) => onChange(value.filter((t) => t !== name));

    const onKeyDown = (e: KeyboardEvent<HTMLInputElement>) => {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setOpen(true);
            setActive((i) =>
                Math.min(i + 1, Math.max(suggestions.length - 1, 0)),
            );
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActive((i) => Math.max(i - 1, 0));
        } else if (e.key === 'Enter' || e.key === ',' || e.key === 'Tab') {
            const pick =
                open && suggestions[active] ? suggestions[active].name : query;
            if (pick.trim()) {
                e.preventDefault();
                add(pick);
            }
        } else if (e.key === 'Backspace' && query === '' && value.length) {
            remove(value[value.length - 1]);
        } else if (e.key === 'Escape' && open) {
            e.stopPropagation();
            setOpen(false);
        }
    };

    return (
        <div className="st-field">
            <label htmlFor={inputId} className="st-field__label">
                {label}
            </label>
            <div
                className={cn(
                    'st-input flex min-h-[44px] flex-wrap items-center gap-1.5 !py-1.5',
                    'focus-within:shadow-[0_0_0_3px_#fff,0_0_0_6px_#E35D00]',
                )}
                aria-invalid={invalid || undefined}
                onClick={() => inputRef.current?.focus()}
            >
                {value.map((tag, i) => (
                    <span
                        key={tag}
                        className={cn('st-tag', i % 2 ? 'st-tag--orange' : '')}
                    >
                        <span>{tag}</span>
                        <button
                            type="button"
                            className="st-tag__remove"
                            aria-label={`Remove tag ${tag}`}
                            onClick={(e) => {
                                e.stopPropagation();
                                remove(tag);
                            }}
                        >
                            <X aria-hidden="true" />
                        </button>
                    </span>
                ))}
                <div className="relative min-w-[8rem] flex-1">
                    <input
                        ref={inputRef}
                        id={inputId}
                        type="text"
                        role="combobox"
                        aria-expanded={open && suggestions.length > 0}
                        aria-controls={listId}
                        aria-autocomplete="list"
                        aria-activedescendant={
                            open && suggestions[active]
                                ? `${listId}-${active}`
                                : undefined
                        }
                        aria-describedby={describedBy}
                        className="w-full border-0 bg-transparent p-0 py-1 text-base outline-none placeholder:text-[#8a8c92]"
                        placeholder={value.length ? '' : placeholder}
                        value={query}
                        maxLength={64}
                        autoComplete="off"
                        onChange={(e) => {
                            setQuery(e.target.value);
                            setOpen(true);
                            setActive(0);
                        }}
                        onFocus={() => setOpen(true)}
                        onBlur={() =>
                            window.setTimeout(() => setOpen(false), 120)
                        }
                        onKeyDown={onKeyDown}
                    />
                    {open && suggestions.length > 0 && (
                        <ul
                            id={listId}
                            role="listbox"
                            aria-label="Tag suggestions"
                            className="st-menu absolute bottom-[calc(100%+10px)] left-[-12px] z-50 max-h-56 w-[min(18rem,80vw)] overflow-y-auto"
                        >
                            {suggestions.map((s, i) => (
                                <li
                                    key={`${s.name}-${s.create}`}
                                    id={`${listId}-${i}`}
                                    role="option"
                                    aria-selected={i === active}
                                    className="st-menu__item"
                                    data-highlighted={
                                        i === active ? '' : undefined
                                    }
                                    onMouseDown={(e) => {
                                        e.preventDefault();
                                        add(s.name);
                                    }}
                                    onMouseEnter={() => setActive(i)}
                                >
                                    {s.create ? (
                                        <Plus aria-hidden="true" />
                                    ) : null}
                                    <span className="truncate">
                                        {s.create
                                            ? `Create “${s.name}”`
                                            : s.name}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        </div>
    );
}

export function ProjectSelect({
    value,
    onChange,
    id,
    label = 'Project',
    error,
}: {
    value: string | null;
    onChange: (id: string | null) => void;
    id?: string;
    label?: string;
    error?: string;
}) {
    const autoId = useId();
    const selectId = id ?? `project-${autoId}`;
    const projects = usePage().props.nav?.projects ?? [];

    return (
        <div className="st-field">
            <label htmlFor={selectId} className="st-field__label">
                {label}
            </label>
            <select
                id={selectId}
                className="st-select"
                value={value ?? ''}
                aria-invalid={error ? true : undefined}
                onChange={(e) => onChange(e.target.value || null)}
            >
                <option value="">No project</option>
                {projects.map((p) => (
                    <option key={p.id} value={p.id}>
                        {p.name}
                    </option>
                ))}
            </select>
            {error && <p className="st-field__error">{error}</p>}
        </div>
    );
}
