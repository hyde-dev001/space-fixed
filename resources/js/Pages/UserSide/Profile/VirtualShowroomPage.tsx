import React, { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import VirtualShowroom from '../Products/VirtualShowroom';

interface Product {
  id: number;
  name: string;
  slug: string;
  price: number;
  compare_at_price?: number;
  brand?: string;
  category: string;
  stock_quantity: number;
  main_image: string;
  hover_image?: string | null;
  gallery_images?: string[];
  description?: string;
}

interface Shop {
  id: number;
  name: string;
  showroom_slot_limit?: number | null;
  showroom_plan_code?: string | null;
  showroom_plan_name?: string | null;
  showroom_placements?: Array<{ product_id: number; slot_key: string }>;
  showroom_wall_art?: { left?: string | null; right?: string | null };
  can_edit_showroom?: boolean;
  can_manage_showroom_art?: boolean;
  showroom_setup_required?: boolean;
}

interface Props {
  shop: Shop;
  products: Product[];
}

const VirtualShowroomPage: React.FC<Props> = ({ shop, products }) => {
  const [isFocusMode, setIsFocusMode] = useState(false);
  const showroomOrigin =
    typeof window !== 'undefined'
      ? new URLSearchParams(window.location.search).get('from')
      : null;
  const fromStaffProducts = showroomOrigin === 'staff-products';
  const fromShopOwnerPremium =
    showroomOrigin === 'shop-owner-premium';
  const backHref = fromStaffProducts
    ? '/erp/staff/products'
    : fromShopOwnerPremium
      ? '/shop-owner/premium-benefits'
      : `/shop-profile/${shop.id}`;
  const backLabel = fromStaffProducts
    ? 'Back to Product Management'
    : fromShopOwnerPremium
      ? 'Back to Premium Benefits'
      : 'Back to Shop Profile';

  return (
    <div className="h-dvh overflow-hidden bg-white">
      <Head title={`${shop.name} - Virtual Showroom`} />

      <main className="h-dvh">
        {!isFocusMode && (
          <div className="fixed left-3 top-3 z-50 max-w-[calc(100vw-8.5rem)] sm:left-20 sm:top-4 sm:max-w-none">
            <Link
              href={backHref}
              className="inline-flex min-h-11 max-w-full items-center break-words rounded-md border border-gray-300 bg-white/95 px-4 py-2 text-center text-sm font-medium leading-tight text-gray-700 hover:bg-white"
            >
              {backLabel}
            </Link>
          </div>
        )}

        <VirtualShowroom
          products={products}
          shopName={shop.name}
          showroomPlacements={(shop.showroom_placements ?? []).map((placement) => ({
            productId: placement.product_id,
            slotKey: placement.slot_key,
          }))}
          canEditShowroom={shop.can_edit_showroom === true}
          canManageShowroomArt={shop.can_manage_showroom_art === true}
          showroomWallArt={{
            left: shop.showroom_wall_art?.left ?? null,
            right: shop.showroom_wall_art?.right ?? null,
          }}
          showroomSetupRequired={shop.showroom_setup_required === true}
          isStandalonePage
          onFocusModeChange={setIsFocusMode}
          showroomSlotLimit={shop.showroom_slot_limit}
          showroomPlanCode={shop.showroom_plan_code}
          showroomPlanName={shop.showroom_plan_name}
        />
      </main>
    </div>
  );
};

export default VirtualShowroomPage;
