import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import ReplenishmentSettingsModal from '../ReplenishmentSettingsModal';
import type { InventoryItem } from '@/types/inventory';

const updateReplenishmentSettings = vi.hoisted(() => vi.fn());

vi.mock('@/services/inventoryAPI', () => ({
    inventoryItemAPI: {
        updateReplenishmentSettings,
    },
}));

const item = {
    id: 7,
    name: 'Air Max 270',
    sku: 'AIR-270',
    category: 'shoes',
    unit: 'pairs',
    available_quantity: 23,
    reorder_level: 10,
    reorder_quantity: 40,
    auto_stock_request_enabled: false,
    color_variants: [
        {
            id: 1,
            color_name: 'Black',
            color_code: '#000000',
            quantity: 13,
            images: [],
            sizes: [
                { id: 11, inventory_item_id: 7, inventory_color_variant_id: 1, size: '8', size_system: 'US', quantity: 3 },
                { id: 12, inventory_item_id: 7, inventory_color_variant_id: 1, size: '9', size_system: 'US', quantity: 10 },
            ],
        },
        {
            id: 2,
            color_name: 'White',
            color_code: '#ffffff',
            quantity: 10,
            images: [],
            sizes: [
                { id: 21, inventory_item_id: 7, inventory_color_variant_id: 2, size: '8', size_system: 'US', quantity: 10 },
            ],
        },
    ],
    sizes: [
        { id: 11, inventory_item_id: 7, inventory_color_variant_id: 1, size: '8', size_system: 'US', quantity: 3, auto_stock_request_enabled: false, reorder_level: 5, reorder_quantity: 20 },
        { id: 12, inventory_item_id: 7, inventory_color_variant_id: 1, size: '9', size_system: 'US', quantity: 10, auto_stock_request_enabled: true, reorder_level: 10, reorder_quantity: 30 },
        { id: 21, inventory_item_id: 7, inventory_color_variant_id: 2, size: '8', size_system: 'US', quantity: 10, auto_stock_request_enabled: true, reorder_level: 8, reorder_quantity: 25 },
    ],
    images: [],
} as InventoryItem;

describe('ReplenishmentSettingsModal', () => {
    it('applies one policy to every actual variant and saves explicit targets', async () => {
        updateReplenishmentSettings.mockResolvedValue({ item });
        const onSaved = vi.fn();

        render(<ReplenishmentSettingsModal item={item} onClose={vi.fn()} onSaved={onSaved} />);

        expect(screen.getByText('Black / US 8')).toBeInTheDocument();
        expect(screen.getByText('Black / US 9')).toBeInTheDocument();
        expect(screen.getByText('White / US 8')).toBeInTheDocument();

        fireEvent.change(screen.getByLabelText('Reorder Level'), { target: { value: '10' } });
        fireEvent.change(screen.getByLabelText('Quantity to Request'), { target: { value: '40' } });
        fireEvent.click(screen.getByLabelText('Auto'));
        fireEvent.click(screen.getByRole('button', { name: 'Apply to All' }));
        fireEvent.click(screen.getByRole('button', { name: 'Save Settings' }));

        await waitFor(() => expect(updateReplenishmentSettings).toHaveBeenCalledWith(7, {
            targets: [
                { type: 'size', id: 11, auto_stock_request_enabled: true, reorder_level: 10, reorder_quantity: 40 },
                { type: 'size', id: 12, auto_stock_request_enabled: true, reorder_level: 10, reorder_quantity: 40 },
                { type: 'size', id: 21, auto_stock_request_enabled: true, reorder_level: 10, reorder_quantity: 40 },
            ],
        }));
        expect(onSaved).toHaveBeenCalledWith(item);
    });

    it('limits a color bulk action to that color sizes', () => {
        render(<ReplenishmentSettingsModal item={item} onClose={vi.fn()} onSaved={vi.fn()} />);

        fireEvent.change(screen.getByLabelText('Reorder Level'), { target: { value: '6' } });
        fireEvent.change(screen.getByLabelText('Quantity to Request'), { target: { value: '30' } });
        fireEvent.click(screen.getByRole('button', { name: 'Apply settings to all Black sizes' }));

        expect(screen.getByRole('spinbutton', { name: 'Reorder level for Black / US 8' })).toHaveValue(6);
        expect(screen.getByRole('spinbutton', { name: 'Reorder level for Black / US 9' })).toHaveValue(6);
        expect(screen.getByRole('spinbutton', { name: 'Reorder level for White / US 8' })).toHaveValue(8);
        expect(screen.getByRole('spinbutton', { name: 'Quantity to request for Black / US 8' })).toHaveValue(30);
        expect(screen.getByRole('spinbutton', { name: 'Quantity to request for White / US 8' })).toHaveValue(25);
    });
});
