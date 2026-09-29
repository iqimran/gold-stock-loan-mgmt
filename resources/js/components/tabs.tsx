import { cn } from '@/lib/utils';
import { type KeyboardEvent, type ReactNode, useId, useRef } from 'react';

export interface TabItem {
    value: string;
    label: ReactNode;
    /** Optional count/badge shown after the label. */
    badge?: ReactNode;
}

interface TabsProps {
    tabs: TabItem[];
    value: string;
    onChange: (value: string) => void;
    label: string;
    children: (active: string) => ReactNode;
}

/**
 * Accessible tabs (WAI-ARIA tablist): arrow keys / Home / End move between tabs.
 * Scrolls horizontally on narrow screens instead of wrapping.
 */
export function Tabs({ tabs, value, onChange, label, children }: TabsProps) {
    const id = useId();
    const refs = useRef<(HTMLButtonElement | null)[]>([]);

    const move = (event: KeyboardEvent, index: number) => {
        const last = tabs.length - 1;
        const next = { ArrowRight: index === last ? 0 : index + 1, ArrowLeft: index === 0 ? last : index - 1, Home: 0, End: last }[event.key];

        if (next === undefined) {
            return;
        }

        event.preventDefault();
        onChange(tabs[next].value);
        refs.current[next]?.focus();
    };

    return (
        <div>
            <div role="tablist" aria-label={label} className="flex gap-1 overflow-x-auto border-b">
                {tabs.map((tab, index) => {
                    const selected = tab.value === value;

                    return (
                        <button
                            key={tab.value}
                            ref={(element) => {
                                refs.current[index] = element;
                            }}
                            type="button"
                            role="tab"
                            id={`${id}-tab-${tab.value}`}
                            aria-selected={selected}
                            aria-controls={`${id}-panel-${tab.value}`}
                            tabIndex={selected ? 0 : -1}
                            onClick={() => onChange(tab.value)}
                            onKeyDown={(event) => move(event, index)}
                            className={cn(
                                'focus-visible:ring-ring/50 -mb-px flex shrink-0 items-center gap-2 border-b-2 px-4 py-2 text-sm font-medium whitespace-nowrap outline-none focus-visible:ring-[3px]',
                                selected ? 'border-primary text-foreground' : 'text-muted-foreground hover:text-foreground border-transparent',
                            )}
                        >
                            {tab.label}
                            {tab.badge !== undefined && <span className="bg-muted rounded-full px-2 py-0.5 text-xs">{tab.badge}</span>}
                        </button>
                    );
                })}
            </div>
            <div role="tabpanel" id={`${id}-panel-${value}`} aria-labelledby={`${id}-tab-${value}`} tabIndex={0} className="pt-6 outline-none">
                {children(value)}
            </div>
        </div>
    );
}
