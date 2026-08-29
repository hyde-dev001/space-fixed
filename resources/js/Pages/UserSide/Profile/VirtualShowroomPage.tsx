import React, { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import VirtualShowroom from '../Products/VirtualShowroom';
import Navigation from '../Shared/Navigation';

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
}

interface Props {
  shop: Shop;
  products: Product[];
}

const VirtualShowroomPage: React.FC<Props> = ({ shop, products }) => {
  const [isFocusMode, setIsFocusMode] = useState(false);
  const fromShopOwnerPremium =
    typeof window !== 'undefined' &&
    new URLSearchParams(window.location.search).get('from') === 'shop-owner-premium';
  const backHref = fromShopOwnerPremium ? '/shop-owner/premium-benefits' : `/shop-profile/${shop.id}`;
  const backLabel = fromShopOwnerPremium ? 'Back to Premium Benefits' : 'Back to Shop Profile';

  return (
    <div className="h-screen overflow-hidden bg-white">
      <Head title={`${shop.name} - Virtual Showroom`} />

      {!isFocusMode && <Navigation />}

      <main className="h-screen">
        {!isFocusMode && (
          <div className="fixed left-20 top-4 z-50">
            <Link
              href={backHref}
              className="rounded-md border border-gray-300 bg-white/95 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-white"
            >
              {backLabel}
            </Link>
          </div>
        )}

        <VirtualShowroom
          products={products}
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
