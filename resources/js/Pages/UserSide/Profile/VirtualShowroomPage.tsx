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
}

interface Props {
  shop: Shop;
  products: Product[];
}

const VirtualShowroomPage: React.FC<Props> = ({ shop, products }) => {
  const [isFocusMode, setIsFocusMode] = useState(false);

  return (
    <div className="h-screen overflow-hidden bg-white">
      <Head title={`${shop.name} - Virtual Showroom`} />

      <main className="h-screen">
        {!isFocusMode && (
          <div className="fixed left-4 top-4 z-50">
            <Link
              href={`/shop-profile/${shop.id}`}
              className="rounded-md border border-gray-300 bg-white/95 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-white"
            >
              Back to Shop Profile
            </Link>
          </div>
        )}

        <VirtualShowroom products={products} isStandalonePage onFocusModeChange={setIsFocusMode} />
      </main>
    </div>
  );
};

export default VirtualShowroomPage;
