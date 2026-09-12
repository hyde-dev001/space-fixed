import React, { FormEvent, useEffect, useMemo, useState } from 'react';
import Swal from 'sweetalert2';
import { Modal } from '@/components/ui/modal';
import { inventoryItemAPI } from '@/services/inventoryAPI';
import type {
    InventoryColorVariant,
    InventoryItem,
    InventoryReplenishmentTargetInput,
    InventorySize,
} from '@/types/inventory';

type ReplenishmentRow = InventoryReplenishmentTargetInput & {
    colorName?: string;
    size?: string;
    sizeSystem?: string;
    quantity: number;
};

type ReplenishmentSettingsModalProps = {
    item: InventoryItem;
    onClose: () => void;
    onSaved: (item: InventoryItem) => void;
};

type BulkSettings = {
    auto_stock_request_enabled: boolean;
    reorder_level: string;
    reorder_quantity: string;
};

const rowKey = (row: Pick<ReplenishmentRow, 'type' | 'id'>): string => `${row.type}:${row.id}`;

const makeRow = (
    target: InventoryReplenishmentTargetInput,
    quantity: number,
    colorName?: string,
    size?: string,
    sizeSystem?: string,
): ReplenishmentRow => ({
    ...target,
    quantity,
    colorName,
    size,
    sizeSystem,
});

const rowsForItem = (item: InventoryItem): ReplenishmentRow[] => {
    const colors = item.color_variants ?? [];
    const sizes = item.sizes?.length
        ? item.sizes
        : colors.flatMap((color) => color.sizes ?? []);
    const rows: ReplenishmentRow[] = [];

    sizes.forEach((size: InventorySize) => {
        const color = colors.find((candidate: InventoryColorVariant) => Number(candidate.id) === Number(size.inventory_color_variant_id));
        rows.push(makeRow(
            {
                type: 'size',
                id: size.id,
                auto_stock_request_enabled: size.auto_stock_request_enabled ?? false,
                reorder_level: size.reorder_level ?? item.reorder_level,
                reorder_quantity: size.reorder_quantity ?? item.reorder_quantity,
            },
            size.quantity,
            color?.color_name,
            size.size,
            size.size_system,
        ));
    });

    colors.forEach((color: InventoryColorVariant) => {
        if ((color.sizes ?? []).length > 0 || sizes.some((size) => Number(size.inventory_color_variant_id) === Number(color.id))) {
            return;
        }

        rows.push(makeRow(
            {
                type: 'color',
                id: color.id,
                auto_stock_request_enabled: color.auto_stock_request_enabled ?? false,
                reorder_level: color.reorder_level ?? item.reorder_level,
                reorder_quantity: color.reorder_quantity ?? item.reorder_quantity,
            },
            color.quantity,
            color.color_name,
        ));
    });

    if (rows.length === 0) {
        rows.push(makeRow(
            {
                type: 'item',
                id: item.id,
                auto_stock_request_enabled: item.auto_stock_request_enabled,
                reorder_level: item.reorder_level,
                reorder_quantity: item.reorder_quantity,
            },
            item.available_quantity,
        ));
    }

    return rows;
};

const initialBulkSettings = (rows: ReplenishmentRow[]): BulkSettings => {
    const first = rows[0];

    return {
        auto_stock_request_enabled: first?.auto_stock_request_enabled ?? false,
        reorder_level: String(first?.reorder_level ?? 0),
        reorder_quantity: String(first?.reorder_quantity ?? 1),
    };
};

const displaySize = (row: ReplenishmentRow): string =>
    row.size ? (row.sizeSystem && !row.size.toUpperCase().startsWith(`${row.sizeSystem.toUpperCase()} `) ? `${row.sizeSystem.toUpperCase()} ${row.size}` : row.size) : '-';

const displayScope = (row: ReplenishmentRow): string => {
    if (row.type === 'item') return 'Stock Item';
    if (row.type === 'color') return row.colorName || 'Color';
    return [row.colorName, displaySize(row)].filter(Boolean).join(' / ');
};

export default function ReplenishmentSettingsModal({
    item,
    onClose,
    onSaved,
}: ReplenishmentSettingsModalProps) {
    const initialRows = useMemo(() => rowsForItem(item), [item]);
    const [rows, setRows] = useState<ReplenishmentRow[]>(initialRows);
    const [bulk, setBulk] = useState<BulkSettings>(() => initialBulkSettings(initialRows));
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');

    useEffect(() => {
        setRows(initialRows);
        setBulk(initialBulkSettings(initialRows));
        setError('');
    }, [initialRows]);

    const colorsWithSizes = useMemo(() => {
        const groups = new Map<string, ReplenishmentRow[]>();
        rows.forEach((row) => {
            const group = row.colorName || 'Other sizes';
            groups.set(group, [...(groups.get(group) ?? []), row]);
        });
        return Array.from(groups.entries());
    }, [rows]);

    const updateRows = (keys: Set<string>, values: Partial<ReplenishmentRow>) => {
        setRows((current) => current.map((row) => (
            keys.has(rowKey(row)) ? { ...row, ...values } : row
        )));
    };

    const applyBulk = (keys: Set<string>) => {
        updateRows(keys, {
            auto_stock_request_enabled: bulk.auto_stock_request_enabled,
            reorder_level: Number(bulk.reorder_level),
            reorder_quantity: Number(bulk.reorder_quantity),
        });
    };

    const validateRows = (): string | null => {
        if (rows.some((row) => !Number.isInteger(row.reorder_level) || row.reorder_level < 0)) {
            return 'Reorder level must be a whole number of 0 or more.';
        }
        if (rows.some((row) => !Number.isInteger(row.reorder_quantity) || row.reorder_quantity < 1)) {
            return 'Quantity to Request must be a whole number greater than zero.';
        }
        return null;
    };

    const handleSubmit = async (event: FormEvent) => {
        event.preventDefault();
        const validationError = validateRows();
        if (validationError) {
            setError(validationError);
            return;
        }

        setSaving(true);
        setError('');
        try {
            const response = await inventoryItemAPI.updateReplenishmentSettings(item.id, {
                targets: rows.map(({ type, id, auto_stock_request_enabled, reorder_level, reorder_quantity }) => ({
                    type,
                    id,
                    auto_stock_request_enabled,
                    reorder_level,
                    reorder_quantity,
                })),
            });
            await Swal.fire({
                icon: 'success',
                title: 'Settings saved',
                text: 'Automatic replenishment settings updated successfully.',
                timer: 1500,
                showConfirmButton: false,
            });
            onSaved(response.item);
        } catch (requestError) {
            setError((requestError as { message?: string })?.message || 'Unable to save replenishment settings.');
        } finally {
            setSaving(false);
        }
    };

    const allKeys = new Set(rows.map(rowKey));

    return (
        <Modal isOpen={true} onClose={onClose} size="5xl" showCloseButton={false} zIndex={1000000} className="m-4 max-h-[calc(100dvh-2rem)] overflow-hidden !rounded-3xl">
            <div
                className="flex max-h-[calc(100dvh-2rem)] w-full flex-col overflow-hidden rounded-3xl bg-white dark:bg-gray-800"
                role="dialog"
                aria-modal="true"
                aria-labelledby="replenishment-settings-title"
            >
                <div className="flex items-start justify-between gap-4 border-b border-gray-200 px-6 py-5 dark:border-gray-700">
                    <div>
                        <h2 id="replenishment-settings-title" className="text-xl font-bold text-gray-900 dark:text-white">
                            Automatic Replenishment
                        </h2>
                        <p className="mt-1 text-sm text-gray-600 dark:text-gray-400">{item.name}</p>
                    </div>
                    <button type="button" onClick={onClose} className="rounded-lg p-2 text-gray-500 hover:bg-gray-100 hover:text-gray-900 dark:hover:bg-gray-700 dark:hover:text-white" aria-label="Close replenishment settings">
                        <span aria-hidden="true" className="text-xl leading-none">&times;</span>
                    </button>
                </div>

                <form onSubmit={handleSubmit} className="flex min-h-0 flex-1 flex-col">
                    <div className="flex-1 space-y-5 overflow-y-auto p-6">
                        <div className="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900/50">
                            <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                                <div>
                                    <h3 className="text-sm font-semibold text-gray-900 dark:text-white">Apply to All Colors &amp; Sizes</h3>
                                    <p className="mt-1 text-xs text-gray-600 dark:text-gray-400">This writes the same policy to each actual inventory target.</p>
                                </div>
                                <div className="flex flex-wrap items-end gap-3">
                                    <label className="flex items-center gap-2 pb-2 text-sm text-gray-700 dark:text-gray-300">
                                        <input
                                            type="checkbox"
                                            checked={bulk.auto_stock_request_enabled}
                                            onChange={(event) => setBulk((current) => ({ ...current, auto_stock_request_enabled: event.target.checked }))}
                                            className="h-4 w-4 rounded border-gray-300 text-gray-900 focus:ring-gray-900"
                                        />
                                        Auto
                                    </label>
                                    <label className="text-xs font-medium text-gray-600 dark:text-gray-400">
                                        Reorder Level
                                        <input
                                            type="number"
                                            min="0"
                                            step="1"
                                            value={bulk.reorder_level}
                                            onChange={(event) => setBulk((current) => ({ ...current, reorder_level: event.target.value }))}
                                            className="mt-1 block w-28 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                                        />
                                    </label>
                                    <label className="text-xs font-medium text-gray-600 dark:text-gray-400">
                                        Quantity to Request
                                        <input
                                            type="number"
                                            min="1"
                                            step="1"
                                            value={bulk.reorder_quantity}
                                            onChange={(event) => setBulk((current) => ({ ...current, reorder_quantity: event.target.value }))}
                                            className="mt-1 block w-36 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                                        />
                                    </label>
                                    <button type="button" onClick={() => applyBulk(allKeys)} className="rounded-lg bg-black px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800">
                                        Apply to All
                                    </button>
                                </div>
                            </div>
                        </div>

                        {rows.some((row) => row.type === 'item') ? (
                            <SettingsTable rows={rows} updateRows={updateRows} />
                        ) : (
                            colorsWithSizes.map(([colorName, colorRows]) => {
                                const sizeRows = colorRows.filter((row) => row.type === 'size');
                                const colorKeys = new Set(colorRows.map(rowKey));
                                return (
                                    <section key={colorName} className="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
                                        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 bg-white px-4 py-3 dark:border-gray-700 dark:bg-gray-800">
                                            <h3 className="font-semibold text-gray-900 dark:text-white">{colorName}</h3>
                                            {sizeRows.length > 0 && (
                                                <button type="button" onClick={() => applyBulk(colorKeys)} className="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                                                    Apply settings to all {colorName} sizes
                                                </button>
                                            )}
                                        </div>
                                        <SettingsTable rows={colorRows} updateRows={updateRows} />
                                    </section>
                                );
                            })
                        )}

                        {error && <p role="alert" className="text-sm text-red-600 dark:text-red-400">{error}</p>}
                    </div>

                    <div className="flex justify-end gap-3 border-t border-gray-200 px-6 py-4 dark:border-gray-700">
                        <button type="button" onClick={onClose} disabled={saving} className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                            Cancel
                        </button>
                        <button type="submit" disabled={saving} className="rounded-lg bg-black px-5 py-2 text-sm font-semibold text-white hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-50">
                            {saving ? 'Saving...' : 'Save Settings'}
                        </button>
                    </div>
                </form>
            </div>
        </Modal>
    );
}

type SettingsTableProps = {
    rows: ReplenishmentRow[];
    updateRows: (keys: Set<string>, values: Partial<ReplenishmentRow>) => void;
};

function SettingsTable({ rows, updateRows }: SettingsTableProps) {
    return (
        <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead className="bg-gray-50 dark:bg-gray-900/50">
                    <tr>
                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Target</th>
                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Stock</th>
                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Auto</th>
                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Reorder Level</th>
                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Quantity to Request</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800">
                    {rows.map((row) => {
                        const key = rowKey(row);
                        return (
                            <tr key={key}>
                                <td className="whitespace-nowrap px-4 py-3 text-sm font-medium text-gray-900 dark:text-white">{displayScope(row)}</td>
                                <td className="whitespace-nowrap px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{row.quantity}</td>
                                <td className="px-4 py-3">
                                    <input
                                        type="checkbox"
                                        checked={row.auto_stock_request_enabled}
                                        onChange={(event) => updateRows(new Set([key]), { auto_stock_request_enabled: event.target.checked })}
                                        aria-label={`Enable automatic replenishment for ${displayScope(row)}`}
                                        className="h-4 w-4 rounded border-gray-300 text-gray-900 focus:ring-gray-900"
                                    />
                                </td>
                                <td className="px-4 py-3">
                                    <input
                                        type="number"
                                        min="0"
                                        step="1"
                                        value={row.reorder_level}
                                        onChange={(event) => updateRows(new Set([key]), { reorder_level: Number(event.target.value) })}
                                        aria-label={`Reorder level for ${displayScope(row)}`}
                                        className="w-28 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 dark:border-gray-600 dark:bg-gray-900 dark:text-white"
                                    />
                                </td>
                                <td className="px-4 py-3">
                                    <input
                                        type="number"
                                        min="1"
                                        step="1"
                                        value={row.reorder_quantity}
                                        onChange={(event) => updateRows(new Set([key]), { reorder_quantity: Number(event.target.value) })}
                                        aria-label={`Quantity to request for ${displayScope(row)}`}
                                        className="w-36 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 dark:border-gray-600 dark:bg-gray-900 dark:text-white"
                                    />
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}
