import { useState } from 'react';

type Paper = '80mm' | '58mm';

const STORAGE_KEY = 'print.receiptPaper';

const readPaper = (): Paper => {
    try {
        return window.localStorage.getItem(STORAGE_KEY) === '58mm' ? '58mm' : '80mm';
    } catch {
        return '80mm';
    }
};

/**
 * Thermal receipt width (80 mm or 58 mm), remembered per browser/counter.
 */
export function usePaperWidth() {
    const [paper, setPaper] = useState<Paper>(readPaper);

    const choose = (next: Paper) => {
        setPaper(next);
        try {
            window.localStorage.setItem(STORAGE_KEY, next);
        } catch {
            // Storage unavailable (private mode): the choice lasts for this page only.
        }
    };

    return {
        width: paper,
        // Literal class names so Tailwind generates them.
        maxWidthClass: paper === '58mm' ? 'max-w-[58mm]' : 'max-w-[80mm]',
        textClass: paper === '58mm' ? 'text-[10px]' : 'text-[12px]',
        toggle: (
            <div className="inline-flex rounded-md border p-0.5 text-sm" role="group" aria-label="Paper width">
                {(['80mm', '58mm'] as Paper[]).map((option) => (
                    <button
                        key={option}
                        type="button"
                        onClick={() => choose(option)}
                        aria-pressed={paper === option}
                        className={paper === option ? 'bg-primary text-primary-foreground rounded px-2 py-1' : 'rounded px-2 py-1'}
                    >
                        {option}
                    </button>
                ))}
            </div>
        ),
    };
}
