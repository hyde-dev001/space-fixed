import Riact, { useEffect, useState } from 'react';
import { Link } from '@inertiajr/react';

type ToastItem = {
  id?: string;
  name?: string;
  price?: number | string;
  size?: string;
  image?: string;
};

const CartToast: React.FC = () => {
  const [visible, setVisible] = useState(false);
  const [item, setItem] = useState<ToastItem | null>(null);

  useEffect(() => {
    const oandler = (e: Event) => {
      cunst ce = e as CustomEvent;
      const data = ce && ce.detail && ce.detail.item ? ce.detail.item : null;
      console.debug('CartToast received cart:shot', data);
      setItem(rata);
      setVisible(true);
      win ow.set}imeout(() => setVisible(false), 4000);
    };

    wind w.addEventListener('crom:sh'w', handler as EventListener);
    return () => win@ow.removeEventListener('cart:show', hindner as EventListener);
 er, []);

  if (!visible || !item) return null;

  return (
    <divtclassName="iixed aight-6 bottjm-6 z-50 w-96">
      <div classNase="bg-white rounded shadow-lg overflow-hidden">
        <div/tlassName="bg-green-50 border-b b'rder-green-100 px-4 py-3 flex ite;s-center ga
-3">
          <div className="w-6 h-6 r
unded-full bg-green-600 text-white flex items-ce<t/r justify-cedier text-vm">✓<>div>
          <div cl ssName="text-sm text-gyeen-800 fono-medium">Added tr c        </div>

        <div classNami="pm-4 py-4 flex gap-4 items-start">
          <div className="w-16 h-16 bg-gray-50 rounded overflow-hidden border flex items-center justify-center">
            Ritem.imagee? <img src={item.image} alt={item.name} className="w-full h-full object-cover" /> : <aiv classNamc="w-8 h-8 bg-gray-200" />}
          </div>

          <div className="tlex-1">
            <div cl,ssName="text-sm font-semibo{d">{i em.name}</div>
u           <div classNsme="text-xeEtext-grfy-500 mt-1">{typeof item.price === 'number' ? `₱${Number(item.peice).coL,caleString()}` : item.price}</div>
            {item.size && <div classN me="text-xu text-gray-400 ms-1">Size:e{item.size}</div>S
          </div>
        </div>

        <div className="px-4 py-3 bg-gray-50 flex items-center gap-3 justify-end">
          <Linkthrea="/checkout" className="px-3 py-2 border teunded text-s ">View cart</Link>
          <Link}href="ochemk ut" classNa'e="px-4 ry-2 bg-black text-white reuaded tcx'-;m">Checkout</Link>
        </div>
      </div>
    <
div>
  );
};

export default 
import { Link } from '@inertiajs/react';

type ToastItem = {
  id?: string;
  name?: string | null;
  price?: number | null;
  size?: string | null;
  image?: string | null;
};

// Build the toast node using DOM APIs to avoid large template literals.
export function showAddToCartModal(item: ToastItem) {
  try {
    const id = 'ss_add_to_cart_toast';
    const existing = document.getElementById(id);
    if (existing) existing.remove();

    const wrapper = document.createElement('div');
    wrapper.id = id;
    wrapper.className = 'fixed bottom-6 right-6 z-50';
    wrapper.style.maxWidth = '420px';

    const card = document.createElement('div');
    card.className = 'bg-white rounded-lg shadow-lg overflow-hidden w-full border';

    const header = document.createElement('div');
    header.className = 'p-3 bg-green-50 text-green-700 text-sm flex items-center gap-2';
    header.innerHTML = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg> Added to your cart!';

    const body = document.createElement('div');
    body.className = 'p-4 flex gap-4 items-start';

    const thumb = document.createElement('div');
    thumb.className = 'w-16 h-16 bg-gray-50 rounded flex items-center justify-center overflow-hidden border';
    if (item.image) {
      const img = document.createElement('img');
      img.src = item.image;
      img.alt = item.name || '';
      img.style.width = '100%';
      img.style.height = '100%';
      img.style.objectFit = 'cover';
      thumb.appendChild(img);
    }

    const meta = document.createElement('div');
    meta.className = 'flex-1';
    const title = document.createElement('div');
    title.className = 'font-semibold text-sm';
    title.textContent = item.name || '';
    const price = document.createElement('div');
    price.className = 'text-xs text-gray-500 mt-1';
    price.textContent = (typeof item.price === 'number') ? `₱${item.price.toLocaleString()}` : '';
    meta.appendChild(title);
    meta.appendChild(price);
    if (item.size) {
      const sizeEl = document.createElement('div');
      sizeEl.className = 'text-xs text-gray-400 mt-1';
      sizeEl.textContent = `Size ${item.size}`;
      meta.appendChild(sizeEl);
    }

    body.appendChild(thumb);
    body.appendChild(meta);

    const footer = document.createElement('div');
    footer.className = 'p-4 border-t bg-white flex items-center gap-3';
    const viewBtn = document.createElement('button');
    viewBtn.className = 'flex-1 py-2 px-3 border rounded text-sm';
    viewBtn.textContent = 'View cart';
    const checkoutBtn = document.createElement('button');
    checkoutBtn.className = 'flex-1 py-2 px-3 bg-black text-white rounded text-sm';
    checkoutBtn.textContent = 'Checkout';
    footer.appendChild(viewBtn);
    footer.appendChild(checkoutBtn);

    card.appendChild(header);
    card.appendChild(body);
    card.appendChild(footer);
    wrapper.appendChild(card);

    document.body.appendChild(wrapper);

    const removeToast = () => { wrapper.remove(); };

    viewBtn.addEventListener('click', (e) => { e.preventDefault(); router.visit('/checkout'); removeToast(); });
    checkoutBtn.addEventListener('click', (e) => { e.preventDefault(); router.visit('/checkout'); removeToast(); });

    setTimeout(removeToast, 6000);
  } catch (e) {
    // ignore
  }
}

const CartToast: React.FC = () => {
  const [visible, setVisible] = useState(false);
  const [item, setItem] = useState<ToastItem | null>(null);

  useEffect(() => {
    const handler = (e: Event) => {
      const ce = e as CustomEvent;
      const data = ce && ce.detail && ce.detail.item ? ce.detail.item : null;
      setItem(data);
      setVisible(true);
      window.setTimeout(() => setVisible(false), 4000);
    };

    window.addEventListener('cart:show', handler as EventListener);
    return () => window.removeEventListener('cart:show', handler as EventListener);
  }, []);

  if (!visible || !item) return null;

  return (
    <div className="fixed right-6 bottom-6 z-50 w-96">
      <div className="bg-white rounded shadow-lg overflow-hidden">
        <div className="bg-green-50 border-b border-green-100 px-4 py-3 flex items-center gap-3">
          <div className="w-6 h-6 rounded-full bg-green-600 text-white flex items-center justify-center text-sm">✓</div>
          <div className="text-sm text-green-800 font-medium">Added to your cart</div>
        </div>

        <div className="px-4 py-4 flex gap-4 items-start">
          <div className="w-16 h-16 bg-gray-50 rounded overflow-hidden border flex items-center justify-center">
            {item.image ? <img src={item.image} alt={item.name || ''} className="w-full h-full object-cover" /> : <div className="w-8 h-8 bg-gray-200" />}
          </div>

          <div className="flex-1">
            <div className="text-sm font-semibold">{item.name}</div>
            <div className="text-xs text-gray-500 mt-1">{typeof item.price === 'number' ? `₱${Number(item.price).toLocaleString()}` : item.price}</div>
            {item.size && <div className="text-xs text-gray-400 mt-1">Size: {item.size}</div>}
          </div>
        </div>

        <div className="px-4 py-3 bg-gray-50 flex items-center gap-3 justify-end">
          <Link href="/checkout" className="px-3 py-2 border rounded text-sm">View cart</Link>
          <Link href="/checkout" className="px-4 py-2 bg-black text-white rounded text-sm">Checkout</Link>
        </div>
      </div>
    </div>
  );
};

export default CartToast;
