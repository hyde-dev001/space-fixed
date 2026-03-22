import React, { useEffect, useMemo, useRef, useState } from 'react';
import { Head, Link } from '@inertiajs/react';

interface Product {
  id: number;
  name: string;
  slug: string;
  price: number;
  main_image?: string | null;
  hover_image?: string | null;
  gallery_images?: string[];
  shop_owner?: {
    business_name?: string | null;
  } | null;
}

interface Shop {
  id: number;
  shopName: string;
  shopLocation: string;
  image?: string | null;
  bio?: string | null;
}

interface ServiceHighlight {
  title: string;
  description: string;
}

interface Props {
  products: Product[];
  shops: Shop[];
  serviceHighlights: ServiceHighlight[];
}

type SectionId = 'hero' | 'products' | 'repair' | 'services';

const sectionOrder: SectionId[] = ['hero', 'products', 'repair', 'services'];

const SpaLanding: React.FC<Props> = ({ products = [], shops = [], serviceHighlights = [] }) => {
  const [activeSection, setActiveSection] = useState<SectionId>('hero');
  const [heroParallaxY, setHeroParallaxY] = useState(0);
  const [accentParallaxY, setAccentParallaxY] = useState(0);
  const reducedMotionRef = useRef(false);

  const topMenuItems = useMemo(
    () => [
      { id: 'hero' as const, label: 'Home' },
      { id: 'products' as const, label: 'Products' },
      { id: 'repair' as const, label: 'Repair' },
      { id: 'services' as const, label: 'Services' },
    ],
    []
  );

  useEffect(() => {
    reducedMotionRef.current = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (reducedMotionRef.current) {
      return;
    }

    let rafId = 0;

    const onScroll = () => {
      if (rafId) {
        return;
      }

      rafId = window.requestAnimationFrame(() => {
        const y = window.scrollY;
        setHeroParallaxY(y * 0.24);
        setAccentParallaxY(y * 0.11);
        rafId = 0;
      });
    };

    window.addEventListener('scroll', onScroll, { passive: true });

    return () => {
      window.removeEventListener('scroll', onScroll);
      if (rafId) {
        window.cancelAnimationFrame(rafId);
      }
    };
  }, []);

  useEffect(() => {
    const observers: IntersectionObserver[] = [];

    sectionOrder.forEach((sectionId) => {
      const section = document.getElementById(sectionId);
      if (!section) {
        return;
      }

      const observer = new IntersectionObserver(
        (entries) => {
          if (entries[0].isIntersecting) {
            setActiveSection(sectionId);
          }
        },
        { threshold: 0.45 }
      );

      observer.observe(section);
      observers.push(observer);
    });

    return () => {
      observers.forEach((observer) => observer.disconnect());
    };
  }, []);

  const scrollToSection = (sectionId: SectionId) => {
    const target = document.getElementById(sectionId);
    if (!target) {
      return;
    }

    const behavior = reducedMotionRef.current ? 'auto' : 'smooth';
    target.scrollIntoView({ behavior, block: 'start' });
  };

  const productImage = (product: Product) =>
    product.main_image || product.hover_image || product.gallery_images?.[0] || '/images/shop/p1.jpg';

  return (
    <>
      <Head title="SoleSpace SPA Pilot" />

      <div className="min-h-screen bg-[#f4f5f7] text-[#111827] font-outfit antialiased">
        <header className="fixed inset-x-0 top-0 z-50 border-b border-white/20 bg-[#0f172abf] backdrop-blur-lg">
          <div className="mx-auto flex w-full max-w-screen-2xl items-center justify-between px-4 py-3 sm:px-6 lg:px-10">
            <Link href="/" className="text-sm font-semibold tracking-[0.22em] text-white uppercase">
              SoleSpace
            </Link>

            <nav className="hidden items-center gap-1 md:flex">
              {topMenuItems.map((item) => (
                <button
                  key={item.id}
                  type="button"
                  onClick={() => scrollToSection(item.id)}
                  className={`rounded-full px-4 py-2 text-xs font-semibold uppercase tracking-[0.18em] transition-all ${
                    activeSection === item.id
                      ? 'bg-[#f59e0b] text-[#1f2937]'
                      : 'text-white/85 hover:bg-white/15 hover:text-white'
                  }`}
                >
                  {item.label}
                </button>
              ))}
            </nav>

            <div className="flex items-center gap-2">
              <Link
                href="/products"
                className="rounded-full border border-white/40 px-3 py-2 text-[11px] font-semibold uppercase tracking-[0.14em] text-white transition hover:bg-white/10"
              >
                Shop
              </Link>
              <Link
                href="/repair-services"
                className="rounded-full bg-[#f59e0b] px-3 py-2 text-[11px] font-semibold uppercase tracking-[0.14em] text-[#111827] transition hover:bg-[#fbbf24]"
              >
                Repair
              </Link>
            </div>
          </div>
        </header>

        <main className="lg:snap-y lg:snap-mandatory">
          <section id="hero" className="relative flex min-h-screen items-center overflow-hidden px-4 pb-12 pt-28 sm:px-6 lg:snap-start lg:px-10">
            <div
              aria-hidden="true"
              className="absolute inset-0 z-0"
              style={{
                transform: reducedMotionRef.current ? undefined : `translateY(${heroParallaxY}px)`,
                willChange: reducedMotionRef.current ? undefined : 'transform',
              }}
            >
              <img src="/images/shop/p2.jpg" alt="" className="h-[115%] w-full object-cover object-center" />
              <div className="absolute inset-0 bg-gradient-to-b from-[#1118279c] via-[#0f172ae3] to-[#020617f5]" />
            </div>

            <div
              aria-hidden="true"
              className="absolute -left-24 top-16 h-72 w-72 rounded-full bg-[#f59e0b59] blur-3xl"
              style={{
                transform: reducedMotionRef.current ? undefined : `translateY(${-accentParallaxY}px)`,
                willChange: reducedMotionRef.current ? undefined : 'transform',
              }}
            />

            <div className="relative z-10 mx-auto w-full max-w-screen-xl">
              <p className="mb-4 text-xs font-semibold uppercase tracking-[0.2em] text-[#fcd34d]">SPA Pilot Experience</p>
              <h1 className="max-w-4xl text-4xl font-bold leading-[0.95] tracking-tight text-white sm:text-6xl lg:text-7xl">
                Scroll Straight Through Products, Repair, and Services
              </h1>
              <p className="mt-6 max-w-2xl text-base leading-relaxed text-white/85 sm:text-lg">
                Isang tuloy-tuloy na one-page experience ito sa user side. Habang nagso-scroll ka pababa, sunod-sunod mong
                makikita ang product picks, repair partners, at service highlights.
              </p>
              <div className="mt-8 flex flex-wrap gap-3">
                <button
                  type="button"
                  onClick={() => scrollToSection('products')}
                  className="rounded-full bg-[#f59e0b] px-6 py-3 text-xs font-semibold uppercase tracking-[0.16em] text-[#111827] transition hover:bg-[#fbbf24]"
                >
                  Start Scrolling
                </button>
                <Link
                  href="/products"
                  className="rounded-full border border-white/55 px-6 py-3 text-xs font-semibold uppercase tracking-[0.16em] text-white transition hover:bg-white/10"
                >
                  Open Full Catalog
                </Link>
              </div>
            </div>
          </section>

          <section id="products" className="relative bg-[#f8fafc] px-4 py-16 sm:px-6 lg:snap-start lg:px-10 lg:py-24">
            <div className="mx-auto w-full max-w-screen-xl">
              <div className="mb-10 flex items-end justify-between gap-4">
                <div>
                  <p className="text-xs font-semibold uppercase tracking-[0.2em] text-[#64748b]">Products</p>
                  <h2 className="mt-2 text-3xl font-bold tracking-tight text-[#0f172a] sm:text-4xl">Featured Kicks</h2>
                </div>
                <Link href="/products" className="text-sm font-semibold text-[#0f172a] underline-offset-4 hover:underline">
                  View all products
                </Link>
              </div>

              <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                {products.length > 0 ? (
                  products.map((product) => (
                    <Link
                      key={product.id}
                      href={`/products/${product.slug}`}
                      className="group overflow-hidden rounded-3xl border border-[#dbe3ea] bg-white shadow-[0_18px_42px_-30px_rgba(15,23,42,0.6)] transition hover:-translate-y-1 hover:shadow-[0_30px_52px_-30px_rgba(15,23,42,0.62)]"
                    >
                      <div className="aspect-[4/3] w-full overflow-hidden bg-[#e2e8f0]">
                        <img
                          src={productImage(product)}
                          alt={product.name}
                          className="h-full w-full object-cover transition duration-500 group-hover:scale-105"
                          loading="lazy"
                          decoding="async"
                        />
                      </div>
                      <div className="p-5">
                        <p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-[#64748b]">
                          {product.shop_owner?.business_name || 'SoleSpace Partner'}
                        </p>
                        <h3 className="mt-2 line-clamp-2 text-lg font-semibold text-[#0f172a]">{product.name}</h3>
                        <p className="mt-3 text-xl font-bold text-[#0f172a]">P {Number(product.price || 0).toLocaleString()}</p>
                      </div>
                    </Link>
                  ))
                ) : (
                  <div className="rounded-3xl border border-dashed border-[#cbd5e1] bg-white p-8 text-sm text-[#475569] sm:col-span-2 lg:col-span-3">
                    No featured products yet.
                  </div>
                )}
              </div>
            </div>
          </section>

          <section id="repair" className="relative bg-[#0f172a] px-4 py-16 sm:px-6 lg:snap-start lg:px-10 lg:py-24">
            <div className="mx-auto w-full max-w-screen-xl">
              <div className="mb-10 flex items-end justify-between gap-4">
                <div>
                  <p className="text-xs font-semibold uppercase tracking-[0.2em] text-[#93c5fd]">Repair</p>
                  <h2 className="mt-2 text-3xl font-bold tracking-tight text-white sm:text-4xl">Trusted Repair Partners</h2>
                </div>
                <Link href="/repair-services" className="text-sm font-semibold text-white underline-offset-4 hover:underline">
                  Explore repair services
                </Link>
              </div>

              <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                {shops.length > 0 ? (
                  shops.map((shop) => (
                    <Link
                      key={shop.id}
                      href={`/repair-shop/${shop.id}`}
                      className="group overflow-hidden rounded-3xl border border-white/15 bg-white/10 shadow-[0_18px_42px_-30px_rgba(2,6,23,0.9)] backdrop-blur transition hover:-translate-y-1 hover:bg-white/15"
                    >
                      <div className="aspect-[4/3] w-full overflow-hidden bg-[#0b1220]">
                        <img
                          src={shop.image || '/images/shop/shop.jpg'}
                          alt={shop.shopName}
                          className="h-full w-full object-cover transition duration-500 group-hover:scale-105"
                          loading="lazy"
                          decoding="async"
                        />
                      </div>
                      <div className="p-5">
                        <h3 className="text-lg font-semibold text-white">{shop.shopName}</h3>
                        <p className="mt-2 text-sm text-white/70">{shop.shopLocation}</p>
                        {shop.bio ? <p className="mt-3 line-clamp-2 text-sm text-white/80">{shop.bio}</p> : null}
                      </div>
                    </Link>
                  ))
                ) : (
                  <div className="rounded-3xl border border-dashed border-white/25 bg-white/5 p-8 text-sm text-white/75 sm:col-span-2 lg:col-span-3">
                    No repair shops available yet.
                  </div>
                )}
              </div>
            </div>
          </section>

          <section id="services" className="relative overflow-hidden bg-[#fff8ed] px-4 py-16 sm:px-6 lg:snap-start lg:px-10 lg:py-24">
            <div
              aria-hidden="true"
              className="absolute -right-20 -top-14 h-64 w-64 rounded-full bg-[#f59e0b33] blur-3xl"
              style={{
                transform: reducedMotionRef.current ? undefined : `translateY(${accentParallaxY}px)`,
                willChange: reducedMotionRef.current ? undefined : 'transform',
              }}
            />

            <div className="relative mx-auto w-full max-w-screen-xl">
              <p className="text-xs font-semibold uppercase tracking-[0.2em] text-[#b45309]">Services</p>
              <h2 className="mt-2 text-3xl font-bold tracking-tight text-[#7c2d12] sm:text-4xl">Premium Service Highlights</h2>
              <p className="mt-4 max-w-2xl text-sm leading-relaxed text-[#7c2d12c7] sm:text-base">
                Dito mo makikita ang premium service direction ng platform mo, designed para sa growth ng shop owners at mas
                magandang user discovery flow.
              </p>

              <div className="mt-10 grid gap-4 md:grid-cols-3">
                {serviceHighlights.length > 0 ? (
                  serviceHighlights.map((item) => (
                    <article key={item.title} className="rounded-3xl border border-[#fed7aa] bg-white p-6 shadow-[0_18px_42px_-32px_rgba(124,45,18,0.45)]">
                      <h3 className="text-lg font-semibold text-[#7c2d12]">{item.title}</h3>
                      <p className="mt-3 text-sm leading-relaxed text-[#9a3412]">{item.description}</p>
                    </article>
                  ))
                ) : (
                  <div className="rounded-3xl border border-dashed border-[#fdba74] bg-white p-8 text-sm text-[#9a3412] md:col-span-3">
                    No service highlights yet.
                  </div>
                )}
              </div>

              <div className="mt-10 flex flex-wrap gap-3">
                <Link
                  href="/services"
                  className="rounded-full bg-[#7c2d12] px-6 py-3 text-xs font-semibold uppercase tracking-[0.16em] text-white transition hover:bg-[#9a3412]"
                >
                  Open Full Services Page
                </Link>
                <button
                  type="button"
                  onClick={() => scrollToSection('hero')}
                  className="rounded-full border border-[#7c2d12] px-6 py-3 text-xs font-semibold uppercase tracking-[0.16em] text-[#7c2d12] transition hover:bg-[#7c2d120d]"
                >
                  Back To Top
                </button>
              </div>
            </div>
          </section>
        </main>
      </div>
    </>
  );
};

export default SpaLanding;