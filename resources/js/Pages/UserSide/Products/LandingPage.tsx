import React, { useEffect, useRef, useState } from 'react';
import { Link, Head } from '@inertiajs/react';
import Navigation from '../Shared/Navigation';

interface Product {
  id: number;
  name: string;
  slug: string;
  description: string;
  price: number;
  main_image: string;
  stock_quantity: number;
}

interface Props {
  products: Product[];
}

const categoryCards = [
  {
    title: 'Shoes',
    routeName: 'products',
    image: '/images/shop/p1.jpg',
    alt: 'SoleSpace footwear collection',
  },
  {
    title: 'Repair',
    routeName: 'repair',
    image: '/images/shop/p2.jpg',
    alt: 'SoleSpace shoe repair service',
  },
  {
    title: 'Services',
    routeName: 'services',
    image: '/images/shop/p3.jpg',
    alt: 'SoleSpace footwear care services',
  },
] as const;

const LandingPage: React.FC<Props> = ({ products = [] }) => {
  const heroSlides = [
    {
      src: '/images/shop/p1.jpg',
      imageClass: 'object-[72%_48%] sm:object-[center_48%]',
      overlayClass: 'bg-gradient-to-b from-black/35 via-black/45 to-black/60 sm:bg-black/45',
    },
    {
      src: '/images/shop/p2.jpg',
      imageClass: 'object-[60%_38%] sm:object-[center_38%]',
      overlayClass: 'bg-gradient-to-b from-black/35 via-black/45 to-black/60 sm:bg-black/45',
    },
    {
      src: '/images/shop/p3.jpg',
      imageClass: 'object-[66%_65%] sm:object-[center_65%]',
      overlayClass: 'bg-gradient-to-b from-black/35 via-black/45 to-black/60 sm:bg-black/45',
    },
    {
      src: '/images/shop/p4.jpg',
      imageClass: 'object-[66%_51%] sm:object-[center_51%]',
      overlayClass: 'bg-gradient-to-b from-black/35 via-black/45 to-black/60 sm:bg-black/45',
    },

  ];

  const [activeHeroSlide, setActiveHeroSlide] = useState(0);
  const revealRootRef = useRef<HTMLDivElement | null>(null);
  const prefersReducedMotionRef = useRef(false);

  useEffect(() => {
    prefersReducedMotionRef.current = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  }, []);

  useEffect(() => {
    const heroTimer = window.setInterval(() => {
      setActiveHeroSlide((prev) => (prev + 1) % heroSlides.length);
    }, 4500);

    return () => window.clearInterval(heroTimer);
  }, [heroSlides.length]);

  useEffect(() => {
    const root = revealRootRef.current;
    if (!root) {
      return;
    }

    const revealElements = Array.from(root.querySelectorAll<HTMLElement>('[data-scroll-reveal]'));

    revealElements.forEach((element) => {
      const delay = Number(element.dataset.scrollDelay ?? 0);
      if (delay > 0) {
        element.style.transitionDelay = `${delay}ms`;
      }
    });

    if (prefersReducedMotionRef.current) {
      revealElements.forEach((element) => element.classList.add('is-visible'));
      return;
    }

    const observer = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (!entry.isIntersecting) {
            return;
          }

          entry.target.classList.add('is-visible');
          observer.unobserve(entry.target);
        });
      },
      {
        threshold: 0.16,
        rootMargin: '0px 0px -10% 0px',
      }
    );

    revealElements.forEach((element) => observer.observe(element));

    return () => observer.disconnect();
  }, []);

  const buttonBaseClass =
    'group inline-flex w-full max-w-full items-center justify-center gap-3 rounded-full px-5 py-3 text-[11px] font-semibold uppercase tracking-[0.14em] transition-all duration-300 focus-visible:outline-none focus-visible:ring-2 sm:w-auto sm:px-10 sm:py-4 sm:text-sm sm:tracking-[0.18em]';
  const buttonLightClass =
    'landing-primary-cta border border-white bg-[#ffffff] text-[#0f172a] backdrop-blur-md shadow-[0_18px_35px_-18px_rgba(0,0,0,0.55)] hover:-translate-y-0.5 hover:border-[#f8fafc] hover:bg-[#f8fafc] hover:text-[#0f172a] hover:shadow-[0_24px_38px_-18px_rgba(0,0,0,0.55)] dark:border-[#334155] dark:bg-[#16233b] dark:text-white dark:hover:border-[#475569] dark:hover:bg-[#213257] dark:hover:text-white focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-black';
  const buttonDarkClass =
    'border border-white/55 bg-black/45 text-white backdrop-blur-sm shadow-[0_14px_28px_-18px_rgba(0,0,0,0.95)] hover:-translate-y-0.5 hover:border-[#16233b] hover:bg-[#16233b] hover:text-white hover:shadow-[0_22px_40px_-18px_rgba(0,0,0,0.95)] focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-black';
  const sectionContainerClass = 'mx-auto w-full max-w-screen-2xl px-4 sm:px-6 lg:px-12';
  const heroContainerClass = 'w-full px-4 sm:px-6 lg:px-10';

  return (
    <>
      <Head title="SoleSpace - Premium Footwear & Expert Repairs" />
      <div ref={revealRootRef} className="min-h-screen overflow-x-hidden bg-white font-outfit antialiased">
        <Navigation mobileMenuTriggerIcon="hamburger" landingSidebar />

       <main className="relative z-10">
       <div>
      {/* Hero Section - Full-bleed Background Carousel */}
      <section className="relative flex min-h-[84svh] w-full items-center overflow-hidden sm:min-h-svh">
        <div className="absolute inset-0 z-0">
          {heroSlides.map((slide, index) => (
            <img
              key={slide.src}
              src={slide.src}
              alt={`Shop background ${index + 1}`}
              className={`absolute inset-0 h-full w-full object-cover transition-opacity duration-1000 ${slide.imageClass} ${
                index === activeHeroSlide ? 'opacity-100' : 'opacity-0'
              }`}
              loading={index === 0 ? 'eager' : 'lazy'}
              decoding="async"
            />
          ))}
          <div className={`absolute inset-0 ${heroSlides[activeHeroSlide]?.overlayClass ?? 'bg-black/45'}`} />
        </div>

        <div className={`${heroContainerClass} flex min-h-[84svh] items-center pb-12 pt-20 sm:min-h-svh sm:py-24 lg:py-32`}>
          <div className="relative z-10 w-full max-w-[24rem] text-left sm:max-w-152 lg:max-w-4xl">
            <h1 className="hero-headline mb-4 text-[2.35rem] font-bold leading-[0.9] tracking-tight text-white/90 xsm:text-[2.75rem] sm:mb-8 sm:text-[4.4rem] md:text-7xl lg:text-8xl xl:text-9xl">
              <span className="hero-headline-line hero-line-1 landing-hero-motion">ELEVATE YOUR</span>
              <span className="hero-headline-line hero-line-2 landing-hero-motion">SIGNATURE</span>
              <span className="hero-headline-line hero-line-3 landing-hero-motion">STYLE</span>
            </h1>
            <p className="hero-description landing-hero-motion mb-7 max-w-xl text-base font-light leading-relaxed text-white/90 sm:mb-12 sm:max-w-2xl sm:text-lg md:text-xl lg:text-2xl">
              Discover refined footwear and atelier-grade repair services, curated for people who wear confidence with every step.
            </p>
            <div className="landing-hero-motion hero-actions flex w-full max-w-sm flex-col gap-3 sm:max-w-none sm:flex-row sm:gap-4">
              <Link
                href={route("products")}
                className={`${buttonBaseClass} ${buttonLightClass}`}
              >
                Shop Collection
                <svg className="h-5 w-5 transition-transform duration-300 group-hover:translate-x-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 8l4 4m0 0l-4 4m4-4H3" />
                </svg>
              </Link>
              <Link
                href={route("repair")}
                className={`${buttonBaseClass} ${buttonDarkClass}`}
              >
                Repair Services
                <svg className="h-5 w-5 transition-transform duration-300 group-hover:rotate-45" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
              </Link>
            </div>

            <div className="mt-6 flex items-center justify-start gap-2 sm:mt-8">
              {heroSlides.map((_, index) => (
                <button
                  key={`hero-dot-${index}`}
                  type="button"
                  onClick={() => setActiveHeroSlide(index)}
                  className={`h-2.5 rounded-full transition-all ${index === activeHeroSlide ? 'w-8 bg-white' : 'w-2.5 bg-white/50 hover:bg-white/80'}`}
                  aria-label={`Go to slide ${index + 1}`}
                />
              ))}
            </div>
          </div>
        </div>
      </section>
      </div>

      <section id="landing-new-releases" data-scroll-reveal className="scroll-reveal w-full bg-white py-16 text-black sm:py-24 lg:py-32">
        <div className={sectionContainerClass}>
          <div data-scroll-reveal className="scroll-reveal mb-10 flex flex-col gap-6 sm:mb-16 sm:flex-row sm:items-end sm:justify-between">
            <h2 className="text-5xl font-normal tracking-[-0.06em] sm:text-7xl lg:text-8xl">New releases</h2>
            <div className="flex flex-wrap gap-x-7 gap-y-3 text-sm font-semibold sm:gap-x-10 sm:text-base">
              <Link href={route("products")} className="group inline-flex items-center gap-4 transition-opacity hover:opacity-60">
                Men's products
                <span aria-hidden="true" className="text-2xl font-normal leading-none transition-transform duration-300 group-hover:translate-x-1">→</span>
              </Link>
              <Link href={route("products")} className="group inline-flex items-center gap-4 transition-opacity hover:opacity-60">
                Women's products
                <span aria-hidden="true" className="text-2xl font-normal leading-none transition-transform duration-300 group-hover:translate-x-1">→</span>
              </Link>
            </div>
          </div>

          <div className="flex snap-x snap-mandatory gap-4 overflow-x-auto pb-3 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden md:grid md:grid-cols-3 md:gap-7 md:overflow-visible md:pb-0">
            {products.length > 0 ? (
              products.map((product, index) => (
                <Link
                  key={product.id}
                  href={route('products.show', product.slug)}
                  data-scroll-reveal
                  data-scroll-delay={Math.min(index * 90, 270)}
                  className="scroll-reveal group min-w-[84%] snap-start sm:min-w-[58%] md:min-w-0"
                >
                  <div className="relative aspect-[4/5] overflow-hidden bg-[#f3f3f1]">
                    <img
                      src={product.main_image || '/images/product/product-01.jpg'}
                      alt={product.name}
                      className="h-full w-full object-cover transition-transform duration-700 ease-out group-hover:scale-105"
                      loading="lazy"
                      decoding="async"
                      sizes="(max-width: 767px) 84vw, (max-width: 1279px) 33vw, 30vw"
                    />
                    {index === 0 && (
                      <span className="absolute right-4 top-4 bg-white px-2 py-1 text-[10px] font-semibold uppercase tracking-[0.12em] text-black sm:right-5 sm:top-5">
                        New
                      </span>
                    )}
                    {product.stock_quantity === 0 && (
                      <div className="absolute inset-0 flex items-center justify-center bg-black/65">
                        <span className="text-sm font-semibold uppercase tracking-[0.12em] text-white sm:text-base">Out of stock</span>
                      </div>
                    )}
                  </div>
                  <div className="flex items-start justify-between gap-4 border-b border-black/15 py-4 sm:py-5">
                    <div className="min-w-0">
                      <h3 className="truncate text-sm font-semibold sm:text-base">{product.name}</h3>
                      <p className="mt-1 line-clamp-1 text-xs text-black/55 sm:text-sm">
                        {product.description || 'Premium footwear for every step.'}
                      </p>
                    </div>
                    <span className="shrink-0 text-sm font-medium sm:text-base">₱{product.price.toLocaleString()}</span>
                  </div>
                </Link>
              ))
            ) : (
              <div className="w-full py-12 text-center md:col-span-full">
                <p className="text-lg text-black/50">No products available at the moment.</p>
              </div>
            )}
          </div>

          <div data-scroll-reveal className="scroll-reveal mt-10 sm:mt-14">
            <Link href={route("products")} className="group inline-flex items-center gap-4 border-b border-black pb-2 text-sm font-semibold uppercase tracking-[0.14em] transition-opacity hover:opacity-60">
              View all products
              <span aria-hidden="true" className="text-xl font-normal leading-none transition-transform duration-300 group-hover:translate-x-1">→</span>
            </Link>
          </div>
        </div>
      </section>

      <section id="landing-categories" data-scroll-reveal className="scroll-reveal w-full bg-white pb-16 text-black sm:pb-24 lg:pb-32">
        <div className={sectionContainerClass}>
          <div data-scroll-reveal className="scroll-reveal mb-10 flex flex-col gap-5 sm:mb-16 sm:flex-row sm:items-end sm:justify-between">
            <h2 className="text-5xl font-normal tracking-[-0.06em] sm:text-7xl lg:text-8xl">Shop by category</h2>
            <Link href={route("products")} className="group inline-flex items-center gap-4 text-sm font-semibold transition-opacity hover:opacity-60 sm:text-base">
              Explore the collection
              <span aria-hidden="true" className="text-2xl font-normal leading-none transition-transform duration-300 group-hover:translate-x-1">→</span>
            </Link>
          </div>

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {categoryCards.map((card, index) => (
              <Link
                key={card.title}
                href={route(card.routeName)}
                data-scroll-reveal
                data-scroll-delay={Math.min(index * 100, 200)}
                className="scroll-reveal group relative min-h-[30rem] overflow-hidden bg-[#e7e7e3] sm:min-h-[38rem]"
              >
                <img
                  src={card.image}
                  alt={card.alt}
                  className="absolute inset-0 h-full w-full object-cover transition-transform duration-700 ease-out group-hover:scale-105"
                  loading="lazy"
                  decoding="async"
                />
                <div className="absolute inset-0 bg-gradient-to-t from-black/70 via-black/10 to-transparent" />
                <div className="absolute inset-x-6 bottom-6 flex items-center justify-between gap-4 text-white sm:inset-x-8 sm:bottom-8">
                  <h3 className="text-xl font-semibold sm:text-2xl">{card.title}</h3>
                  <span aria-hidden="true" className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-white/20 text-2xl font-light backdrop-blur-sm transition-transform duration-300 group-hover:translate-x-1">→</span>
                </div>
              </Link>
            ))}
          </div>
        </div>
      </section>


      <section id="landing-story" data-scroll-reveal className="scroll-reveal w-full bg-black text-white">
        <div className="relative min-h-[34rem] overflow-hidden sm:min-h-[44rem]">
          <img src="/images/shop/p4.jpg" alt="SoleSpace craftsmanship in motion" className="absolute inset-0 h-full w-full object-cover opacity-75" loading="lazy" decoding="async" />
          <div className="absolute inset-0 bg-gradient-to-r from-black/80 via-black/35 to-black/25" />
          <div className={`${sectionContainerClass} relative flex min-h-[34rem] items-end pb-10 pt-24 sm:min-h-[44rem] sm:pb-16 lg:pb-20`}>
            <div data-scroll-reveal className="scroll-reveal scroll-reveal--side max-w-3xl">
              <p className="mb-5 text-xs font-semibold uppercase tracking-[0.2em] text-white/70">KEEP EVERY STEP GOING</p>
              <h2 className="max-w-3xl text-4xl font-normal leading-[0.95] tracking-[-0.05em] sm:text-6xl lg:text-8xl">Find a pair worth keeping.</h2>
              <Link href={route("products")} className="group mt-9 inline-flex items-center gap-4 text-sm font-semibold uppercase tracking-[0.14em] transition-opacity hover:opacity-60 sm:mt-12">
                Discover SoleSpace
                <span aria-hidden="true" className="text-2xl font-normal leading-none transition-transform duration-300 group-hover:translate-x-1">→</span>
              </Link>
            </div>
          </div>
        </div>
      </section>

      <section id="landing-benefits" data-scroll-reveal className="scroll-reveal w-full bg-white py-20 text-black sm:py-28 lg:py-36">
        <div className={sectionContainerClass}>
          <div className="grid grid-cols-1 gap-14 text-center sm:grid-cols-3 sm:gap-8 lg:gap-20">
            <div data-scroll-reveal data-scroll-delay="0" className="scroll-reveal">
              <div className="mx-auto mb-7 flex h-12 w-12 items-center justify-center sm:mb-9 sm:h-16 sm:w-16">
                <svg className="h-10 w-10" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.25} d="M3 7h11v10H3zM14 10h3l4 4v3h-7zM6 17a2 2 0 104 0M17 17a2 2 0 104 0" />
                </svg>
              </div>
              <h3 className="mb-3 text-xl font-semibold tracking-tight sm:text-2xl">Curated footwear</h3>
              <p className="mx-auto max-w-xs text-base leading-relaxed text-black/60">Pieces chosen for comfort, character, and the way you move.</p>
            </div>
            <div data-scroll-reveal data-scroll-delay="100" className="scroll-reveal">
              <div className="mx-auto mb-7 flex h-12 w-12 items-center justify-center sm:mb-9 sm:h-16 sm:w-16">
                <svg className="h-10 w-10" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.25} d="M4 5h16v14H4zM8 9h8M8 13h5M8 17h3" />
                </svg>
              </div>
              <h3 className="mb-3 text-xl font-semibold tracking-tight sm:text-2xl">Expert repairs</h3>
              <p className="mx-auto max-w-xs text-base leading-relaxed text-black/60">Thoughtful care that helps your favorite pairs go further.</p>
            </div>
            <div data-scroll-reveal data-scroll-delay="200" className="scroll-reveal">
              <div className="mx-auto mb-7 flex h-12 w-12 items-center justify-center sm:mb-9 sm:h-16 sm:w-16">
                <svg className="h-10 w-10" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.25} d="M12 3l7 4v5c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V7zM9 12l2 2 4-4" />
                </svg>
              </div>
              <h3 className="mb-3 text-xl font-semibold tracking-tight sm:text-2xl">One space for every step</h3>
              <p className="mx-auto max-w-xs text-base leading-relaxed text-black/60">Shop, repair, and care for footwear in one considered place.</p>
            </div>
          </div>
        </div>
      </section>

      <section id="landing-community" data-scroll-reveal className="scroll-reveal w-full bg-black text-white">
        <div className={`${sectionContainerClass} grid min-h-[34rem] grid-cols-1 gap-10 py-12 sm:min-h-[42rem] sm:py-16 lg:grid-cols-[minmax(0,1.25fr)_minmax(18rem,0.75fr)] lg:gap-16 lg:py-20`}>
          <div className="flex flex-col justify-between">
            <div data-scroll-reveal className="scroll-reveal">
              <p className="mb-8 text-xs font-semibold uppercase tracking-[0.2em] text-white/65">JOIN THE SOLESPACE COMMUNITY</p>
              <h2 className="max-w-5xl text-[3.4rem] font-normal leading-[0.82] tracking-[-0.07em] sm:text-7xl lg:text-[8.5rem]">STEP INTO SOLESPACE</h2>
            </div>
            <div data-scroll-reveal data-scroll-delay="120" className="scroll-reveal mt-12 flex flex-col gap-5 sm:flex-row sm:items-center sm:gap-8">
              <Link href={route("products")} className="group inline-flex items-center gap-4 text-sm font-semibold uppercase tracking-[0.14em] transition-opacity hover:opacity-60">
                Shop products
                <span aria-hidden="true" className="text-2xl font-normal leading-none transition-transform duration-300 group-hover:translate-x-1">→</span>
              </Link>
              <Link href={route("repair")} className="group inline-flex items-center gap-4 text-sm font-semibold uppercase tracking-[0.14em] transition-opacity hover:opacity-60">
                Book a repair
                <span aria-hidden="true" className="text-2xl font-normal leading-none transition-transform duration-300 group-hover:translate-x-1">→</span>
              </Link>
            </div>
          </div>
          <div data-scroll-reveal className="scroll-reveal scroll-reveal--scale relative min-h-[18rem] overflow-hidden bg-[#1b1b1b] lg:min-h-0">
            <img src="/images/shop/p2.jpg" alt="SoleSpace community on the move" className="absolute inset-0 h-full w-full object-cover opacity-75 transition-transform duration-700 ease-out hover:scale-105" loading="lazy" decoding="async" />
            <div className="absolute inset-0 bg-black/20" />
          </div>
        </div>
       </section>

       </main>
      </div>

       <footer
         id="landing-footer"
         className="landing-footer sticky bottom-0 z-0 w-full min-h-[30rem] overflow-hidden bg-white text-black sm:min-h-[34rem]"
       >
         <div className={`${sectionContainerClass} relative z-10 pt-8 sm:pt-10`}>
           <div className="hidden grid-cols-4 gap-8 lg:grid">
             <div>
               <Link href={route("landing")} className="text-sm font-semibold uppercase tracking-[0.08em]">
                 SOLESPACE
               </Link>
             </div>
             <div>
               <h2 className="mb-4 text-xs font-semibold uppercase tracking-[0.14em]">Explore</h2>
               <ul className="space-y-2 text-xs font-medium uppercase tracking-[0.08em]">
                 <li><a href="#landing-new-releases" className="footer-link">New releases</a></li>
                 <li><a href="#landing-categories" className="footer-link">Shop by category</a></li>
                 <li><Link href={route("repair")} className="footer-link">Book a repair</Link></li>
               </ul>
             </div>
             <div>
               <h2 className="mb-4 text-xs font-semibold uppercase tracking-[0.14em]">Support</h2>
               <ul className="space-y-2 text-xs font-medium uppercase tracking-[0.08em]">
                 <li><a href="#landing-story" className="footer-link">Our story</a></li>
                 <li><Link href={route("services")} className="footer-link">Care services</Link></li>
                 <li><Link href={route("services")} className="footer-link">Contact support</Link></li>
               </ul>
             </div>
             <div>
               <h2 className="mb-4 text-xs font-semibold uppercase tracking-[0.14em]">Community</h2>
               <ul className="space-y-2 text-xs font-medium uppercase tracking-[0.08em]">
                 <li><a href="#landing-community" className="footer-link">Join the community</a></li>
                 <li><Link href={route("products")} className="footer-link">Shop SoleSpace</Link></li>
                 <li><Link href={route("services")} className="footer-link">Step in with us</Link></li>
               </ul>
             </div>
           </div>

           <div className="lg:hidden">
             <p className="mb-7 text-sm font-semibold uppercase tracking-[0.08em]">SOLESPACE</p>
             <details className="footer-disclosure">
               <summary>Explore <span aria-hidden="true">+</span></summary>
               <div className="footer-disclosure__links">
                 <a href="#landing-new-releases" className="footer-link">New releases</a>
                 <a href="#landing-categories" className="footer-link">Shop by category</a>
                 <Link href={route("repair")} className="footer-link">Book a repair</Link>
               </div>
             </details>
             <details className="footer-disclosure">
               <summary>Support <span aria-hidden="true">+</span></summary>
               <div className="footer-disclosure__links">
                 <a href="#landing-story" className="footer-link">Our story</a>
                 <Link href={route("services")} className="footer-link">Care services</Link>
                 <Link href={route("services")} className="footer-link">Contact support</Link>
               </div>
             </details>
             <details className="footer-disclosure">
               <summary>Community <span aria-hidden="true">+</span></summary>
               <div className="footer-disclosure__links">
                 <a href="#landing-community" className="footer-link">Join the community</a>
                 <Link href={route("products")} className="footer-link">Shop SoleSpace</Link>
                 <Link href={route("services")} className="footer-link">Step in with us</Link>
               </div>
             </details>
           </div>

           <div className="mt-12 grid grid-cols-1 gap-4 border-t border-black/15 py-5 text-[11px] font-medium uppercase tracking-[0.08em] sm:grid-cols-3 sm:gap-8">
             <p>Copyright &copy; 2024 SoleSpace</p>
             <p>Shipping to <span aria-hidden="true">&rsaquo;</span> Philippines</p>
             <p>Language <span aria-hidden="true">&rsaquo;</span> English</p>
           </div>
         </div>

         <div aria-hidden="true" className="footer-wordmark">
           SOLESPACE
         </div>
       </footer>
        <style>{`
          .scroll-reveal {
            opacity: 0;
            transform: translate3d(0, 40px, 0);
            transition: opacity 700ms ease, transform 800ms cubic-bezier(0.22, 1, 0.36, 1);
          }

          .scroll-reveal.is-visible {
            opacity: 1;
            transform: translate3d(0, 0, 0);
          }

          .scroll-reveal--side {
            transform: translate3d(32px, 0, 0);
          }

          .scroll-reveal--scale {
            transform: scale(1.04);
          }

           .scroll-reveal--scale.is-visible {
             transform: scale(1);
           }

           .footer-link {
             display: inline-block;
             transition: opacity 180ms ease, transform 180ms ease;
           }

           .footer-link:hover {
             opacity: 0.55;
             transform: translateX(3px);
           }

           .footer-link:focus-visible,
           .footer-disclosure summary:focus-visible {
             outline: 2px solid currentColor;
             outline-offset: 4px;
           }

           .footer-disclosure {
             border-top: 1px solid rgb(0 0 0 / 15%);
           }

           .footer-disclosure:last-of-type {
             border-bottom: 1px solid rgb(0 0 0 / 15%);
           }

           .footer-disclosure summary {
             display: flex;
             min-height: 44px;
             cursor: pointer;
             list-style: none;
             align-items: center;
             justify-content: space-between;
             padding: 14px 0;
             font-size: 0.75rem;
             font-weight: 600;
             letter-spacing: 0.12em;
             text-transform: uppercase;
           }

           .footer-disclosure summary::-webkit-details-marker {
             display: none;
           }

           .footer-disclosure[open] summary span {
             transform: rotate(45deg);
           }

           .footer-disclosure summary span {
             font-size: 1.25rem;
             font-weight: 400;
             line-height: 1;
             transition: transform 180ms ease;
           }

           .footer-disclosure__links {
             display: grid;
             gap: 10px;
             padding: 0 0 18px;
             font-size: 0.7rem;
             font-weight: 500;
             letter-spacing: 0.08em;
             text-transform: uppercase;
           }

           .footer-wordmark {
             position: relative;
             z-index: 0;
             width: max-content;
             min-width: 100%;
             padding: 2.2rem 0 0;
             overflow: hidden;
             white-space: nowrap;
             font-size: clamp(7rem, 25vw, 26rem);
             font-weight: 700;
             line-height: 0.68;
             letter-spacing: -0.105em;
           }

           @media (prefers-reduced-motion: reduce) {
             .footer-link,
             .footer-disclosure summary span {
               transition: none;
             }
           }

           .hero-headline-line {
            display: block;
            opacity: 0;
            transform: translate3d(0, 28px, 0);
            animation: hero-line-rise 600ms cubic-bezier(0.22, 1, 0.36, 1) forwards;
            will-change: transform, opacity;
          }

          .hero-line-1 {
            animation-delay: 0ms;
          }

          .hero-line-2 {
            animation-delay: 160ms;
          }

          .hero-line-3 {
            animation-delay: 320ms;
          }

          .hero-description {
            opacity: 0;
            transform: translate3d(0, 20px, 0);
            animation: hero-copy-fade 650ms cubic-bezier(0.22, 1, 0.36, 1) forwards;
            animation-delay: 520ms;
            will-change: transform, opacity;
          }

          .hero-actions {
            opacity: 0;
            transform: translate3d(0, 20px, 0);
            animation: hero-copy-fade 650ms cubic-bezier(0.22, 1, 0.36, 1) forwards;
            animation-delay: 700ms;
            will-change: transform, opacity;
          }

          html.solespace-first-load:not(.solespace-app-ready) .landing-hero-motion {
            animation-play-state: paused !important;
          }

          @keyframes hero-line-rise {
            from {
              opacity: 0;
              transform: translate3d(0, 28px, 0);
            }
            to {
              opacity: 1;
              transform: translate3d(0, 0, 0);
            }
          }

          @keyframes hero-copy-fade {
            from {
              opacity: 0;
              transform: translate3d(0, 20px, 0);
            }
            to {
              opacity: 1;
              transform: translate3d(0, 0, 0);
            }
          }

          @media (prefers-reduced-motion: reduce) {
            .scroll-reveal,
            .scroll-reveal.is-visible {
              transition: none !important;
              transform: none !important;
              opacity: 1 !important;
            }

            .landing-hero-motion,
            .landing-hero-motion.hero-headline-line,
            .landing-hero-motion.hero-description,
            .landing-hero-motion.hero-actions {
              animation: none !important;
              transform: none !important;
              opacity: 1 !important;
            }

          }
        `}</style>
    </>
  );
};

export default LandingPage;
