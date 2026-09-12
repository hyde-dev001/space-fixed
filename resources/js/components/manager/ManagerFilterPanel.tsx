import type { ReactNode } from "react";

type ManagerFilterPanelProps = {
    title: string;
    titleId: string;
    description: string;
    metadata?: ReactNode;
    children: ReactNode;
};

export const ManagerFilterActions = ({ children }: { children: ReactNode }) => (
    <div className="col-span-full mt-4 flex flex-wrap justify-end gap-2">
        {children}
    </div>
);

export default function ManagerFilterPanel({ title, titleId, description, metadata, children }: ManagerFilterPanelProps) {
    return (
        <section className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-white/[0.03]" aria-labelledby={titleId}>
            <div className="mb-4 flex flex-col gap-3 border-b border-gray-200 pb-4 sm:flex-row sm:items-start sm:justify-between dark:border-gray-800">
                <div>
                    <h2 id={titleId} className="text-base font-semibold text-gray-900 dark:text-white">{title}</h2>
                    <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">{description}</p>
                </div>
                {metadata && <div className="shrink-0 text-sm sm:text-right">{metadata}</div>}
            </div>
            {children}
        </section>
    );
}
